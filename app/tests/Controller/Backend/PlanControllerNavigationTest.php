<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PlanController;
use App\Entity\Measure;
use App\Entity\MeasureVerificationSource;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Entity\VerificationSource;
use App\Entity\User;
use App\Repository\MeasureRepository;
use App\Repository\CommercialPlanRepository;
use App\Repository\PlanMeasureRepository;
use App\Repository\PlanRepository;
use App\Repository\SustainabilityPlanBlockAnswerRepository;
use App\Service\ActiveProjectService;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Tests\Support\CommercialPlanTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class PlanControllerNavigationTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testTerminalSelectionNextUrlPointsToDoneWhenCustomMeasuresStepIsComplete(): void
    {
        $controller = $this->getController();
        $plan = (new Plan())->setStatus('completo')->markCustomMeasuresCompleted();

        $url = $this->invokeResolveTerminalSelectionNextUrl($controller, $plan, true, 48);

        self::assertStringContainsString('/backend/plan/done', $url);
    }

    public function testTerminalSelectionNextUrlPointsToMeasuresWhenCustomMeasuresStepIsPending(): void
    {
        $controller = $this->getController();
        $plan = (new Plan())->setStatus('completo');

        $url = $this->invokeResolveTerminalSelectionNextUrl($controller, $plan, true, 48);

        self::assertStringContainsString('/backend/plan/measures', $url);
        self::assertStringContainsString('i=48', $url);
    }

    public function testReviewDefaultFiltersAreExplicit(): void
    {
        $controller = $this->getController();
        $filters = $this->invokeReviewDefaultFilters($controller);

        self::assertSame([
            'state' => 'pending',
        ], $filters);
    }

    public function testReviewInlineFieldsUseImplementationPhase(): void
    {
        $controller = $this->getControllerWithFeatureGate($this->makeProjectFeatureGate($this->makeDefaultCommercialPlans()));
        $project = $this->makeProjectWithTiers(ProjectSubscription::TIER_PRO, ProjectSubscription::TIER_BASIC);

        self::assertFalse($this->invokeIsReviewInlineFieldAllowed($controller, $project, 'verification'));
        self::assertFalse($this->invokeIsReviewInlineFieldAllowed($controller, $project, 'responsibles'));
        self::assertFalse($this->invokeIsReviewInlineFieldAllowed($controller, $project, 'internal_notes'));
        self::assertTrue($this->invokeIsReviewInlineFieldAllowed($controller, $project, 'decision'));

        $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->setTier(ProjectSubscription::TIER_STANDARD);

        self::assertTrue($this->invokeIsReviewInlineFieldAllowed($controller, $project, 'verification'));
        self::assertTrue($this->invokeIsReviewInlineFieldAllowed($controller, $project, 'responsibles'));
        self::assertTrue($this->invokeIsReviewInlineFieldAllowed($controller, $project, 'internal_notes'));
    }

    public function testIndexRedirectsToMeasuresWhenCustomMeasuresStepIsPending(): void
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
            ->setProtocol($protocol)
            ->setStatus('completo');

        $response = $controller->index(
            $this->createActiveProjectServiceMock($project),
            $this->createPlanRepositoryMock($plan)
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/plan/measures', (string) $response->headers->get('Location'));
    }

    public function testDoneRedirectsToMeasuresWhenCustomMeasuresStepIsPending(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setStatus('completo');

        $response = $controller->done(
            $this->createActiveProjectServiceMock($project),
            $this->createPlanRepositoryMock($plan),
            (new \ReflectionClass(\App\Service\StripeProjectCheckoutService::class))->newInstanceWithoutConstructor(),
            self::getContainer()->get(CommercialPlanRepository::class),
            self::getContainer()->get(MeasureRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/plan/measures', (string) $response->headers->get('Location'));
    }

    public function testContinueCustomMeasuresMarksStepCompletedAndRedirectsToDone(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);
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

        $request = $this->createRequest([
            'action' => 'continue_custom_measures',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([]);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')->willReturn(null);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $checkoutService = $this->newStripeCheckoutServiceStub();
        $commercialPlanRepository = self::getContainer()->get(CommercialPlanRepository::class);

        $response = $controller->measures(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $entityManager,
            $checkoutService,
            $commercialPlanRepository
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/plan/done', (string) $response->headers->get('Location'));
        self::assertNotNull($plan->getCustomMeasuresCompletedAt());
    }

    public function testCustomMeasuresContinueButtonPointsToElaborationClose(): void
    {
        self::bootKernel();
        $this->setAdminToken();
        $requestStack = self::getContainer()->get('request_stack');
        $request = Request::create('/');
        $request->setLocale('es');
        $request->attributes->set('_route', 'backend_plan_measures');
        $request->attributes->set('_route_params', []);
        $requestStack->push($request);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', []);

        $baseContext = [
            'project' => (new Project())->setName('Proyecto demo'),
            'plan' => (new Plan())->setStatus('completo'),
            'projectTier' => ProjectSubscription::TIER_PRO,
            'projectTierLabel' => 'Pro',
            'projectTierSummary' => 'Resumen de prueba',
            'evidenceCount' => 0,
            'evidenceLimit' => null,
            'upgradeCta' => null,
            'commercialCards' => [],
            'hasWatermark' => false,
            'taxonomyPresenter' => self::getContainer()->get(\App\Service\MeasureTaxonomyPresenter::class),
            'collaborationSummary' => ['customMeasures' => 0],
            'commitmentSummary' => ['customMeasures' => 0],
            'navigationQuery' => [],
            'showCustomMeasuresStep' => true,
            'index' => 0,
            'progressIndex' => 0,
            'total' => 0,
            'catalogMeasuresTotal' => 0,
            'measure' => null,
            'planMeasures' => [],
            'canGoNext' => false,
            'planComplete' => true,
            'currentBlockAnswer' => null,
            'planChartsConfig' => [],
            'scoreGained' => 0,
            'scoreMax' => 0,
            'canUseCustomMeasures' => true,
        ];

        $emptyHtml = $twig->render('backend/plan/measures.html.twig', $baseContext + [
            'customMeasures' => [],
        ]);

        $filledHtml = $twig->render('backend/plan/measures.html.twig', $baseContext + [
            'customMeasures' => [
                [
                    'title' => 'Medida propia',
                    'description' => 'Descripción',
                    'state' => 'active',
                    'raw' => 'Medida propia',
                ],
            ],
        ]);

        self::assertStringContainsString('Ir al cierre de Elaboración', $emptyHtml);
        self::assertStringContainsString('Ir al cierre de Elaboración', $filledHtml);

        $requestStack->pop();
    }

    public function testGamificationMessageUsesIntegratedNonBlockingAlert(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get('twig')->render(
            'backend/plan/_gamification_message.html.twig',
            [
                'gamificationMessage' => [
                    'key' => 'welcome.seed.001',
                    'type' => 'welcome',
                    'text' => 'Mensaje integrado de bienvenida',
                ],
            ]
        );

        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('data-gamification-message="welcome"', $html);
        self::assertStringContainsString('Mensaje integrado de bienvenida', $html);
        self::assertStringNotContainsString('modal', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('Continuar', $html);
    }

    public function testElaborationTierSummaryUsesOnlyTheCanonicalTierCodeAndIsSticky(): void
    {
        self::bootKernel();
        $requestStack = self::getContainer()->get('request_stack');
        $request = Request::create('/');
        $request->setLocale('es');
        $requestStack->push($request);
        $twig = self::getContainer()->get('twig');

        foreach (['basic' => 'Basic', 'standard' => 'Standard', 'pro' => 'Pro'] as $tier => $label) {
            $html = $twig->render('backend/plan/_tier_summary_panel.html.twig', [
                'project' => new Project(),
                'projectTier' => $tier,
                'projectTierLabel' => 'Elaboración ' . $label,
                'projectTierDisplayLabel' => $label,
                'phaseLabel' => 'Elaboración',
                'phaseUpgradeLabel' => 'Mejorar plan',
                'upgradeCta' => [
                    'mode' => 'unavailable',
                    'label' => 'No disponible',
                    'options' => [],
                ],
                'stickyTierSummary' => true,
            ]);

            self::assertStringContainsString('Plan de Elaboración: ' . $label, $html);
            self::assertStringNotContainsString('Plan de Elaboración: Elaboración ' . $label, $html);
            self::assertStringContainsString('plan-commercial-summary--sticky', $html);
        }

        $requestStack->pop();
    }

    public function testAddCustomMeasureIsAllowedWhenBasicFeatureIsEnabledManually(): void
    {
        $basicPlan = $this->makeCommercialPlan('basic', [
            'features' => array_replace(
                $this->defaultCommercialPlanDefinition('basic')['features'],
                [
                    'sustainability_plan.custom_measures' => true,
                ]
            ),
        ]);
        $controller = $this->getControllerWithFeatureGate($this->makeProjectFeatureGate([$basicPlan]));
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
            ->setProtocol($protocol)
            ->setStatus('completo');

        $request = $this->createRequest([
            'action' => 'add_custom_measure',
            'custom_measure_title' => 'Instalar paneles solares',
            'custom_measure_description' => 'Reducir consumo eléctrico',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([]);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')->willReturn(null);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $checkoutService = $this->newStripeCheckoutServiceStub();
        $commercialPlanRepository = self::getContainer()->get(CommercialPlanRepository::class);

        $response = $controller->measures(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $entityManager,
            $checkoutService,
            $commercialPlanRepository
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/plan/measures', (string) $response->headers->get('Location'));
        self::assertStringContainsString('Instalar paneles solares', (string) $plan->getCustomMeasures());
        self::assertNull($plan->getCustomMeasuresCompletedAt());
    }

    public function testAddCustomMeasureIsBlockedWhenFeatureIsDisabled(): void
    {
        $controller = $this->getControllerWithFeatureGate($this->makeProjectFeatureGate($this->makeDefaultCommercialPlans()));
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
            ->setProtocol($protocol)
            ->setStatus('completo');

        $request = $this->createRequest([
            'action' => 'add_custom_measure',
            'custom_measure_title' => 'Medida bloqueada',
            'custom_measure_description' => 'No debería guardarse',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([]);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')->willReturn(null);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $checkoutService = $this->newStripeCheckoutServiceStub();
        $commercialPlanRepository = self::getContainer()->get(CommercialPlanRepository::class);

        $response = $controller->measures(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $entityManager,
            $checkoutService,
            $commercialPlanRepository
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/plan/measures', (string) $response->headers->get('Location'));
        self::assertNull($plan->getCustomMeasures());
    }

    public function testMeasuresPageShowsDecisionButtonsAndOmitsImplementStepInCreationFlow(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');

        $measure = (new Measure())
            ->setName('Medida de prueba');
        $this->setEntityId($measure, 801);

        $html = $twig->render('backend/plan/_measure_card.html.twig', [
            'measure' => $measure,
            'planMeasures' => [],
            'taxonomyPresenter' => self::getContainer()->get(\App\Service\MeasureTaxonomyPresenter::class),
            'projectType' => null,
            'currentBlockAnswer' => null,
            'nextCategoryName' => null,
            'prevCategoryName' => null,
        ]);

        self::assertStringContainsString('¿Quieres incluir esta medida en tu plan?', $html);
        self::assertStringContainsString('data-field="decision"', $html);
        self::assertStringContainsString('Sí', $html);
        self::assertStringContainsString('No', $html);
        self::assertStringContainsString('No aplica al proyecto', $html);
        self::assertStringNotContainsString('¿Vas a implementar esta medida en tu proyecto?', $html);
        self::assertStringNotContainsString('No implementar', $html);
        self::assertStringNotContainsString('Implementar', $html);
    }

    public function testMeasuresPageShowsContinueButtonWhenCriticalIsYes(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');

        $measure = (new Measure())
            ->setName('Medida crítica')
            ->setQuestionText('¿Pregunta de prueba?');
        $this->setEntityId($measure, 802);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setIsCritical(true)
            ->setCriticalReason('Motivo visible')
            ->setWillImplement(true)
            ->markAsManual();

        $html = $twig->render('backend/plan/_measure_card.html.twig', [
            'measure' => $measure,
            'planMeasures' => [$planMeasure],
            'taxonomyPresenter' => self::getContainer()->get(\App\Service\MeasureTaxonomyPresenter::class),
            'projectType' => null,
            'currentBlockAnswer' => null,
            'nextCategoryName' => null,
            'prevCategoryName' => null,
        ]);

        self::assertStringContainsString('¿Consideras que es una medida crítica en tu proyecto?', $html);
        self::assertStringContainsString('¿Por qué la consideras crítica?', $html);
        self::assertStringContainsString('Motivo visible', $html);
        self::assertStringContainsString('Continuar', $html);
        self::assertStringNotContainsString('¿Vas a implementar esta medida en tu proyecto?', $html);
        self::assertStringNotContainsString('No implementar', $html);
        self::assertStringNotContainsString('Implementar', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical"[\s\S]*data-field="critical"[\s\S]*data-value="true"/', $html);
        self::assertDoesNotMatchRegularExpression('/data-plan-measures-section="critical"[^>]*class="[^"]*d-none[^"]*"/', $html);
    }

    public function testMeasuresPageShowsCriticalStepWithNoSelectedWhenCriticalIsFalse(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');

        $planMeasure = $this->buildLoadedMeasureState(true, true, false, null);

        self::assertTrue($this->invokeCanAdvanceFromCurrentMeasure($this->getController(), $planMeasure));

        $measure = $planMeasure->getMeasure();
        $html = $twig->render('backend/plan/_measure_card.html.twig', [
            'measure' => $measure,
            'planMeasures' => [$planMeasure],
            'taxonomyPresenter' => self::getContainer()->get(\App\Service\MeasureTaxonomyPresenter::class),
            'projectType' => null,
            'currentBlockAnswer' => null,
            'nextCategoryName' => null,
            'prevCategoryName' => null,
        ]);

        self::assertStringContainsString('¿Consideras que es una medida crítica en tu proyecto?', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical"[\s\S]*data-field="critical"[\s\S]*data-value="false"/', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical-reason"[^>]*class="[^"]*d-none[^"]*"/', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical-continue"[^>]*class="[^"]*d-none[^"]*"/', $html);
        self::assertDoesNotMatchRegularExpression('/data-plan-measures-section="critical"[^>]*class="[^"]*d-none[^"]*"/', $html);
    }

    public function testMeasuresPageHidesCriticalStepAndAllowsNextWhenDecisionIsNo(): void
    {
        $controller = $this->getController();

        $planMeasure = $this->buildLoadedMeasureState(true, false, null, null);

        self::assertTrue($this->invokeCanAdvanceFromCurrentMeasure($controller, $planMeasure));

        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        $measure = $planMeasure->getMeasure();
        $html = $twig->render('backend/plan/_measure_card.html.twig', [
            'measure' => $measure,
            'planMeasures' => [$planMeasure],
            'taxonomyPresenter' => self::getContainer()->get(\App\Service\MeasureTaxonomyPresenter::class),
            'projectType' => null,
            'currentBlockAnswer' => null,
            'nextCategoryName' => null,
            'prevCategoryName' => null,
        ]);

        self::assertStringContainsString('data-value="false"', $html);
        self::assertStringContainsString('btn-danger', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical"\\s+class="d-none"/', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical-reason"\\s+class="d-none"/', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical-continue"\\s+class="d-none"/', $html);
    }

    public function testMeasuresPageHidesCriticalStepAndAllowsNextWhenDecisionIsNotApplicable(): void
    {
        $controller = $this->getController();

        $planMeasure = $this->buildLoadedMeasureState(false, null, null, null);

        self::assertTrue($this->invokeCanAdvanceFromCurrentMeasure($controller, $planMeasure));

        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        $measure = $planMeasure->getMeasure();
        $html = $twig->render('backend/plan/_measure_card.html.twig', [
            'measure' => $measure,
            'planMeasures' => [$planMeasure],
            'taxonomyPresenter' => self::getContainer()->get(\App\Service\MeasureTaxonomyPresenter::class),
            'projectType' => null,
            'currentBlockAnswer' => null,
            'nextCategoryName' => null,
            'prevCategoryName' => null,
        ]);

        self::assertStringContainsString('No aplica al proyecto', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical"\\s+class="d-none"/', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical-reason"\\s+class="d-none"/', $html);
        self::assertMatchesRegularExpression('/data-plan-measures-section="critical-continue"\\s+class="d-none"/', $html);
    }

    public function testListExposesCheckedImplementRadioWhenWillImplementIsYes(): void
    {
        self::bootKernel();
        $this->setAdminToken();
        $requestStack = self::getContainer()->get('request_stack');
        $request = Request::create('/backend/plan/review');
        $request->setLocale('es');
        $request->attributes->set('_route', 'backend_plan_review');
        $request->attributes->set('_route_params', []);
        $requestStack->push($request);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', []);

        $planMeasure = $this->buildLoadedMeasureState(true, true, false, null);
        $measure = $planMeasure->getMeasure();

        $html = $twig->render('backend/plan/_list.html.twig', [
            'project' => (new Project())->setName('Proyecto demo'),
            'plan' => (new Plan())->setStatus('completo'),
            'measures' => [$measure],
            'planMeasures' => [$planMeasure],
            'projectTier' => ProjectSubscription::TIER_PRO,
            'projectTierLabel' => 'Pro',
            'projectTierSummary' => 'Resumen de prueba',
            'evidenceCount' => 0,
            'evidenceLimit' => null,
            'upgradeCta' => null,
            'commercialCards' => [],
            'hasWatermark' => false,
            'taxonomyPresenter' => self::getContainer()->get(\App\Service\MeasureTaxonomyPresenter::class),
            'collaborationSummary' => ['customMeasures' => 0],
            'commitmentSummary' => ['customMeasures' => 0],
            'navigationQuery' => [],
            'showCustomMeasuresStep' => true,
            'index' => 0,
            'progressIndex' => 0,
            'total' => 1,
            'catalogMeasuresTotal' => 1,
            'measure' => null,
            'canGoNext' => false,
            'planComplete' => true,
            'currentBlockAnswer' => null,
            'planChartsConfig' => [],
            'scoreGained' => 0,
            'scoreMax' => 0,
            'canUseCustomMeasures' => true,
            'crewMembersByMeasure' => [],
            'canUseChecklist' => false,
            'canUseResponsibles' => false,
            'canUseInternalNotes' => false,
            'operationalStateResolver' => self::getContainer()->get(\App\Service\PlanMeasureOperationalStateResolver::class),
        ]);

        self::assertMatchesRegularExpression('/<input[^>]*name="implement-' . $measure->getId() . '"[^>]*value="true"[^>]*checked[^>]*>/', $html);
        self::assertStringContainsString('data-action="change->plan-review#toggleImplemented"', $html);

        $requestStack->pop();
    }

    public function testReviewRedirectsToMeasuresAtFirstPendingMeasureWhenUpgradeBreaksCompleteness(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);
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

        $completedMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida completa');
        $this->setEntityId($completedMeasure, 901);

        $pendingMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(3)
            ->setName('Medida pendiente');
        $this->setEntityId($pendingMeasure, 902);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($completedMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(false)
            ->setWillImplement(true)
            ->markAsManual();
        $plan->addPlanMeasure($planMeasure);

        $request = $this->createRequest();
        $measureRepository = $this->createMeasureRepositoryMock([$completedMeasure, $pendingMeasure], $completedMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $protocolRepository = $this->createMock(\App\Repository\ProtocolRepository::class);
        $commercialPlanRepository = $this->createMock(\App\Repository\CommercialPlanRepository::class);
        $checkoutService = $this->newStripeCheckoutServiceStub();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $mailer = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);

        $response = $controller->review(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $protocolRepository,
            $commercialPlanRepository,
            $checkoutService,
            $entityManager,
            $mailer,
            $translator
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/plan/measures', (string) $response->headers->get('Location'));
        self::assertStringContainsString('i=1', (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('only_pending=1', (string) $response->headers->get('Location'));
    }

    public function testUpdateSelectionIgnoresInternalNotesWhenFeatureIsUnavailable(): void
    {
        [$controller, $project, $plan, $measure, $planMeasure, $measureRepository, $planRepository, $planMeasureRepository, $blockAnswerRepository, $activeProjectService] = $this->buildInternalNotesScenario(false);

        $entityManager = $this->createEntityManagerMockForIgnoredSelection();
        $request = $this->createRequest([
            'measureId' => (string) $measure->getId(),
            'field' => 'internal_notes',
            'value' => 'updated private note',
        ]);

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
        self::assertArrayHasKey('nextUrl', $data);
        self::assertNull($data['nextUrl']);
        self::assertSame('original note', $planMeasure->getInternalNotes());
    }

    public function testUpdateSelectionPersistsInternalNotesWhenFeatureIsAvailable(): void
    {
        [$controller, $project, $plan, $measure, $planMeasure, $measureRepository, $planRepository, $planMeasureRepository, $blockAnswerRepository, $activeProjectService] = $this->buildInternalNotesScenario(true);

        $entityManager = $this->createEntityManagerMock($planMeasure);
        $request = $this->createRequest([
            'measureId' => (string) $measure->getId(),
            'field' => 'internal_notes',
            'value' => 'updated private note',
        ]);

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
        self::assertSame('updated private note', $planMeasure->getInternalNotes());
    }

    public function testUpdateSelectionPersistsEvidenceMetadataOnlyForVisibleFilesAndAllowedSources(): void
    {
        self::bootKernel();
        $basicPlan = $this->makeCommercialPlan('basic', [
            'phase' => CommercialPhase::IMPLEMENTATION,
            'features' => array_replace(
                $this->defaultImplementationCommercialPlanDefinition('basic')['features'],
                [
                    'sustainability_plan.evidence_upload' => true,
                ]
            ),
        ]);
        $controller = $this->getControllerWithFeatureGate($this->makeProjectFeatureGate([
            $this->makeCommercialPlan('basic'),
            $basicPlan,
        ]));
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $sourceAllowed = (new VerificationSource())
            ->setCode('invoice')
            ->setName('Invoice')
            ->setSortOrder(10);
        $this->setEntityId($sourceAllowed, 21);

        $sourceOther = (new VerificationSource())
            ->setCode('manual')
            ->setName('Manual note')
            ->setSortOrder(20);
        $this->setEntityId($sourceOther, 22);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida con evidencias');
        $this->setEntityId($measure, 991);
        $measure->addVerificationSourceLink(
            (new MeasureVerificationSource())
                ->setVerificationSource($sourceAllowed)
                ->setPriority(1)
        );

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setEvidence("/uploads/evidences/doc-1.pdf\n/uploads/evidences/doc-2.pdf")
            ->setEvidenceMetadata([
                '/uploads/evidences/doc-1.pdf' => 'invoice',
                '/uploads/evidences/doc-2.pdf' => 'manual',
                '/uploads/evidences/not-visible.pdf' => 'invoice',
            ])
            ->markAsManual();
        $plan->addPlanMeasure($planMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $measure->getId(),
            'field' => 'evidence_metadata',
            'value' => json_encode([
                '/uploads/evidences/doc-1.pdf' => 'invoice',
                '/uploads/evidences/doc-2.pdf' => 'manual',
                '/uploads/evidences/not-visible.pdf' => 'invoice',
            ], JSON_THROW_ON_ERROR),
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$measure], $measure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($measure, $planMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createEntityManagerMock($planMeasure);

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
        self::assertSame([
            '/uploads/evidences/doc-1.pdf' => 'invoice',
        ], $planMeasure->getEvidenceMetadata());
        self::assertSame("/uploads/evidences/doc-1.pdf\n/uploads/evidences/doc-2.pdf", $planMeasure->getEvidence());
    }

    public function testDeleteEvidenceAlsoRemovesItsMetadata(): void
    {
        self::bootKernel();
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
            ->setName('Medida con evidencias');
        $this->setEntityId($measure, 992);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setEvidence("/uploads/evidences/doc-1.pdf\n/uploads/evidences/doc-2.pdf")
            ->setEvidenceMetadata([
                '/uploads/evidences/doc-1.pdf' => 'invoice',
                '/uploads/evidences/doc-2.pdf' => 'manual',
            ])
            ->markAsManual();
        $plan->addPlanMeasure($planMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $measure->getId(),
            'file' => '/uploads/evidences/doc-1.pdf',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$measure], $measure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($measure, $planMeasure);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($planMeasure);
        $entityManager->expects(self::once())->method('flush');

        $response = $controller->deleteEvidence(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $entityManager
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame("/uploads/evidences/doc-2.pdf", $planMeasure->getEvidence());
        self::assertSame([
            '/uploads/evidences/doc-2.pdf' => 'manual',
        ], $planMeasure->getEvidenceMetadata());
    }

    public function testUploadEvidencesStoresMetadataForTheNewFile(): void
    {
        self::bootKernel();
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $sourceAllowed = (new VerificationSource())
            ->setCode('invoice')
            ->setName('Invoice')
            ->setSortOrder(10);
        $this->setEntityId($sourceAllowed, 31);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida con subida');
        $this->setEntityId($measure, 993);
        $measure->addVerificationSourceLink(
            (new MeasureVerificationSource())
                ->setVerificationSource($sourceAllowed)
                ->setPriority(1)
        );

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setEvidence('/uploads/evidences/existing.pdf')
            ->setEvidenceMetadata([
                '/uploads/evidences/existing.pdf' => 'invoice',
            ])
            ->markAsManual();
        $plan->addPlanMeasure($planMeasure);

        $tmpFile = tempnam(sys_get_temp_dir(), 'evidence_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'fake pdf content');
        $uploadedFile = new UploadedFile($tmpFile, 'factura.pdf', 'application/pdf', null, true);

        $request = $this->createRequest([
            'measureId' => (string) $measure->getId(),
            'source_code' => 'invoice',
        ]);
        $request->files->set('evidences', [$uploadedFile]);

        $measureRepository = $this->createMeasureRepositoryMock([$measure], $measure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($measure, $planMeasure);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($planMeasure);
        $entityManager->expects(self::once())->method('flush');

        $slugger = $this->createMock(\Symfony\Component\String\Slugger\SluggerInterface::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);

        $response = $controller->uploadEvidences(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $entityManager,
            $slugger
        );

        @unlink($tmpFile);

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertCount(1, $data['files']);
        self::assertStringStartsWith('/uploads/evidences/', $data['files'][0]);
        self::assertSame('/uploads/evidences/existing.pdf' . "\n" . $data['files'][0], $planMeasure->getEvidence());
        self::assertSame([
            '/uploads/evidences/existing.pdf' => 'invoice',
            $data['files'][0] => 'invoice',
        ], $planMeasure->getEvidenceMetadata());
    }

    public function testUploadEvidencesUsesImplementationBasicLimitEvenWhenElaborationIsPro(): void
    {
        self::bootKernel();
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTiers(ProjectSubscription::TIER_PRO, ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 2);

        $targetMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida con límite');
        $this->setEntityId($targetMeasure, 994);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        for ($i = 1; $i <= 10; $i++) {
            $existingMeasure = (new Measure())
                ->setProtocol($protocol)
                ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
                ->setScore(5)
                ->setName('Medida previa ' . $i);
            $this->setEntityId($existingMeasure, 2000 + $i);

            $existingPlanMeasure = (new PlanMeasure())
                ->setMeasure($existingMeasure)
                ->setEvidence('/uploads/evidences/existing-' . $i . '.pdf')
                ->markAsManual();
            $plan->addPlanMeasure($existingPlanMeasure);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'evidence_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'fake pdf content');
        $uploadedFile = new UploadedFile($tmpFile, 'limite.pdf', 'application/pdf', null, true);

        $request = $this->createRequest([
            'measureId' => (string) $targetMeasure->getId(),
        ]);
        $request->files->set('evidences', [$uploadedFile]);

        $measureRepository = $this->createMeasureRepositoryMock([$targetMeasure], $targetMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $slugger = $this->createMock(\Symfony\Component\String\Slugger\SluggerInterface::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);

        $response = $controller->uploadEvidences(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $entityManager,
            $slugger
        );

        self::assertFileExists($tmpFile);
        @unlink($tmpFile);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Basic permite un máximo de 10 evidencias por proyecto.', $data['error']);
        self::assertCount(10, $plan->getPlanMeasures());
        foreach ($plan->getPlanMeasures() as $existingPlanMeasure) {
            self::assertNotSame($targetMeasure, $existingPlanMeasure->getMeasure());
        }
    }

    public function testUploadEvidencesRejectsWhenImplementationEvidenceUploadIsDisabled(): void
    {
        self::bootKernel();
        $basicImplementationPlan = $this->makeCommercialPlan('basic', [
            'phase' => CommercialPhase::IMPLEMENTATION,
            'features' => array_replace(
                $this->defaultImplementationCommercialPlanDefinition('basic')['features'],
                [
                    'sustainability_plan.evidence_upload' => false,
                ]
            ),
        ]);
        $controller = $this->getControllerWithFeatureGate($this->makeProjectFeatureGate([
            $this->makeCommercialPlan('basic'),
            $this->makeCommercialPlan('standard'),
            $this->makeCommercialPlan('pro'),
            $basicImplementationPlan,
            $this->makeCommercialPlan('standard', ['phase' => CommercialPhase::IMPLEMENTATION]),
            $this->makeCommercialPlan('pro', ['phase' => CommercialPhase::IMPLEMENTATION]),
        ]));
        $this->setAdminToken();

        $project = $this->makeProjectWithTiers(ProjectSubscription::TIER_PRO, ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 3);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida denegada');
        $this->setEntityId($measure, 995);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        $request = $this->createRequest([
            'measureId' => (string) $measure->getId(),
        ]);
        $measureRepository = $this->createMeasureRepositoryMock([$measure], $measure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $tmpFile = tempnam(sys_get_temp_dir(), 'evidence_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'fake pdf content');
        $uploadedFile = new UploadedFile($tmpFile, 'denegada.pdf', 'application/pdf', null, true);
        $request->files->set('evidences', [$uploadedFile]);

        $slugger = $this->createMock(\Symfony\Component\String\Slugger\SluggerInterface::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);

        $response = $controller->uploadEvidences(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $entityManager,
            $slugger
        );

        self::assertFileExists($tmpFile);
        @unlink($tmpFile);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Feature not available for current plan tier', $data['error']);
        self::assertCount(0, $plan->getPlanMeasures());
    }

    public function testUploadEvidencesRejectsInvalidSourceWithoutPersistingPlanMeasure(): void
    {
        self::bootKernel();
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 4);

        $sourceAllowed = (new VerificationSource())
            ->setCode('invoice')
            ->setName('Invoice')
            ->setSortOrder(10);
        $this->setEntityId($sourceAllowed, 40);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida con fuente inválida');
        $this->setEntityId($measure, 996);
        $measure->addVerificationSourceLink(
            (new MeasureVerificationSource())
                ->setVerificationSource($sourceAllowed)
                ->setPriority(1)
        );

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        $tmpFile = tempnam(sys_get_temp_dir(), 'evidence_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'fake pdf content');
        $uploadedFile = new UploadedFile($tmpFile, 'fuente-invalida.pdf', 'application/pdf', null, true);

        $request = $this->createRequest([
            'measureId' => (string) $measure->getId(),
            'source_code' => 'manual',
        ]);
        $request->files->set('evidences', [$uploadedFile]);

        $measureRepository = $this->createMeasureRepositoryMock([$measure], $measure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $slugger = $this->createMock(\Symfony\Component\String\Slugger\SluggerInterface::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);

        $response = $controller->uploadEvidences(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $entityManager,
            $slugger
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Fuente de verificación inválida.', $data['error']);
        self::assertCount(0, $plan->getPlanMeasures());
        self::assertFileExists($tmpFile);
        @unlink($tmpFile);
    }

    public function testUpdateSelectionRedirectsTerminalActionToFirstPendingVisibleMeasure(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);
        $this->setEntityId($protocol, 1);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->markCustomMeasuresCompleted();
        $this->setEntityId($plan, 9000);
        $this->setEntityId($plan, 9000);

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
        self::assertStringNotContainsString('only_pending=1', (string) $data['nextUrl']);
        self::assertSame(['backend.plan.flash.pending_critical_reason'], $request->getSession()->getFlashBag()->peek('warning'));
    }

    public function testUpdateSelectionDoesNotAppendOnlyPendingWhenFilterIsInactive(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);
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
            ->markCustomMeasuresCompleted();

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 103);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 104);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(true)
            ->setCriticalReason('Razón válida')
            ->setWillImplement(null)
            ->markAsManual();
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'willImplement',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
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
        self::assertStringContainsString('i=1', (string) $data['nextUrl']);
        self::assertStringNotContainsString('only_pending=1', (string) $data['nextUrl']);
    }

    public function testUpdateSelectionAdvancesToNextVisibleMeasureWithoutWarningWhenPendingExistsEarlier(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);
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
            ->markCustomMeasuresCompleted();

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

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);
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
            ->markCustomMeasuresCompleted();

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

    public function testUpdateSelectionDecisionYesMarksMeasureAsIncludedAndStaysOnCriticalStep(): void
    {
        [$controller, $request, $measureRepository, $planMeasureRepository, $planRepository, $blockAnswerRepository, $activeProjectService, $entityManager, $currentPlanMeasure] = $this->buildDecisionScenario('true');

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
        self::assertArrayHasKey('nextUrl', $data);
        self::assertNull($data['nextUrl']);
        self::assertTrue($currentPlanMeasure->isApplicable());
        self::assertNull($currentPlanMeasure->isCritical());
        self::assertTrue($currentPlanMeasure->willImplement());
    }

    public function testSavingTheSameYesDecisionPreservesCriticalDataAndDoesNotGenerateMessage(): void
    {
        [$controller, $request, $measureRepository, $planMeasureRepository, $planRepository, $blockAnswerRepository, $activeProjectService, $entityManager, $currentPlanMeasure] = $this->buildDecisionScenario('true');
        $answeredAt = new \DateTimeImmutable('2026-07-28 10:00:00');
        $criticalHandledAt = new \DateTimeImmutable('2026-07-28 10:05:00');
        $currentPlanMeasure
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setIsCritical(true)
            ->setCriticalReason('Motivo conservado')
            ->markFirstDecisionAnswered($answeredAt)
            ->markCriticalGamificationHandled($criticalHandledAt);

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

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertTrue($data['unchangedDecision']);
        self::assertArrayNotHasKey('gamification', $data);
        self::assertNull($data['nextUrl']);
        self::assertTrue($currentPlanMeasure->isCritical());
        self::assertSame('Motivo conservado', $currentPlanMeasure->getCriticalReason());
        self::assertSame($answeredAt, $currentPlanMeasure->getFirstDecisionAnsweredAt());
        self::assertSame($criticalHandledAt, $currentPlanMeasure->getCriticalGamificationHandledAt());
    }

    public function testUpdateSelectionDecisionNoMarksMeasureAsApplicableButNotIncluded(): void
    {
        [$controller, $request, $measureRepository, $planMeasureRepository, $planRepository, $blockAnswerRepository, $activeProjectService, $entityManager, $currentPlanMeasure] = $this->buildDecisionScenario('false');

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
        self::assertStringContainsString('i=1', (string) $data['nextUrl']);
        self::assertArrayNotHasKey('gamification', $data);
        self::assertSame('welcome', $currentPlanMeasure->getPlan()->getPendingGamificationType());
        self::assertStringStartsWith(
            'welcome.seed.',
            (string) $currentPlanMeasure->getPlan()->getPendingGamificationKey()
        );
        self::assertTrue($currentPlanMeasure->isApplicable());
        self::assertNull($currentPlanMeasure->isCritical());
        self::assertFalse($currentPlanMeasure->willImplement());
    }

    public function testUpdateSelectionKeepsImmediateNavigationWhenNoGamificationMessageIsDue(): void
    {
        [$controller, $request, $measureRepository, $planMeasureRepository, $planRepository, $blockAnswerRepository, $activeProjectService, $entityManager, $currentPlanMeasure] = $this->buildDecisionScenario('false');
        $currentPlanMeasure->getPlan()->markGamificationLevelPresented('seed');

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

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayNotHasKey('gamification', $data);
        self::assertFalse($currentPlanMeasure->getPlan()->hasPendingGamificationMessage());
        self::assertStringContainsString('/backend/plan/measures', (string) $data['nextUrl']);
        self::assertStringContainsString('i=1', (string) $data['nextUrl']);
    }

    public function testUpdateSelectionDecisionNotApplicableClearsApplicability(): void
    {
        [$controller, $request, $measureRepository, $planMeasureRepository, $planRepository, $blockAnswerRepository, $activeProjectService, $entityManager, $currentPlanMeasure] = $this->buildDecisionScenario('na');

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
        self::assertStringContainsString('i=1', (string) $data['nextUrl']);
        self::assertFalse($currentPlanMeasure->isApplicable());
        self::assertNull($currentPlanMeasure->isCritical());
        self::assertNull($currentPlanMeasure->willImplement());
    }

    public function testUpdateSelectionDecisionNoOnLastMeasureRedirectsToDone(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->markCustomMeasuresCompleted();

        $lastMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($lastMeasure, 1111);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($lastMeasure)
            ->setIsApplicable(true)
            ->setWillImplement(false)
            ->setIsCritical(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $lastMeasure->getId(),
            'field' => 'decision',
            'value' => 'false',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$lastMeasure], $lastMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($lastMeasure, $currentPlanMeasure);
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
        self::assertStringContainsString('/backend/plan/done', (string) $data['nextUrl']);
    }

    public function testUpdateSelectionDecisionNotApplicableOnLastMeasureRedirectsToDone(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->markCustomMeasuresCompleted();

        $lastMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($lastMeasure, 1112);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($lastMeasure)
            ->setIsApplicable(null)
            ->setWillImplement(null)
            ->setIsCritical(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $lastMeasure->getId(),
            'field' => 'decision',
            'value' => 'na',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$lastMeasure], $lastMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($lastMeasure, $currentPlanMeasure);
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
        self::assertStringContainsString('/backend/plan/done', (string) $data['nextUrl']);
    }

    public function testUpdateSelectionWillImplementWithCriticalReasonOnLastMeasureRedirectsToDone(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->markCustomMeasuresCompleted();

        $lastMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($lastMeasure, 1113);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($lastMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(true)
            ->setCriticalReason('Motivo válido')
            ->setWillImplement(true)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $lastMeasure->getId(),
            'field' => 'willImplement',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$lastMeasure], $lastMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($lastMeasure, $currentPlanMeasure);
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
        self::assertStringContainsString('/backend/plan/done', (string) $data['nextUrl']);
    }

    public function testUpdateSelectionCriticalNoAdvancesWithoutRequiringReason(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->markCustomMeasuresCompleted();

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 111);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 112);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setIsCritical(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'critical',
            'value' => 'false',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
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
        self::assertStringContainsString('i=1', (string) $data['nextUrl']);
        self::assertFalse($currentPlanMeasure->isCritical());
        self::assertNull($currentPlanMeasure->getCriticalReason());
    }

    public function testUpdateSelectionCriticalYesCanBeUnsetToNoAndClearsReason(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->markCustomMeasuresCompleted();

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 113);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 114);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setIsCritical(true)
            ->setCriticalReason('Motivo original')
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'critical',
            'value' => 'false',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
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
        self::assertStringContainsString('i=1', (string) $data['nextUrl']);
        self::assertFalse($currentPlanMeasure->isCritical());
        self::assertNull($currentPlanMeasure->getCriticalReason());
    }

    public function testUpdateSelectionCriticalYesRequiresReasonBeforeAdvancing(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->markCustomMeasuresCompleted();

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 211);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 212);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setIsCritical(null)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'critical',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
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
        self::assertArrayHasKey('nextUrl', $data);
        self::assertNull($data['nextUrl']);
        self::assertTrue($currentPlanMeasure->isCritical());
        self::assertNull($currentPlanMeasure->getCriticalReason());
    }

    public function testUpdateSelectionWillImplementRequiresCriticalReasonWhenMeasureIsCritical(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 311);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 312);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(true)
            ->setCriticalReason(null)
            ->setWillImplement(true)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'willImplement',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($currentMeasure, $currentPlanMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createEntityManagerMockForIgnoredSelection();

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
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('motivo', (string) $data['error']);
        self::assertNull($data['nextUrl'] ?? null);
        self::assertSame('', $currentPlanMeasure->getCriticalReason() ?? '');
    }

    public function testUpdateSelectionWillImplementWithCriticalReasonAdvances(): void
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($currentMeasure, 411);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($nextMeasure, 412);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setIsApplicable(true)
            ->setIsCritical(true)
            ->setCriticalReason('Motivo válido')
            ->setWillImplement(true)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'willImplement',
            'value' => 'true',
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
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
        self::assertStringContainsString('i=1', (string) $data['nextUrl']);
        self::assertSame('Motivo válido', $currentPlanMeasure->getCriticalReason());
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

    public function testMeasuresProgressCountsSkippedBlockMeasuresInBaseTotal(): void
    {
        $controller = $this->getController();
        $this->setAdminToken();
        self::getContainer()->get('twig')->addGlobal('userProjects', []);

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);
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
            ->markCustomMeasuresCompleted();
        $this->setEntityId($plan, 9000);

        $measure1 = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida 1');
        $this->setEntityId($measure1, 501);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('biodiversity')
            ->setName('Biodiversidad')
            ->setHasScreeningQuestion(true)
            ->setScreeningQuestion('¿Se va a rodar en espacios naturales?');
        $this->setEntityId($block, 502);

        $measure2 = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setMeasureBlock($block)
            ->setScore(5)
            ->setName('Medida 2');
        $this->setEntityId($measure2, 503);

        $measure3 = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida 3');
        $this->setEntityId($measure3, 504);

        $measure4 = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida 4');
        $this->setEntityId($measure4, 505);

        $planMeasure1 = (new PlanMeasure())
            ->setMeasure($measure1)
            ->setIsApplicable(true)
            ->setIsCritical(false)
            ->setWillImplement(true)
            ->markAsManual();
        $plan->addPlanMeasure($planMeasure1);

        $planMeasure2 = (new PlanMeasure())
            ->setMeasure($measure2)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($planMeasure2);

        $planMeasure3 = (new PlanMeasure())
            ->setMeasure($measure3)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($planMeasure3);

        $planMeasure4 = (new PlanMeasure())
            ->setMeasure($measure4)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($planMeasure4);

        $measureRepository = $this->createMeasureRepositoryMock([$measure1, $measure2, $measure3, $measure4], $measure2);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($measure2, $planMeasure2);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $persistedEntities = [];
        $entityManager = $this->createEntityManagerMockForBlockQuestion($persistedEntities);
        $planCompletionService = self::getContainer()->get(\App\Service\SustainabilityPlanCompletionService::class);

        $visibleMeasuresBefore = $planCompletionService->getVisibleMeasures($plan, $project, $measureRepository);
        $currentIndexBefore = $planCompletionService->findVisibleMeasureIndex($visibleMeasuresBefore, $measure2);
        self::assertNotNull($currentIndexBefore);

        $beforeRequest = $this->createRequest([], ['i' => $currentIndexBefore]);
        $beforeRequest->attributes->set('_route', 'backend_plan_measures');
        $beforeRequest->attributes->set('_route_params', []);

        $beforeHtml = $this->invokeMeasures(
            $controller,
            $beforeRequest,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $this->createEntityManagerMockForMeasuresView(),
            $this->newStripeCheckoutServiceStubForProTier(),
            self::getContainer()->get(\App\Repository\CommercialPlanRepository::class)
        )->getContent();

        self::assertIsString($beforeHtml);
        self::assertStringContainsString('Medida 2 de 4', $beforeHtml);

        $request = $this->createRequest([
            'measureId' => (string) $measure2->getId(),
            'field' => 'blockQuestion',
            'value' => 'false',
        ]);

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

        $visibleMeasuresAfter = $planCompletionService->getVisibleMeasures($plan, $project, $measureRepository);
        $currentIndexAfter = $planCompletionService->findVisibleMeasureIndex($visibleMeasuresAfter, $measure3);
        $lastIndexAfter = $planCompletionService->findVisibleMeasureIndex($visibleMeasuresAfter, $measure4);
        self::assertNotNull($currentIndexAfter);
        self::assertNotNull($lastIndexAfter);

        $nextRequest = $this->createRequest([], ['i' => $currentIndexAfter]);
        $nextRequest->attributes->set('_route', 'backend_plan_measures');
        $nextRequest->attributes->set('_route_params', []);
        $nextHtml = $this->invokeMeasures(
            $controller,
            $nextRequest,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $this->createEntityManagerMockForMeasuresView(),
            $this->newStripeCheckoutServiceStubForProTier(),
            self::getContainer()->get(\App\Repository\CommercialPlanRepository::class)
        )->getContent();

        self::assertIsString($nextHtml);
        self::assertStringContainsString('Medida 3 de 4', $nextHtml);

        $progressRequest = $this->createRequest([
            'measureId' => (string) $measure3->getId(),
            'field' => 'decision',
            'value' => 'false',
        ]);
        $progressMeasureRepository = $this->createMeasureRepositoryMock([$measure1, $measure2, $measure3, $measure4], $measure3);
        $progressPlanMeasureRepository = $this->createPlanMeasureRepositoryMock($measure3, $planMeasure3);
        $progressEntityManager = $this->createEntityManagerMock($planMeasure3);

        $progressResponse = $this->invokeUpdateSelection(
            $controller,
            $progressRequest,
            $progressMeasureRepository,
            $progressPlanMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $progressEntityManager
        );

        self::assertInstanceOf(JsonResponse::class, $progressResponse);
        $progressData = json_decode((string) $progressResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($progressData['success']);

        $plan->markCustomMeasuresCompleted();

        $lastRequest = $this->createRequest([], ['i' => $lastIndexAfter]);
        $lastRequest->attributes->set('_route', 'backend_plan_measures');
        $lastRequest->attributes->set('_route_params', []);
        $lastHtml = $this->invokeMeasures(
            $controller,
            $lastRequest,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $this->createEntityManagerMockForMeasuresView(),
            $this->newStripeCheckoutServiceStubForProTier(),
            self::getContainer()->get(\App\Repository\CommercialPlanRepository::class)
        )->getContent();

        self::assertIsString($lastHtml);
        self::assertStringContainsString('Medida 4 de 4', $lastHtml);

        $finalRequest = $this->createRequest([
            'measureId' => (string) $measure4->getId(),
            'field' => 'decision',
            'value' => 'false',
        ]);
        $finalMeasureRepository = $this->createMeasureRepositoryMock([$measure1, $measure2, $measure3, $measure4], $measure4);
        $finalPlanMeasureRepository = $this->createPlanMeasureRepositoryMock($measure4, $planMeasure4);
        $finalEntityManager = $this->createEntityManagerMock($planMeasure4);

        $finalResponse = $this->invokeUpdateSelection(
            $controller,
            $finalRequest,
            $finalMeasureRepository,
            $finalPlanMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $finalEntityManager
        );

        self::assertInstanceOf(JsonResponse::class, $finalResponse);
        $finalData = json_decode((string) $finalResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($finalData['success']);
        self::assertStringContainsString('/backend/plan/measures', (string) $finalData['nextUrl']);
        self::assertStringContainsString('i=3', (string) $finalData['nextUrl']);
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

    private function getControllerWithFeatureGate(ProjectFeatureGate $featureGate): PlanController
    {
        self::bootKernel();
        self::getContainer()->set(ProjectFeatureGate::class, $featureGate);

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

    private function createEntityManagerMock(PlanMeasure $planMeasure): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($planMeasure);
        $entityManager->expects(self::exactly(2))->method('flush');

        return $entityManager;
    }

    private function createEntityManagerMockForIgnoredSelection(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        return $entityManager;
    }

    private function createEntityManagerMockForMeasuresView(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        return $entityManager;
    }

    /**
     * @return array{
     *     0: PlanController,
     *     1: Request,
     *     2: MeasureRepository,
     *     3: PlanMeasureRepository,
     *     4: PlanRepository,
     *     5: SustainabilityPlanBlockAnswerRepository,
     *     6: ActiveProjectService,
     *     7: EntityManagerInterface,
     *     8: PlanMeasure
     * }
     */
    private function buildDecisionScenario(string $decisionValue): array
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

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol);

        $currentMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida actual');
        $this->setEntityId($currentMeasure, 901);

        $nextMeasure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5)
            ->setName('Medida siguiente');
        $this->setEntityId($nextMeasure, 902);

        $currentPlanMeasure = (new PlanMeasure())
            ->setMeasure($currentMeasure)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($currentPlanMeasure);

        $nextPlanMeasure = (new PlanMeasure())
            ->setMeasure($nextMeasure)
            ->setApplicabilitySource('manual');
        $plan->addPlanMeasure($nextPlanMeasure);

        $request = $this->createRequest([
            'measureId' => (string) $currentMeasure->getId(),
            'field' => 'decision',
            'value' => $decisionValue,
        ]);

        $measureRepository = $this->createMeasureRepositoryMock([$currentMeasure, $nextMeasure], $currentMeasure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($currentMeasure, $currentPlanMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);
        $entityManager = $this->createEntityManagerMock($currentPlanMeasure);

        return [
            $controller,
            $request,
            $measureRepository,
            $planMeasureRepository,
            $planRepository,
            $blockAnswerRepository,
            $activeProjectService,
            $entityManager,
            $currentPlanMeasure,
        ];
    }

    /**
     * @return array{
     *     0: PlanController,
     *     1: Project,
     *     2: Plan,
     *     3: Measure,
     *     4: PlanMeasure,
     *     5: MeasureRepository,
     *     6: PlanRepository,
     *     7: PlanMeasureRepository,
     *     8: SustainabilityPlanBlockAnswerRepository,
     *     9: ActiveProjectService
     * }
     */
    private function buildInternalNotesScenario(bool $featureEnabled): array
    {
        self::bootKernel();

        $basicPlan = $this->makeCommercialPlan('basic', [
            'phase' => CommercialPhase::IMPLEMENTATION,
            'features' => array_replace(
                $this->defaultImplementationCommercialPlanDefinition('basic')['features'],
                [
                    'sustainability_plan.internal_notes' => $featureEnabled,
                ]
            ),
        ]);
        $featureGate = $this->makeProjectFeatureGate([
            $this->makeCommercialPlan('basic'),
            $basicPlan,
        ]);
        self::getContainer()->set(ProjectFeatureGate::class, $featureGate);

        $this->setAdminToken();
        /** @var PlanController $controller */
        $controller = self::getContainer()->get(PlanController::class);
        $controller->setContainer(self::getContainer());

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $plan = (new Plan())
            ->setProject($project)
            ->setUser(new User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($measure, 91);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setInternalNotes('original note')
            ->markAsManual();
        $plan->addPlanMeasure($planMeasure);

        $measureRepository = $this->createMeasureRepositoryMock([$measure], $measure);
        $planRepository = $this->createPlanRepositoryMock($plan);
        $planMeasureRepository = $this->createPlanMeasureRepositoryMock($measure, $planMeasure);
        $blockAnswerRepository = self::getContainer()->get(SustainabilityPlanBlockAnswerRepository::class);
        $activeProjectService = $this->createActiveProjectServiceMock($project);

        return [
            $controller,
            $project,
            $plan,
            $measure,
            $planMeasure,
            $measureRepository,
            $planRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $activeProjectService,
        ];
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
        Plan $plan,
        bool $planComplete,
        int $nextIndex
    ): string {
        $reflection = new \ReflectionMethod($controller, 'resolveTerminalSelectionNextUrl');
        $reflection->setAccessible(true);

        /** @var string $url */
        $url = $reflection->invoke($controller, $plan, $planComplete, $nextIndex);

        return $url;
    }

    /**
     * @return array{state: string}
     */
    private function invokeReviewDefaultFilters(PlanController $controller): array
    {
        $reflection = new \ReflectionMethod($controller, 'reviewDefaultFilters');
        $reflection->setAccessible(true);

        /** @var array{state: string} $filters */
        $filters = $reflection->invoke($controller);

        return $filters;
    }

    private function invokeIsReviewInlineFieldAllowed(PlanController $controller, Project $project, string $field): bool
    {
        $reflection = new \ReflectionMethod($controller, 'isReviewInlineFieldAllowed');
        $reflection->setAccessible(true);

        return (bool) $reflection->invoke($controller, $project, $field);
    }

    private function newStripeCheckoutServiceStub(): \App\Service\StripeProjectCheckoutService
    {
        $reflection = new \ReflectionClass(\App\Service\StripeProjectCheckoutService::class);
        /** @var \App\Service\StripeProjectCheckoutService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        return $service;
    }

    private function newStripeCheckoutServiceStubForProTier(): \App\Service\StripeProjectCheckoutService
    {
        $service = $this->newStripeCheckoutServiceStub();
        $resolverReflection = new \ReflectionClass(\App\Service\CommercialPlanResolver::class);
        /** @var \App\Service\CommercialPlanResolver $resolver */
        $resolver = $resolverReflection->newInstanceWithoutConstructor();
        $subscriptionRepository = $this->createMock(\App\Repository\ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProjectAndPhase')->willReturnCallback(
            static fn (\App\Entity\Project $project, CommercialPhase $phase): ?ProjectSubscription => $phase === CommercialPhase::ELABORATION
                ? (new ProjectSubscription())
                    ->setPhase(CommercialPhase::ELABORATION)
                    ->setTier(ProjectSubscription::TIER_PRO)
                : null
        );
        $subscriptionProperty = new \ReflectionProperty($resolver, 'subscriptionRepository');
        $subscriptionProperty->setAccessible(true);
        $subscriptionProperty->setValue($resolver, $subscriptionRepository);

        $featureGate = new ProjectFeatureGate($resolver);
        $reflection = new \ReflectionProperty($service, 'featureGate');
        $reflection->setAccessible(true);
        $reflection->setValue($service, $featureGate);

        return $service;
    }

    private function invokeReview(
        PlanController $controller,
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        \App\Repository\ProtocolRepository $protocolRepository,
        \App\Repository\CommercialPlanRepository $commercialPlanRepository,
        \App\Service\StripeProjectCheckoutService $checkoutService,
        EntityManagerInterface $entityManager,
        \Symfony\Component\Mailer\MailerInterface $mailer,
        \Symfony\Contracts\Translation\TranslatorInterface $translator
    ): Response {
        /** @var Response $response */
        $response = $controller->review(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $protocolRepository,
            $commercialPlanRepository,
            $checkoutService,
            $entityManager,
            $mailer,
            $translator
        );

        return $response;
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

    private function invokeCanAdvanceFromCurrentMeasure(PlanController $controller, PlanMeasure $planMeasure): bool
    {
        $reflection = new \ReflectionMethod($controller, 'canAdvanceFromCurrentMeasure');
        $reflection->setAccessible(true);

        /** @var bool $result */
        $result = $reflection->invoke($controller, $planMeasure);

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

    private function invokeMeasures(
        PlanController $controller,
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        PlanMeasureRepository $planMeasureRepository,
        SustainabilityPlanBlockAnswerRepository $blockAnswerRepository,
        EntityManagerInterface $entityManager,
        \App\Service\StripeProjectCheckoutService $checkoutService,
        \App\Repository\CommercialPlanRepository $commercialPlanRepository
    ): Response {
        /** @var Response $response */
        $response = $controller->measures(
            $request,
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $planMeasureRepository,
            $blockAnswerRepository,
            $entityManager,
            $checkoutService,
            $commercialPlanRepository
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

    private function buildLoadedMeasureState(?bool $isApplicable, ?bool $willImplement, ?bool $isCritical, ?string $criticalReason): PlanMeasure
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
            ->setScore(5)
            ->setName('Medida cargada');
        $this->setEntityId($measure, 9901);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable($isApplicable)
            ->setWillImplement($willImplement)
            ->setIsCritical($isCritical)
            ->setCriticalReason($criticalReason)
            ->markAsManual();

        return $planMeasure;
    }
}
