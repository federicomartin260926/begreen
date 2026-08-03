<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PlanController;
use App\Entity\Measure;
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
use App\Service\SustainabilityPlanCollaborationService;
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

final class PlanControllerMeasureExecutionTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testUpdateSelectionRejectsOperationalFieldBeforeElaborationIsComplete(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 993,
            'plan_status' => 'incompleto',
        ]);
        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'action_taken',
            'value' => 'Acción prematura',
        ]);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $this->createMock(EntityManagerInterface::class)
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($scenario['planMeasure']->getActionTaken());
    }

    public function testUpdateSelectionAllowsElaborationFieldBeforeElaborationIsComplete(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 992,
            'plan_status' => 'incompleto',
            'will_implement' => false,
        ]);
        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'decision',
            'value' => 'true',
        ]);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $this->createEntityManagerMock($scenario['planMeasure'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($scenario['planMeasure']->willImplement());
    }

    public function testUpdateSelectionRequiresNonEmptyObservationsWithoutChangingDecisions(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 991,
            'plan_status' => 'incompleto',
            'will_implement' => false,
        ]);
        $planMeasure = $scenario['planMeasure'];
        $blockSkipAnswer = new SustainabilityPlanBlockAnswer();
        $planMeasure
            ->setIsApplicable(false)
            ->setIsCritical(true)
            ->setObservations('Observación conservada')
            ->markAsBlockSkipped($blockSkipAnswer);

        $saveRequest = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'completeDecision',
            'value' => '  Observación durante Elaboración  ',
        ]);

        $saveResponse = $this->invokeUpdateSelection(
            $scenario['controller'],
            $saveRequest,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $this->createEntityManagerMock($planMeasure, 2)
        );

        self::assertSame(200, $saveResponse->getStatusCode());
        $saveData = json_decode((string) $saveResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($saveData['success']);
        self::assertNull($saveData['nextUrl']);
        self::assertSame('Observación durante Elaboración', $planMeasure->getObservations());
        self::assertFalse($planMeasure->isApplicable());
        self::assertFalse($planMeasure->willImplement());
        self::assertTrue($planMeasure->isCritical());
        self::assertSame('Observación durante Elaboración', $planMeasure->getObservations());
        self::assertSame('block_skip', $planMeasure->getApplicabilitySource());
        self::assertSame($blockSkipAnswer, $planMeasure->getBlockSkipAnswer());
        self::assertFalse(self::getContainer()->get(SustainabilityPlanCollaborationService::class)->hasImplementationActivity($scenario['plan']));
        self::assertSame('incompleto', $scenario['plan']->getStatus());

        $clearRequest = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'completeDecision',
            'value' => '   ',
        ]);
        $unusedEntityManager = $this->createMock(EntityManagerInterface::class);
        $unusedEntityManager->expects(self::never())->method('persist');
        $unusedEntityManager->expects(self::never())->method('flush');
        $clearResponse = $this->invokeUpdateSelection(
            $scenario['controller'],
            $clearRequest,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $unusedEntityManager
        );

        self::assertSame(400, $clearResponse->getStatusCode());
        $clearData = json_decode((string) $clearResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($clearData['success']);
        self::assertNull($clearData['nextUrl']);
        self::assertSame('Observación durante Elaboración', $planMeasure->getObservations());
        self::assertFalse($planMeasure->isApplicable());
        self::assertFalse($planMeasure->willImplement());
        self::assertTrue($planMeasure->isCritical());
        self::assertSame('block_skip', $planMeasure->getApplicabilitySource());
        self::assertSame($blockSkipAnswer, $planMeasure->getBlockSkipAnswer());
        self::assertFalse(self::getContainer()->get(SustainabilityPlanCollaborationService::class)->hasImplementationActivity($scenario['plan']));
        self::assertSame('incompleto', $scenario['plan']->getStatus());
    }

    public function testOnlyCompleteDecisionIsAllowedBeforeElaborationIsComplete(): void
    {
        $controller = $this->getController();
        $method = new \ReflectionMethod($controller, 'isImplementationField');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($controller, 'completeDecision'));
        foreach (['implemented', 'verification', 'action_taken', 'executionIncident', 'evidence', 'evidence_metadata', 'evidenceMetadata', 'internalNotes', 'internal_notes', 'responsibles'] as $field) {
            self::assertTrue($method->invoke($controller, $field), $field . ' must remain blocked before Elaboración is complete.');
        }
    }

    public function testImplementationSavesAndClearsExecutionIncidentWithoutChangingObservations(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 995,
            'observations' => 'Observación general',
            'execution_incident' => 'Incidencia anterior',
        ]);
        $scenario['planMeasure']
            ->setIsApplicable(true)
            ->setIsCritical(false);

        $saveResponse = $this->invokeUpdateSelection(
            $scenario['controller'],
            $this->createRequest([
                'measureId' => (string) $scenario['measure']->getId(),
                'field' => 'executionIncident',
                'value' => '  Incidencia nueva  ',
            ]),
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $this->createEntityManagerMock($scenario['planMeasure'])
        );

        self::assertSame(200, $saveResponse->getStatusCode());
        self::assertSame('Incidencia nueva', $scenario['planMeasure']->getExecutionIncident());
        self::assertSame('Observación general', $scenario['planMeasure']->getObservations());

        $clearResponse = $this->invokeUpdateSelection(
            $scenario['controller'],
            $this->createRequest([
                'measureId' => (string) $scenario['measure']->getId(),
                'field' => 'executionIncident',
                'value' => '   ',
            ]),
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $this->createEntityManagerMock($scenario['planMeasure'])
        );

        self::assertSame(200, $clearResponse->getStatusCode());
        self::assertNull($scenario['planMeasure']->getExecutionIncident());
        self::assertSame('Observación general', $scenario['planMeasure']->getObservations());
    }

    public function testUpdateSelectionAllowsImplementedWhenActionAndEvidenceExist(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 994,
            'measure_name' => 'Medida ejecutable',
            'action_taken' => 'Acción realizada',
            'evidence' => '/uploads/evidences/doc.pdf',
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'implemented',
            'value' => 'true',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertTrue($data['implemented']);
        self::assertTrue($scenario['planMeasure']->isImplemented());
    }

    public function testUpdateSelectionRejectsImplementedWhenActionIsBlank(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 995,
            'measure_name' => 'Medida sin acción',
            'evidence' => '/uploads/evidences/doc.pdf',
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'implemented',
            'value' => 'true',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Para que puedas dar la medida como ejecutada es obligatorio rellenar el campo de acción y subir una evidencia', $data['error']);
        self::assertFalse($data['implemented']);
        self::assertFalse($scenario['planMeasure']->isImplemented());
    }

    public function testUpdateSelectionRejectsImplementedWhenEvidenceIsMissing(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 996,
            'measure_name' => 'Medida sin evidencia',
            'action_taken' => 'Acción realizada',
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'implemented',
            'value' => 'true',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Para que puedas dar la medida como ejecutada es obligatorio rellenar el campo de acción y subir una evidencia', $data['error']);
        self::assertFalse($data['implemented']);
        self::assertFalse($scenario['planMeasure']->isImplemented());
    }

    public function testUpdateSelectionAllowsImplementedFalseWithIncompleteData(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 997,
            'measure_name' => 'Medida incompleta',
            'implemented' => true,
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'implemented',
            'value' => 'false',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertFalse($data['implemented']);
        self::assertFalse($scenario['planMeasure']->isImplemented());
    }

    public function testDeleteEvidenceUnmarksImplementedWhenTheLastEvidenceIsRemoved(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 998,
            'measure_name' => 'Medida con evidencia única',
            'action_taken' => 'Acción realizada',
            'evidence' => '/uploads/evidences/doc.pdf',
            'implemented' => true,
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'file' => '/uploads/evidences/doc.pdf',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure'], 1);

        $response = $this->invokeDeleteEvidence(
            $scenario['controller'],
            $request,
            $scenario['activeProjectService'],
            $scenario['planRepository'],
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertTrue($data['removed']);
        self::assertFalse($data['implemented']);
        self::assertSame('', trim((string) $scenario['planMeasure']->getEvidence()));
        self::assertFalse($scenario['planMeasure']->isImplemented());
    }

    public function testUpdateSelectionClearsImplementedWhenActionBecomesBlank(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 999,
            'measure_name' => 'Medida ejecutada',
            'action_taken' => 'Acción realizada',
            'evidence' => '/uploads/evidences/doc.pdf',
            'implemented' => true,
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'action_taken',
            'value' => '   ',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertFalse($data['implemented']);
        self::assertNull($scenario['planMeasure']->getActionTaken());
        self::assertFalse($scenario['planMeasure']->isImplemented());
    }

    public function testUpdateSelectionClearsImplementedWhenEvidenceBecomesBlank(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 1000,
            'measure_name' => 'Medida ejecutada',
            'action_taken' => 'Acción realizada',
            'evidence' => '/uploads/evidences/doc.pdf',
            'implemented' => true,
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'evidence',
            'value' => '   ',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertFalse($data['implemented']);
        self::assertSame('', trim((string) $scenario['planMeasure']->getEvidence()));
        self::assertFalse($scenario['planMeasure']->isImplemented());
    }

    public function testUpdateSelectionKeepsImplementedWhenUnrelatedFieldChanges(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 1001,
            'measure_name' => 'Medida ejecutada',
            'action_taken' => 'Acción realizada',
            'evidence' => '/uploads/evidences/doc.pdf',
            'implemented' => true,
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'completeDecision',
            'value' => 'Observación nueva',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertTrue($data['implemented']);
        self::assertTrue($scenario['planMeasure']->isImplemented());
    }

    public function testUpdateSelectionRejectsImplementedWhenActionContainsOnlySpaces(): void
    {
        $scenario = $this->buildScenario([
            'measure_id' => 1002,
            'measure_name' => 'Medida con acción en blanco',
            'action_taken' => '   ',
            'evidence' => '/uploads/evidences/doc.pdf',
        ]);

        $request = $this->createRequest([
            'measureId' => (string) $scenario['measure']->getId(),
            'field' => 'implemented',
            'value' => 'true',
        ]);
        $entityManager = $this->createEntityManagerMock($scenario['planMeasure']);

        $response = $this->invokeUpdateSelection(
            $scenario['controller'],
            $request,
            $scenario['measureRepository'],
            $scenario['planMeasureRepository'],
            $scenario['planRepository'],
            $scenario['blockAnswerRepository'],
            $scenario['activeProjectService'],
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Para que puedas dar la medida como ejecutada es obligatorio rellenar el campo de acción y subir una evidencia', $data['error']);
        self::assertFalse($data['implemented']);
        self::assertFalse($scenario['planMeasure']->isImplemented());
    }

    /**
     * @return array{
     *     controller: PlanController,
     *     project: Project,
     *     plan: Plan,
     *     measure: Measure,
     *     planMeasure: PlanMeasure,
     *     measureRepository: MeasureRepository,
     *     planRepository: PlanRepository,
     *     planMeasureRepository: PlanMeasureRepository,
     *     blockAnswerRepository: SustainabilityPlanBlockAnswerRepository,
     *     activeProjectService: ActiveProjectService
     * }
     */
    private function buildScenario(array $options = []): array
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName((string) ($options['measure_name'] ?? 'Medida de prueba'));
        $this->setEntityId($measure, (int) ($options['measure_id'] ?? 9900));

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus((string) ($options['plan_status'] ?? 'completo'));

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setWillImplement((bool) ($options['will_implement'] ?? true))
            ->markAsManual();

        if (array_key_exists('action_taken', $options)) {
            $planMeasure->setActionTaken($options['action_taken']);
        }
        if (array_key_exists('evidence', $options)) {
            $planMeasure->setEvidence($options['evidence']);
        }
        if (array_key_exists('implemented', $options)) {
            $planMeasure->setImplemented($options['implemented']);
        }
        if (array_key_exists('observations', $options)) {
            $planMeasure->setObservations($options['observations']);
        }
        if (array_key_exists('execution_incident', $options)) {
            $planMeasure->setExecutionIncident($options['execution_incident']);
        }

        $plan->addPlanMeasure($planMeasure);

        $measureRepository = $this->createMeasureRepositoryMock([$measure], $measure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($measure, $planMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);

        return [
            'controller' => $controller,
            'project' => $project,
            'plan' => $plan,
            'measure' => $measure,
            'planMeasure' => $planMeasure,
            'measureRepository' => $measureRepository,
            'planRepository' => $planRepository,
            'planMeasureRepository' => $planMeasureRepository,
            'blockAnswerRepository' => $blockAnswerRepository,
            'activeProjectService' => $activeProjectService,
        ];
    }

    private function getController(): PlanController
    {
        self::bootKernel();

        /** @var PlanController $controller */
        $controller = self::getContainer()->get(PlanController::class);
        $controller->setContainer(self::getContainer());

        return $controller;
    }

    private function createRequest(array $post = [], array $query = []): Request
    {
        $request = new Request($query, $post);
        if ($post !== []) {
            $request->setMethod('POST');
        }

        $session = new Session(new MockArraySessionStorage());
        $session->set('_locale', 'es');
        $request->setSession($session);

        return $request;
    }

    private function setAdminToken(): void
    {
        $user = (new User())
            ->setName('Admin')
            ->setSurnames('User')
            ->setEmail('admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable('2024-01-01 00:00:00'));

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

    private function createEntityManagerMock(PlanMeasure $planMeasure, int $flushCount = 2): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($planMeasure);
        $entityManager->expects(self::exactly($flushCount))->method('flush');

        return $entityManager;
    }

    private function invokeUpdateSelection(
        PlanController $controller,
        Request $request,
        MeasureRepository $measureRepository,
        PlanMeasureRepository $planMeasureRepository,
        PlanRepository $planRepository,
        SustainabilityPlanBlockAnswerRepository $blockAnswerRepository,
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

    private function invokeDeleteEvidence(
        PlanController $controller,
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        PlanMeasureRepository $planMeasureRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var JsonResponse $response */
        $response = $controller->deleteEvidence(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $entityManager
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
