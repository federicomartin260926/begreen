<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PlanController;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Entity\User;
use App\Repository\MeasureRepository;
use App\Repository\PlanMeasureRepository;
use App\Repository\PlanRepository;
use App\Repository\SustainabilityPlanBlockAnswerRepository;
use App\Service\ActiveProjectService;
use App\Service\PlanMeasureCatalogResolver;
use App\Tests\Support\CommercialPlanTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class PlanControllerNavigationTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testTerminalSelectionNextUrlPointsToDoneWhenPlanIsComplete(): void
    {
        $controller = $this->getController();

        $url = $this->invokeResolveTerminalSelectionNextUrl($controller, true, 48);

        self::assertStringContainsString('/backend/plan/done', $url);
    }

    public function testTerminalSelectionNextUrlPointsToNextMeasureWhenPendingRemain(): void
    {
        $controller = $this->getController();

        $url = $this->invokeResolveTerminalSelectionNextUrl($controller, false, 48);

        self::assertStringContainsString('/backend/plan/measures', $url);
        self::assertStringContainsString('i=48', $url);
    }

    public function testReviewDefaultFiltersAreExplicit(): void
    {
        $controller = $this->getController();
        $filters = $this->invokeReviewDefaultFilters($controller);

        self::assertSame([
            'is_applicable' => '1',
            'will_implement' => '1',
        ], $filters);
    }

    public function testUpdateSelectionRedirectsTerminalActionToFirstPendingVisibleMeasure(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $pendingMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($pendingMeasure, 101);

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 102);

        $pendingPlanMeasure = (new PlanMeasure())
            ->setMeasure($pendingMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(true)
            ->setCriticalReason(null)
            ->setWillImplement(true)
            ->markAsManual();
        $plan->addPlanMeasure($pendingPlanMeasure);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(false)
            ->setWillImplement(null)
            ->markAsManual();
        $plan->addPlanMeasure($currentPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'willImplement',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$pendingMeasure, $currentMeasure], $currentMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($currentMeasure, $currentPlanMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createEntityManagerMock($currentPlanMeasure);

        $response = $this->invokeUpdateSelection(
            $controller,
            $request,
            $measureRepository,
            $planMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertStringContainsString('/backend/plan/measures', (string) $data['nextUrl']);
        self::assertStringContainsString('i=0', (string) $data['nextUrl']);
        self::assertSame(['backend.plan.flash.pending_critical_reason'], $request->getSession()->getFlashBag()->peek('warning'));
    }

    public function testUpdateSelectionAdvancesToNextVisibleMeasureWithoutWarningWhenPendingExistsEarlier(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $pendingMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($pendingMeasure, 301);

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 302);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 303);

        $pendingPlanMeasure = (new PlanMeasure())
            ->setMeasure($pendingMeasure)
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($pendingPlanMeasure);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(false)
            ->setWillImplement(null)
            ->markAsManual();
        $plan->addPlanMeasure($currentPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'willImplement',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$pendingMeasure, $currentMeasure, $nextMeasure], $currentMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($currentMeasure, $currentPlanMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createEntityManagerMock($currentPlanMeasure);

        $response = $this->invokeUpdateSelection(
            $controller,
            $request,
            $measureRepository,
            $planMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertStringContainsString('/backend/plan/measures', (string) $data['nextUrl']);
        self::assertStringContainsString('i=2', (string) $data['nextUrl']);
        self::assertSame([], $request->getSession()->getFlashBag()->peek('warning'));
    }

    public function testUpdateSelectionRedirectsTerminalActionToDoneWhenPlanIsComplete(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $firstMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($firstMeasure, 201);

        $lastMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($lastMeasure, 202);

        $firstPlanMeasure = (new PlanMeasure())
            ->setMeasure($firstMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(false)
            ->setWillImplement(true)
            ->markAsManual();
        $plan->addPlanMeasure($firstPlanMeasure);

        $lastPlanMeasure = (new PlanMeasure())
            ->setMeasure($lastMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(false)
            ->setWillImplement(null)
            ->markAsManual();
        $plan->addPlanMeasure($lastPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $lastMeasure->getId(),
            'field' => 'willImplement',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$firstMeasure, $lastMeasure], $lastMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($lastMeasure, $lastPlanMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createEntityManagerMock($lastPlanMeasure);

        $response = $this->invokeUpdateSelection(
            $controller,
            $request,
            $measureRepository,
            $planMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertStringContainsString('/backend/plan/done', (string) $data['nextUrl']);
        self::assertSame([], $request->getSession()->getFlashBag()->peek('warning'));
    }

    public function testBlockQuestionYesKeepsCurrentMeasurePendingAndReturnsCurrentIndex(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('biodiversity')
            ->setName('Biodiversidad')
            ->setHasScreeningQuestion(true)
            ->setScreeningQuestion('¿Se va a rodar en espacios naturales?');
        $this->setEntityId($block, 301);

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setMeasureBlock($block)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 401);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 402);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'blockQuestion',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($currentMeasure, $currentPlanMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $persistedEntities = [];
        $entityManager = $this->createEntityManagerMockForBlockQuestion($persistedEntities);

        $response = $this->invokeUpdateSelection(
            $controller,
            $request,
            $measureRepository,
            $planMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertStringContainsString('/backend/plan/measures', (string) $data['nextUrl']);
        self::assertStringContainsString('i=0', (string) $data['nextUrl']);
        self::assertCount(1, $persistedEntities);
        self::assertSame($currentPlanMeasure, $persistedEntities[0]);
        self::assertNull($currentPlanMeasure->isApplicable());
        self::assertNull($currentPlanMeasure->willImplement());
        self::assertNull($currentPlanMeasure->isCritical());
        self::assertNull($currentPlanMeasure->getCriticalReason());
    }

    public function testBlockQuestionNoSkipsBlockAndReturnsFirstVisibleMeasureAfterBlock(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $previousMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($previousMeasure, 501);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('biodiversity')
            ->setName('Biodiversidad')
            ->setHasScreeningQuestion(true)
            ->setScreeningQuestion('¿Se va a rodar en espacios naturales?');
        $this->setEntityId($block, 302);

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setMeasureBlock($block)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 502);

        $blockedSiblingMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setMeasureBlock($block)
            ->setScore(5);
        $this->setEntityId($blockedSiblingMeasure, 503);

        $nextVisibleMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextVisibleMeasure, 504);

        $previousPlanMeasure = (new PlanMeasure())
            ->setMeasure($previousMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(false)
            ->setWillImplement(true)
            ->markAsManual();
        $plan->addPlanMeasure($previousPlanMeasure);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $blockedSiblingPlanMeasure = (new PlanMeasure())
            ->setMeasure($blockedSiblingMeasure)
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($blockedSiblingPlanMeasure);

        $nextVisiblePlanMeasure = (new PlanMeasure())
            ->setMeasure($nextVisibleMeasure)
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextVisiblePlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'blockQuestion',
            'value' => 'false',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([
            $previousMeasure,
            $currentMeasure,
            $blockedSiblingMeasure,
            $nextVisibleMeasure,
        ], $currentMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($currentMeasure, $currentPlanMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $persistedEntities = [];
        $entityManager = $this->createEntityManagerMockForBlockQuestion($persistedEntities);

        $response = $this->invokeUpdateSelection(
            $controller,
            $request,
            $measureRepository,
            $planMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertStringContainsString('/backend/plan/measures', (string) $data['nextUrl']);
        self::assertStringContainsString('i=0', (string) $data['nextUrl']);
        self::assertCount(1, $persistedEntities);
        self::assertSame($currentPlanMeasure, $persistedEntities[0]);
    }

    public function testSkippedBlockDoesNotPreventPlanCompletion(): void
    {
        $controller = $this->getController();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $answeredMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($answeredMeasure, 101);

        $skippedBlock = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('biodiversity')
            ->setName('Biodiversidad')
            ->setHasScreeningQuestion(true)
            ->setScreeningQuestion('¿Aplica?');
        $this->setEntityId($skippedBlock, 201);

        $skippedMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setMeasureBlock($skippedBlock);
        $this->setEntityId($skippedMeasure, 102);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($answeredMeasure)
            ->setIsApplicable(false)
            ->markAsManual();
        $plan->addPlanMeasure($planMeasure);

        $skippedPlanMeasure = (new PlanMeasure())
            ->setMeasure($skippedMeasure)
            ->setIsApplicable(false)
            ->markAsBlockSkipped($blockAnswer = new SustainabilityPlanBlockAnswer());
        $blockAnswer
            ->setMeasureBlock($skippedBlock)
            ->setApplies(false);
        $plan->addPlanMeasure($skippedPlanMeasure);

        $plan->addBlockAnswer($blockAnswer);

        $measureRepository = $this->createMeasureRepositoryMock([$answeredMeasure, $skippedMeasure]);

        $isComplete = $this->invokeIsPlanCompleteForProtocol($controller, $plan, $project, $measureRepository);

        self::assertTrue($isComplete);
    }

    private function getController(): PlanController
    {
        self::bootKernel();

        /** @var PlanController $controller */
        $controller = self::getContainer()->get(PlanController::class);
        $controller->setContainer(self::getContainer());

        return $controller;
    }

    private function createRequest(array $post = []): Request
    {
        $request = new Request([], $post);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
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

    /**
     * @param array<int, Measure> $measures
     */
    private function createMeasureRepositoryMock(array $measures, ?Measure $foundMeasure = null): MeasureRepository
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($measures);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'addSelect', 'from', 'join', 'innerJoin', 'leftJoin', 'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'setParameter', 'distinct'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);

        $repository = $this->createMock(MeasureRepository::class);
        $repository->method('createQueryBuilder')->willReturn($qb);
        $repository->method('find')->willReturnCallback(
            static function (mixed $id) use ($measures, $foundMeasure): ?Measure {
                $id = (int) $id;
                if ($foundMeasure !== null && $foundMeasure->getId() === $id) {
                    return $foundMeasure;
                }

                foreach ($measures as $measure) {
                    if ($measure instanceof Measure && $measure->getId() === $id) {
                        return $measure;
                    }
                }

                return null;
            }
        );

        return $repository;
    }

    private function createPlanRepositoryMock(Plan $plan): PlanRepository
    {
        $repository = $this->createMock(PlanRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($plan): ?Plan {
                return ($criteria['project'] ?? null) === $plan->getProject() ? $plan : null;
            }
        );

        return $repository;
    }

    private function createPlanMeasureRepositoryMock(Measure $measure, PlanMeasure $planMeasure): PlanMeasureRepository
    {
        $repository = $this->createMock(PlanMeasureRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($measure, $planMeasure): ?PlanMeasure {
                $candidate = $criteria['measure'] ?? null;
                if ($candidate instanceof Measure && $candidate->getId() === $measure->getId()) {
                    return $planMeasure;
                }

                return null;
            }
        );

        return $repository;
    }

    private function createActiveProjectServiceMock(Project $project): ActiveProjectService
    {
        $service = $this->createMock(ActiveProjectService::class);
        $service->method('getActiveProject')->willReturn($project);

        return $service;
    }

    private function createEntityManagerMock(PlanMeasure $planMeasure): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($planMeasure);
        $entityManager->expects(self::exactly(2))->method('flush');

        return $entityManager;
    }

    /**
     * @param array<int, object> $persistedEntities
     */
    private function createEntityManagerMockForBlockQuestion(array &$persistedEntities): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedEntities): void {
                $persistedEntities[] = $entity;
            });
        $entityManager->expects(self::exactly(2))->method('flush');

        return $entityManager;
    }

    private function invokeResolveTerminalSelectionNextUrl(
        PlanController $controller,
        bool $planComplete,
        int $nextIndex
    ): string {
        $reflection = new \ReflectionMethod($controller, 'resolveTerminalSelectionNextUrl');
        $reflection->setAccessible(true);

        /** @var string $url */
        $url = $reflection->invoke($controller, $planComplete, $nextIndex);

        return $url;
    }

    /**
     * @return array{is_applicable: string, will_implement: string}
     */
    private function invokeReviewDefaultFilters(PlanController $controller): array
    {
        $reflection = new \ReflectionMethod($controller, 'reviewDefaultFilters');
        $reflection->setAccessible(true);

        /** @var array{is_applicable: string, will_implement: string} $filters */
        $filters = $reflection->invoke($controller);

        return $filters;
    }

    private function invokeIsPlanCompleteForProtocol(
        PlanController $controller,
        Plan $plan,
        Project $project,
        MeasureRepository $measureRepository
    ): bool {
        $reflection = new \ReflectionMethod($controller, 'isPlanCompleteForProtocol');
        $reflection->setAccessible(true);

        /** @var bool $result */
        $result = $reflection->invoke($controller, $plan, $project, $measureRepository);

        return $result;
    }

    private function invokeUpdateSelection(
        PlanController $controller,
        Request $request,
        MeasureRepository $measureRepository,
        PlanMeasureRepository $planMeasureRepository,
        PlanRepository $planRepository,
        \App\Repository\SustainabilityPlanBlockAnswerRepository $blockAnswerRepository,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var JsonResponse $response */
        $response = $controller->updateSelection(
            $request,
            $measureRepository,
            $planMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $em
        );

        return $response;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        while (!$reflection->hasProperty('id') && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        if (!$reflection->hasProperty('id')) {
            return;
        }

        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
