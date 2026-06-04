<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectSubscriptionCheckoutController;
use App\Controller\SecurityController;
use App\Entity\CommercialPlan;
use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Repository\CommercialPlanRepository;
use App\Service\ActiveProjectService;
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
            'payment_status' => 'paid',
            'amount_total' => 9900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_success_1'],
            'metadata' => (object) [
                'project_id' => '42',
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
        $response = $controller->success($project, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/plan', $response->getTargetUrl());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscription()?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscription()?->getTier());
        self::assertSame('pi_success_1', $project->getSubscription()?->getStripePaymentIntentId());
    }

    public function testConfirmPendingRouteUsesStoredSessionId(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(43, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_STANDARD, 'cs_manual_1');
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_manual_1',
            'payment_status' => 'paid',
            'amount_total' => 9900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_manual_1'],
            'metadata' => (object) [
                'project_id' => '43',
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
        $token = $container->get('security.csrf.token_manager')->getToken('project_subscription_confirm_pending_43')->getValue();
        $request->request->set('_token', $token);
        $response = $controller->confirmPending($project, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/project/43/billing', $response->getTargetUrl());
        self::assertStringContainsString('from=index', $response->getTargetUrl());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscription()?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscription()?->getTier());
        self::assertSame('pi_manual_1', $project->getSubscription()?->getStripePaymentIntentId());
    }

    public function testCancelUpgradeKeepsTheActivePlanIntact(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $project = $this->createProject(44, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO, 'cs_cancel_1');
        $project->getSubscription()?->setPaidAmountCents(9900);
        $project->getSubscription()?->setLastPaymentStatus('paid');

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

        $request = $this->createRequest(['session_id' => 'cs_cancel_1']);
        $response = $controller->cancel($project, $request, $entityManager);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/backend/plan', $response->getTargetUrl());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscription()?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscription()?->getTier());
        self::assertSame('paid', $project->getSubscription()?->getLastPaymentStatus());
        self::assertNull($project->getSubscription()?->getTargetTier());
        self::assertNull($project->getSubscription()?->getStripeCheckoutSessionId());
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

    private function createCheckoutService(FakeStripeClient $client, array $plans, bool $expectFlush = false): StripeProjectCheckoutService
    {
        $gate = $this->makeProjectFeatureGate($plans);
        $planRepository = $this->createMock(CommercialPlanRepository::class);
        $indexedPlans = [];
        foreach ($plans as $plan) {
            if ($plan instanceof CommercialPlan) {
                $indexedPlans[strtolower(trim($plan->getCode()))] = $plan;
            }
        }
        $planRepository->method('findActiveByCode')->willReturnCallback(
            static function (string $code) use ($indexedPlans): ?CommercialPlan {
                return $indexedPlans[strtolower(trim($code))] ?? null;
            }
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        if ($expectFlush) {
            $entityManager->expects(self::once())->method('flush');
            $entityManager->expects(self::never())->method('persist');
        }

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/backend/project/42/subscription/success');

        $invoiceStorage ??= $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->method('upsertFromStripeCheckout')->willReturn(new ProjectBillingDocument());

        return new StripeProjectCheckoutService(
            $client,
            $gate,
            $planRepository,
            $entityManager,
            $invoiceStorage,
            $urlGenerator,
            'https://example.test/backend/project/{PROJECT_ID}/subscription/success?session_id={CHECKOUT_SESSION_ID}',
            'https://example.test/backend/project/{PROJECT_ID}/subscription/cancel?session_id={CHECKOUT_SESSION_ID}',
        );
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
            ->setTier($tier)
            ->setStatus($status)
            ->setSource(ProjectSubscription::SOURCE_MANUAL)
            ->setTargetTier($targetTier ?? $tier);

        if ($sessionId !== null) {
            $subscription->setStripeCheckoutSessionId($sessionId);
        }

        $project->setSubscription($subscription);

        return $project;
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
