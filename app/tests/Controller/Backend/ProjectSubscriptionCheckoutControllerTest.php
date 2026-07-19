<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectSubscriptionCheckoutController;
use App\Controller\SecurityController;
use App\Entity\CommercialPlan;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Entity\User;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
use App\Repository\PlanRepository;
use App\Service\ActiveProjectService;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Service\SustainabilityPlanCompletionService;
use App\Service\SustainabilityPlanMeasureOrderer;
use App\Service\StripeInvoiceStorageService;
use App\Service\StripeProjectCheckoutService;
use App\Tests\Support\CommercialPlanTestHelpers;
use App\Tests\Support\Stripe\FakeStripeClient;
use App\Tests\Support\Stripe\FakeStripeCheckoutFacade;
use App\Tests\Support\Stripe\FakeStripeCheckoutSessions;
use App\Tests\Support\Stripe\FakeStripeInvoicesFacade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class ProjectSubscriptionCheckoutControllerTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testSuccessRouteReconcilesStripeSessionBeforeRedirecting(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(42, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_STANDARD, 'cs_success_1');
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_success_1',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 2900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_success_1'],
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $service = $this->createCheckoutService($client, $this->makeDefaultCommercialPlans(), true);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $controller = new ProjectSubscriptionCheckoutController($service, $activeProjectService);
        $controller->setContainer($container);
        $this->setAdminToken();

        $request = $this->createRequest(['session_id' => 'cs_success_1']);
        $response = $controller->success($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/plan/review', $response->getTargetUrl());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertSame('pi_success_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripePaymentIntentId());
    }

    public function testSuccessRouteRedirectsToFirstPendingMeasureWhenUpgradeBreaksCompleteness(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(52, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO, 'cs_success_pending');
        $measureBasic = $this->createMeasure(701, 5);
        $measurePending = $this->createMeasure(702, 3);
        $plan = $this->createPlan($project, $measureBasic, $measurePending);

        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_success_pending',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 2000,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_success_pending'],
            'metadata' => (object) [
                'project_id' => '52',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_PRO,
                'commercial_plan_code' => 'pro',
            ],
        ];
        $service = $this->createCheckoutService($client, $this->makeDefaultCommercialPlans(), true, [$measureBasic, $measurePending], $plan);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $controller = new ProjectSubscriptionCheckoutController($service, $activeProjectService);
        $controller->setContainer($container);
        $this->setAdminToken();

        $request = $this->createRequest(['session_id' => 'cs_success_pending']);
        $response = $controller->success($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/plan/measures', $response->getTargetUrl());
        self::assertStringContainsString('i=1', $response->getTargetUrl());
        self::assertStringNotContainsString('only_pending=1', $response->getTargetUrl());
    }

    public function testConfirmPendingRouteUsesStoredSessionId(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(43, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_STANDARD, 'cs_manual_1');
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_manual_1',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 2900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_manual_1'],
            'metadata' => (object) [
                'project_id' => '43',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $service = $this->createCheckoutService($client, $this->makeDefaultCommercialPlans(), true);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $controller = new ProjectSubscriptionCheckoutController($service, $activeProjectService);
        $controller->setContainer($container);
        $this->setAdminToken();

        $request = $this->createRequest(['from' => 'index']);
        $token = $container->get('security.csrf.token_manager')->getToken('project_subscription_confirm_pending_43_elaboration')->getValue();
        $request->request->set('_token', $token);
        $response = $controller->confirmPending($project, CommercialPhase::ELABORATION, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/project/43/billing/elaboration', $response->getTargetUrl());
        self::assertStringContainsString('from=index', $response->getTargetUrl());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertSame('pi_manual_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripePaymentIntentId());
    }

    public function testStripeCancelReturnDoesNotMutatePendingCheckout(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(44, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO, 'cs_cancel_1');
        $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->setPaidAmountCents(2900);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createCheckoutService($this->createFakeStripeClient(), $this->makeDefaultCommercialPlans());

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $controller = new ProjectSubscriptionCheckoutController($service, $activeProjectService);
        $controller->setContainer($container);
        $this->setAdminToken();

        $request = $this->createRequest(['session_id' => 'cs_cancel_1']);
        $response = $controller->cancelReturn($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/project/44/billing/elaboration', $response->getTargetUrl());
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertSame(ProjectSubscription::TIER_PRO, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
        self::assertSame('cs_cancel_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertSame(['backend.subscription.flash.cancel_return'], $request->getSession()->getFlashBag()->all()['info'] ?? []);
    }

    public function testCancelPendingClearsOnlyTheCurrentPhaseAndRequiresCsrf(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(46, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO, 'cs_cancel_pending');
        $implementationSubscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL)
            ->setStripeCheckoutSessionId('cs_impl_keep');
        $project->addSubscription($implementationSubscription);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createCheckoutService($this->createFakeStripeClient(), $this->makeDefaultCommercialPlans());

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $controller = new ProjectSubscriptionCheckoutController($service, $activeProjectService);
        $controller->setContainer($container);
        $this->setAdminToken();

        $request = $this->createRequest(['from' => 'project']);
        $request->request->set('_token', $container->get('security.csrf.token_manager')->getToken('project_subscription_cancel_pending_46_elaboration_pro')->getValue());
        $response = $controller->cancelPending($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO, $request, $entityManager);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/plan', $response->getTargetUrl());
        self::assertNull($project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
        self::assertNull($project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $implementationSubscription->getStatus());
        self::assertSame('cs_impl_keep', $implementationSubscription->getStripeCheckoutSessionId());
        self::assertSame(['backend.subscription.flash.cancelled'], $request->getSession()->getFlashBag()->all()['warning'] ?? []);
    }

    public function testCancelPendingRequiresProjectEditAccess(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(47, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO, 'cs_cancel_denied');
        $service = $this->createCheckoutService($this->createFakeStripeClient(), $this->makeDefaultCommercialPlans());

        $controller = new ProjectSubscriptionCheckoutController($service, $this->createMock(ActiveProjectService::class));
        $controller->setContainer($container);

        $user = (new User())
            ->setName('Viewer')
            ->setSurnames('Only')
            ->setEmail('viewer2@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_USER']);
        $this->setEntityId($user, 1001);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $request = $this->createRequest();
        $request->request->set('_token', 'invalid');

        $this->expectException(AccessDeniedException::class);

        $controller->cancelPending($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO, $request, $this->createMock(EntityManagerInterface::class));
    }

    public function testLoginRedirectsAuthenticatedUsersToBackend(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $user = (new User())
            ->setName('Admin')
            ->setSurnames('User')
            ->setEmail('admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN']);

        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $controller = new SecurityController();
        $controller->setContainer($container);

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $response = $controller->login($authenticationUtils);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend', $response->getTargetUrl());
    }

    public function testCheckoutRequiresProjectEditAccess(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(45, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_BASIC);
        $service = $this->createCheckoutService($this->createFakeStripeClient(), $this->makeDefaultCommercialPlans());

        $controller = new ProjectSubscriptionCheckoutController($service, $this->createMock(ActiveProjectService::class));
        $controller->setContainer($container);

        $user = (new User())
            ->setName('Viewer')
            ->setSurnames('Only')
            ->setEmail('viewer@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_USER']);
        $this->setEntityId($user, 1002);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $request = $this->createRequest();
        $request->request->set('_token', 'invalid');

        $this->expectException(AccessDeniedException::class);

        $controller->checkout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD, $request);
    }

    private function createCheckoutService(FakeStripeClient $client, array $plans, bool $expectFlush = false, ?array $measures = null, ?Plan $plan = null): StripeProjectCheckoutService
    {
        $gate = $this->makeProjectFeatureGate($plans);
        $planRepository = $this->createMock(CommercialPlanRepository::class);
        $projectPlanRepository = $this->createMock(PlanRepository::class);
        $projectPlanRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($plan): ?Plan {
                return ($plan instanceof Plan && ($criteria['project'] ?? null) === $plan->getProject()) ? $plan : null;
            }
        );
        $indexedPlans = [];
        foreach ($plans as $commercialPlan) {
            if ($commercialPlan instanceof CommercialPlan) {
                $indexedPlans[$commercialPlan->getPhase()->value . ':' . strtolower(trim($commercialPlan->getCode()))] = $commercialPlan;
            }
        }
        $planRepository->method('findActiveByPhaseAndCode')->willReturnCallback(
            static function (CommercialPhase $phase, string $code) use ($indexedPlans): ?CommercialPlan {
                return $indexedPlans[$phase->value . ':' . strtolower(trim($code))] ?? null;
            }
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        if ($expectFlush) {
            $entityManager->expects(self::once())->method('flush');
            $entityManager->expects(self::never())->method('persist');
        }

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/backend/project/42/subscription/elaboration/success/standard');

        $invoiceStorage ??= $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->method('upsertFromStripeCheckout')->willReturn(new ProjectBillingDocument());

        $completionService = new SustainabilityPlanCompletionService(
            $this->createMeasureRepositoryMock($measures ?? []),
            new PlanMeasureCatalogResolver($gate),
            new SustainabilityPlanMeasureOrderer(),
        );

        return new StripeProjectCheckoutService(
            $client,
            $gate,
            $planRepository,
            $projectPlanRepository,
            $entityManager,
            $invoiceStorage,
            $urlGenerator,
            'https://example.test/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/success/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
            'https://example.test/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/cancel/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
            $completionService,
        );
    }

    /**
     * @param array<int, Measure> $measures
     */
    private function createMeasureRepositoryMock(array $measures): MeasureRepository
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn($measures);

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        foreach (['join', 'leftJoin', 'addSelect', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);

        $repository = $this->createMock(MeasureRepository::class);
        $repository->method('createQueryBuilder')->willReturn($qb);

        return $repository;
    }

    private function createFakeStripeClient(): FakeStripeClient
    {
        $sessions = new FakeStripeCheckoutSessions();
        $checkout = new FakeStripeCheckoutFacade($sessions);
        $invoices = new FakeStripeInvoicesFacade();

        return new FakeStripeClient($checkout, $invoices);
    }

    private function createProject(int $id, string $status, string $tier, ?string $targetTier = null, ?string $sessionId = null): Project
    {
        $project = new Project();
        $this->setEntityId($project, $id);

        $subscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setTier($tier)
            ->setStatus($status)
            ->setSource(ProjectSubscription::SOURCE_MANUAL)
            ->setTargetTier($targetTier ?? $tier);

        if ($sessionId !== null) {
            $subscription->setStripeCheckoutSessionId($sessionId);
        }

        $project->addSubscription($subscription);

        return $project;
    }

    private function createPlan(Project $project, Measure ...$measures): Plan
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        foreach ($measures as $index => $measure) {
            $planMeasure = (new PlanMeasure())
                ->setMeasure($measure)
                ->setIsApplicable($index === 0 ? true : null)
                ->setIsCritical($index === 0 ? false : null)
                ->setWillImplement($index === 0 ? true : null)
                ->markAsManual();
            $plan->addPlanMeasure($planMeasure);
        }

        return $plan;
    }

    private function createMeasure(int $id, int $score): Measure
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore($score);
        $this->setEntityId($measure, $id);

        return $measure;
    }

    private function setAdminToken(): void
    {
        $user = (new User())
            ->setName('Admin')
            ->setSurnames('User')
            ->setEmail('admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN']);

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }
}
