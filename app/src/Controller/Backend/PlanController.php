<?php

namespace App\Controller\Backend;

use App\Entity\{CommercialPlan, Plan, PlanMeasure, Measure, Ods, EsG, Scope, Project, Protocol, CrewMember, Category, Department, ProjectSubscription, MeasureBlock, SustainabilityPlanBlockAnswer, User};
use App\Enum\CommercialPhase;
use App\Repository\{CommercialPlanRepository, PlanRepository, MeasureRepository, PlanMeasureRepository, ProtocolRepository, SustainabilityPlanBlockAnswerRepository};
use App\Service\PlanMeasureCatalogResolver;
use App\Service\MeasureTaxonomyPresenter;
use App\Service\PlanBlockQuestionService;
use App\Service\SustainabilityPlanCompletionService;
use App\Service\PlanMeasureResumeService;
use App\Service\SustainabilityPlanMeasureOrderer;
use App\Service\SustainabilityPlanCollaborationService;
use App\Service\SustainabilityPlanCustomMeasureService;
use App\Service\PlanMeasureOperationalStateResolver;
use App\Service\SustainabilityCommitmentLevelService;
use App\Service\SustainabilityPlanClosureSummaryService;
use App\Service\SustainabilityPlanClosureEmailRecipientResolver;
use App\Service\SustainabilityGamificationService;
use App\Service\ProjectFeatureGate;
use App\Service\StripeProjectCheckoutService;
use App\Security\PlanVoter;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use Dompdf\{Dompdf, Options};
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/plan', name: 'backend_plan_')]
#[IsGranted('ROLE_USER')]
class PlanController extends AbstractController
{
    private const IMPLEMENTATION_FIELDS = [
        'implemented',
        'verification',
        'action_taken',
        'evidence',
        'evidence_metadata',
        'evidenceMetadata',
        'observations',
        'internalNotes',
        'internal_notes',
        'responsibles',
    ];

    public function __construct(
        private TranslatorInterface $t,
        private PlanMeasureCatalogResolver $catalogResolver,
        private ProjectFeatureGate $featureGate,
        private MeasureTaxonomyPresenter $taxonomyPresenter,
        private PlanMeasureResumeService $resumeService,
        private PlanBlockQuestionService $blockQuestionService,
        private SustainabilityPlanCompletionService $planCompletionService,
        private SustainabilityPlanMeasureOrderer $measureOrderer,
        private SustainabilityPlanCollaborationService $collaborationService,
        private SustainabilityPlanCustomMeasureService $customMeasureService,
        private PlanMeasureOperationalStateResolver $operationalStateResolver,
        private SustainabilityCommitmentLevelService $commitmentLevelService,
        private SustainabilityPlanClosureSummaryService $closureSummaryService,
        private SustainabilityGamificationService $gamificationService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);

        // Si no hay plan o no hay protocolo seleccionado -> ir a Welcome
        if (!$plan || !$plan->getProtocol()) {
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // Si hay plan y está completo -> ir a review, salvo que falte resolver custom measures
        if ($plan && $plan->getStatus() === 'completo') {
            if ($this->shouldShowCustomMeasuresStep($plan)) {
                return $this->redirectToRoute('backend_plan_measures');
            }

            return $this->redirectToRoute('backend_plan_review', $this->reviewDefaultFilters());
        }

        // Con protocolo seleccionado -> ir a Medidas
        return $this->redirectToRoute('backend_plan_measures');
    }

    #[Route('/welcome', name: 'welcome', methods: ['GET'])]
    public function welcome(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        ProtocolRepository $protocolRepository,
        PlanMeasureRepository $planMeasureRepo,
        EntityManagerInterface $em
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            $plan = (new Plan())->setProject($project)->setUser($this->getUser());
            $em->persist($plan);
            $em->flush();
        }

        // Si ya hay alguna medida en el plan, ir a measures directamente
        $alreadyHasMeasures = (bool) $planMeasureRepo->findOneBy(['plan' => $plan]);
        if ($alreadyHasMeasures) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        // Protocolos aplicables al tipo de proyecto (evento/rodaje/ambos)
        $protocolNames = $protocolRepository->getNamesForProjectType($project->getType());
        $protocols = $protocolRepository->findBy(['name' => $protocolNames], ['name' => 'ASC']);

        return $this->render('backend/plan/welcome.html.twig', [
            'project'   => $project,
            'plan'      => $plan,
            'protocols' => $protocols,
        ]);
    }

    #[Route('/select-protocol', name: 'select_protocol', methods: ['POST'])]
    public function selectProtocol(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            $plan = (new Plan())->setProject($project)->setUser($this->getUser());
            $em->persist($plan);
            $em->flush();
        }

        $protocolId = $request->request->get('protocol_id');
        if (!$protocolId) {
            $this->addFlash('warning', 'backend.plan.flash.select_protocol_required');
            return $this->redirectToRoute('backend_plan_welcome');
        }

        /** @var Protocol|null $protocol */
        $protocol = $protocolRepository->find($protocolId);
        if (!$protocol) {
            $this->addFlash('danger', 'backend.plan.flash.invalid_protocol');
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // Persistimos en el plan
        $plan->setProtocol($protocol);
        $em->flush();

        return $this->redirectToRoute('backend_plan_measures');
    }

    #[Route('/measures', name: 'measures', methods: ['GET','POST'])]
    public function measures(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        PlanMeasureRepository $planMeasureRepository,
        SustainabilityPlanBlockAnswerRepository $blockAnswerRepository,
        EntityManagerInterface $em,
        StripeProjectCheckoutService $checkoutService,
        CommercialPlanRepository $commercialPlanRepository
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan || !$plan->getProtocol()) {
            $this->addFlash('info', 'backend.plan.flash.select_protocol_to_continue');
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // VOTER: puede editar el plan (miembro/admin)
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        $protocol     = $plan->getProtocol();
        $groupingBy   = $protocol->getGroupingBy(); // 'category' | 'department'
        $navigationQuery = $request->query->all();
        unset($navigationQuery['i']);
        unset($navigationQuery['only_pending']);
        $canUseCustomMeasures = $this->featureGate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.custom_measures');

        // POST: custom measures interstitial
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');

            if ($action === 'continue_custom_measures') {
                $planComplete = $this->planCompletionService->syncStatus($plan, $project, $measureRepository);
                if (!$planComplete) {
                    $this->addFlash('warning', 'backend.plan.errors.not_complete');

                    return $this->redirectToRoute('backend_plan_measures', $navigationQuery);
                }

                $plan->markCustomMeasuresCompleted();
                $em->flush();

                return $this->redirectToRoute('backend_plan_done');
            }

            if ($action === 'add_custom_measure') {
                if (!$canUseCustomMeasures) {
                    $this->addFlash('info', 'backend.plan.complete.custom_measures_locked');

                    return $this->redirectToRoute('backend_plan_measures', $navigationQuery);
                }

                $title = trim((string) $request->request->get('custom_measure_title', ''));
                $description = trim((string) $request->request->get('custom_measure_description', ''));

                if ($title === '') {
                    $this->addFlash('warning', 'backend.plan.complete.custom_measure_title_required');

                    return $this->redirectToRoute('backend_plan_measures', $navigationQuery);
                }

                $this->customMeasureService->addCustomMeasure(
                    $plan,
                    $project,
                    $this->getUser() instanceof User ? $this->getUser() : null,
                    $title,
                    $description
                );
                $em->flush();

                $this->addFlash('success', 'backend.plan.complete.custom_measure_saved');

                return $this->redirectToRoute('backend_plan_measures', $navigationQuery);
            }
        }

        // Si ya está completo y el paso de custom measures ya se resolvió, dirige a done (resumen)
        $planComplete = $this->planCompletionService->syncStatus($plan, $project, $measureRepository);
        $showCustomMeasuresStep = $this->shouldShowCustomMeasuresStep($plan);
        $em->flush();
        if ($planComplete && !$showCustomMeasuresStep) {
            return $this->redirectToRoute('backend_plan_done');
        }

        // ===== Medidas del protocolo seleccionado (ORDER BY dinámico: categoría o departamento) =====
        $qb = $this->createVisibleMeasuresQueryBuilder($measureRepository, $protocol, $project);

        $allMeasures = $qb->getQuery()->getResult();
        $allVisibleMeasures = $this->measureOrderer->sortVisibleMeasures(
            $this->filterMeasuresBySkippedBlocks($allMeasures, $plan),
            $groupingBy
        );
        $measures = $allVisibleMeasures;

        $total = count($measures);
        $index = $request->query->has('i')
            ? max(0, min(max($total - 1, 0), $request->query->getInt('i', 0)))
            : $this->resumeService->resolveIndex($measures, $plan->getPlanMeasures());
        $currentMeasure = $measures[$index] ?? null;
        $progressIndex = $currentMeasure ? $this->findVisibleMeasureIndex($measures, $currentMeasure) : null;

        // ===== PM actual (si existe) y lógica de navegación =====
        $currentPm = $currentMeasure
            ? $planMeasureRepository->findOneBy(['plan' => $plan, 'measure' => $currentMeasure])
            : null;
        $currentBlockAnswer = ($currentMeasure && $currentMeasure->getMeasureBlock())
            ? $blockAnswerRepository->findOneByPlanAndBlock($plan, (int) $currentMeasure->getMeasureBlock()->getId())
            : null;

        $canGoNext = false;
        if ($currentPm) {
            $canGoNext = $this->canAdvanceFromCurrentMeasure($currentPm);
        }

        // --- Medidas obligatorias: reglas especiales ---
        $mandatory = $currentMeasure ? (bool) $currentMeasure->isMandatory() : false;
        if ($canGoNext && $mandatory) {
            $hasBothTrue = $currentPm
                && $currentPm->isApplicable() === true
                && $currentPm->willImplement() === true;

            if (!$hasBothTrue) {
                $canGoNext = false;
                $this->addFlash('warning', 'backend.plan.measures.mandatory_warning');
            }
        }

        // ===== Gráficos =====
        $visiblePlanMeasures = $this->filterPlanMeasuresBySkippedBlocks($plan->getPlanMeasures(), $plan);

        // ===== Puntuación (no persistente) =====
        $pmIndex = [];
        foreach ($visiblePlanMeasures as $pm) {
            if ($pm->getMeasure()?->getProtocol()?->getId() === $protocol->getId()) {
                $pmIndex[$pm->getMeasure()->getId()] = $pm;
            }
        }

        $scoreMax = 0;
        $scoreGained = 0;
        foreach ($measures as $m) {
            $score = (int) ($m->getScore() ?? 0);
            $scoreMax += $score;

            if ($score > 0 && isset($pmIndex[$m->getId()])) {
                $pm = $pmIndex[$m->getId()];
                if ($pm->isApplicable() === true && $pm->willImplement() === true) {
                    $scoreGained += $score;
                }
            }
        }

        $projectTier = $this->featureGate->getTier($project, CommercialPhase::ELABORATION);
        $evidenceLimit = $this->featureGate->getMaxEvidenceCount($project, CommercialPhase::ELABORATION);
        $evidenceCount = $this->countProjectEvidenceFiles($plan);
        $projectTierLabel = $this->featureGate->getPlanLabel($project, CommercialPhase::ELABORATION);
        $projectTierDisplayLabel = $this->t->trans('backend.plan.tier.level.' . $projectTier);
        $projectTierSummary = $this->featureGate->getPlanDescription($project, CommercialPhase::ELABORATION) ?? $this->t->trans('backend.plan.tier.basic_summary');
        $availableUpgradeTargets = $checkoutService->getAvailableUpgradeTargets($project, CommercialPhase::ELABORATION);
        $upgradeCta = $this->buildUpgradeCta($project, $plan, CommercialPhase::ELABORATION, $projectTier, $availableUpgradeTargets, $commercialPlanRepository, $measureRepository);
        $gamificationMessage = $this->gamificationService->claimPendingMessageForDisplayWithLock(
            $em,
            $plan,
            $project,
            $planComplete ? null : $currentMeasure?->getId()
        );

        // ===== Render =====
        return $this->render('backend/plan/measures.html.twig', [
            'project'          => $project,
            'plan'             => $plan,
            'projectTier'      => $projectTier,
            'projectTierLabel'  => $projectTierLabel,
            'projectTierDisplayLabel' => $projectTierDisplayLabel,
            'projectTierSummary'=> $projectTierSummary,
            'evidenceCount'    => $evidenceCount,
            'evidenceLimit'    => $evidenceLimit,
            'canUseCustomMeasures' => $canUseCustomMeasures,
            'commercialCards'  => $this->buildCommercialFeatureCards($project, CommercialPhase::ELABORATION),
            'hasWatermark'     => $this->featureGate->hasWatermark($project, CommercialPhase::ELABORATION),
            'taxonomyPresenter'=> $this->taxonomyPresenter,
            'upgradeCta'       => $upgradeCta,
            'collaborationSummary' => $this->collaborationService->buildProgressSummary($plan, $project),
            'commitmentSummary' => $this->commitmentLevelService->buildSummary($plan, $project),
            'customMeasures'   => $this->collaborationService->getCustomMeasures($plan),
            'navigationQuery'  => $navigationQuery,
            'showCustomMeasuresStep' => $showCustomMeasuresStep,
            'gamificationMessage' => $gamificationMessage,

            // navegación y medida actual
            'index'            => $index,
            'progressIndex'    => $progressIndex,
            'total'            => $total,
            'measure'          => $planComplete ? null : $currentMeasure,
            'planMeasures'     => $visiblePlanMeasures,
            'canGoNext'        => !$planComplete && $canGoNext,
            'planComplete'     => $planComplete,
            'currentBlockAnswer' => $currentBlockAnswer,

            // puntuación
            'scoreGained'      => $scoreGained,
            'scoreMax'         => $scoreMax,
        ]);
    }

    #[Route('/done', name: 'done', methods: ['GET'])]
    public function done(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        StripeProjectCheckoutService $checkoutService,
        CommercialPlanRepository $commercialPlanRepository,
        MeasureRepository $measureRepository,
        EntityManagerInterface $em,
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) return $this->redirectToRoute('app_backend');

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            return $this->redirectToRoute('backend_plan_welcome');
        }

        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        if ($plan instanceof Plan && $this->shouldShowCustomMeasuresStep($plan)) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        if ($plan->getStatus() !== 'completo') {
            return $this->redirectToRoute('backend_plan_measures');
        }

        $implementationPhase = CommercialPhase::IMPLEMENTATION;
        $elaborationPhase = CommercialPhase::ELABORATION;
        $implementationTier = $this->featureGate->getTier($project, $implementationPhase);
        $elaborationTier = $this->featureGate->getTier($project, $elaborationPhase);
        $closureSummary = $this->closureSummaryService->buildSummary($plan, $project);
        $closureFeatures = [
            'unifiedPdf' => $this->featureGate->getFeatureState($project, $elaborationPhase, 'sustainability_plan.unified_pdf'),
            'departmentPdf' => $this->featureGate->getFeatureState($project, $elaborationPhase, 'sustainability_plan.export.department_pdf'),
            'tripleBalancePdf' => $this->featureGate->getFeatureState($project, $elaborationPhase, 'sustainability_plan.export.triple_balance'),
            'odsPdf' => $this->featureGate->getFeatureState($project, $elaborationPhase, 'sustainability_plan.export.ods'),
            'impactAreaPdf' => $this->featureGate->getFeatureState($project, $elaborationPhase, 'sustainability_plan.export.impact_area'),
            'excel' => $this->featureGate->getFeatureState($project, $elaborationPhase, 'sustainability_plan.export.excel'),
            'email' => $this->featureGate->getFeatureState($project, $elaborationPhase, 'sustainability_plan.export.email'),
        ];
        $elaborationUpgradeCta = $this->buildUpgradeCta(
            $project,
            $plan,
            $elaborationPhase,
            $elaborationTier,
            $checkoutService->getAvailableUpgradeTargets($project, $elaborationPhase),
            $commercialPlanRepository,
            $measureRepository
        );
        $implementationUpgradeCta = $this->buildUpgradeCta(
            $project,
            $plan,
            $implementationPhase,
            $implementationTier,
            $checkoutService->getAvailableUpgradeTargets($project, $implementationPhase),
            $commercialPlanRepository,
            $measureRepository
        );
        $hasImplementationActivity = $this->collaborationService->hasImplementationActivity($plan);
        $gamificationMessage = $this->gamificationService->claimPendingMessageForDisplayWithLock(
            $em,
            $plan,
            $project,
            null
        );

        return $this->render('backend/plan/done.html.twig', [
            'project' => $project,
            'plan' => $plan,
            'elaborationTier' => $elaborationTier,
            'elaborationTierLabel' => $this->t->trans('backend.plan.tier.level.' . $elaborationTier),
            'closureSummary' => $closureSummary,
            'closureFeatures' => $closureFeatures,
            'elaborationUpgradeCta' => $elaborationUpgradeCta,
            'implementationTier' => $implementationTier,
            'implementationTierLabel' => $this->t->trans('backend.plan.tier.level.' . $implementationTier),
            'hasImplementationActivity' => $hasImplementationActivity,
            'implementationUpgradeCta' => $implementationUpgradeCta,
            'gamificationMessage' => $gamificationMessage,
            'crewMembers' => $project->getCrewMembers(),
        ]);
    }

    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function deletePlan(
        Project $project,
        Request $request,
        PlanRepository $planRepository,
        EntityManagerInterface $em
    ): Response {
        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            $this->addFlash('info', 'backend.plan.flash.no_plan_for_project');
            return $this->redirectToRoute('backend_project_index');
        }

        // VOTER: solo miembros pueden editar/borrar el plan
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        if (!$this->isCsrfTokenValid('delete_plan_' . $plan->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($plan->getStatus() !== 'incompleto' || $this->collaborationService->hasImplementationActivity($plan)) {
            $this->addFlash('danger', 'backend.plan.flash.delete_forbidden');

            return $this->redirectToRoute('backend_project_index');
        }

        try {
            $em->remove($plan);
            $em->flush();

            $this->addFlash('success', 'backend.plan.flash.deleted');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'backend.plan.flash.delete_failed');
        }

        return $this->redirectToRoute('backend_project_index');
    }

    #[Route('/review', name: 'review', methods: ['GET','POST'])]
    public function review(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        CommercialPlanRepository $commercialPlanRepository,
        StripeProjectCheckoutService $checkoutService,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        TranslatorInterface $translator
    ): Response {
        // Proyecto activo
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }
        // Permiso de acceso al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        // Debe existir plan
        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // Permiso de acceso al plan
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        $previousStatus = $plan->getStatus();
        $this->planCompletionService->syncStatus($plan, $project, $measureRepository);
        $em->flush();

        if ($this->shouldShowCustomMeasuresStep($plan)) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        // Debe estar completo
        if ($plan->getStatus() !== 'completo') {
            $this->addFlash('info', 'backend.plan.errors.not_complete');

            if ($previousStatus === 'completo') {
                $pendingMeasure = $this->findFirstPendingVisibleMeasure($plan, $project, $measureRepository);
                if ($pendingMeasure !== null && isset($pendingMeasure['index'])) {
                    return $this->redirectToRoute('backend_plan_measures', ['i' => $pendingMeasure['index']]);
                }
            }

            return $this->redirectToRoute('backend_plan_measures');
        }

        // Protocolos válidos para el tipo
        $protocols = $protocolRepository->getNamesForProjectType($project->getType());

        // --- Filtros por GET ---
        $protocol         = $request->query->get('protocol');
        $category         = $request->query->get('category');
        $department       = $request->query->get('department');
        $ods              = $request->query->get('ods');
        $impactArea       = $request->query->get('impact_area');
        $tripleBalance    = $request->query->get('triple_balance_axis');
        $scope            = $request->query->get('scope');
        $esg              = $request->query->get('esg');
        $isApplicable     = $request->query->get('is_applicable');
        $willImplement    = $request->query->get('will_implement');
        $pendingSelection = $request->query->get('pending_selection');
        $onlyImplemented  = $request->query->get('only_implemented');
        $openId           = $request->query->getInt('open', 0);
        $isCritical       = $request->query->get('is_critical');
        $state            = $request->query->get('state');

        $allowedStates = [
            PlanMeasureOperationalStateResolver::ALL,
            PlanMeasureOperationalStateResolver::PENDING,
            PlanMeasureOperationalStateResolver::IN_PROGRESS,
            PlanMeasureOperationalStateResolver::IMPLEMENTED,
            PlanMeasureOperationalStateResolver::DISCARDED,
            PlanMeasureOperationalStateResolver::NOT_APPLICABLE,
        ];
        if (!is_string($state) || !in_array($state, $allowedStates, true)) {
            $state = PlanMeasureOperationalStateResolver::PENDING;
        }

        $paginationQuery = $request->query->all();
        unset($paginationQuery['open']);
        if (!array_key_exists('is_applicable', $paginationQuery) && $isApplicable !== null && $isApplicable !== '') {
            $paginationQuery['is_applicable'] = $isApplicable;
        }
        if (!array_key_exists('will_implement', $paginationQuery) && $willImplement !== null && $willImplement !== '') {
            $paginationQuery['will_implement'] = $willImplement;
        }

        $page    = max(1, (int)$request->query->get('page', 1));
        $perPage = 10;

        // START Número medida
        $baseQb = $measureRepository->createQueryBuilder('m')
            ->select('m.id AS id')
            ->join('m.protocol', 'p');
        $this->catalogResolver->applyCatalogFilter($baseQb, 'm', 'p', $project);
        if (!$protocol) {
            $baseQb->andWhere('p.name IN (:protocols)')->setParameter('protocols', $protocols);
        } else {
            $baseQb->andWhere('p.name = :protocol')->setParameter('protocol', $protocol);
        }
        $baseQb->orderBy('m.id', 'ASC');
        $baseIdsRows = $baseQb->getQuery()->getScalarResult();
        $positionById = [];
        foreach ($baseIdsRows as $idx => $row) {
            $id = (int) $row['id'];
            $positionById[$id] = $idx + 1;
        }
        // END Número medida

        // Query de Measure filtrada
        $qb = $measureRepository->createQueryBuilder('m')
            ->join('m.protocol', 'p')
            ->leftJoin('m.category', 'c')
            ->leftJoin('m.planMeasures', 'pm', 'WITH', 'pm.plan = :plan')
            ->setParameter('plan', $plan)
            ->andWhere('p = :protocol')
            ->setParameter('protocol', $plan->getProtocol())
            ->addSelect(
            "CASE
                WHEN pm.isApplicable = true AND pm.willImplement = true AND pm.isCritical = true THEN 1
                WHEN pm.isApplicable = true AND pm.willImplement = true THEN 2
                WHEN pm.isApplicable = true AND pm.willImplement = false THEN 3
                WHEN pm.isApplicable = false THEN 4
                ELSE 5
            END AS HIDDEN rank"
        );
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p', $project);
        $measureRepository->applyPlanTaxonomyFilters($qb, [
            'category' => $category,
            'department' => $department,
            'ods' => $ods,
            'impact_area' => $impactArea,
            'triple_balance_axis' => $tripleBalance,
            'scope' => $scope,
            'esg' => $esg,
        ]);

        // Orden por ranking y luego por nombre de la medida
        // Nombre para orden secundario: nameReview si existe, si no name
        $qb->addSelect("
            CASE
                WHEN m.nameReview IS NULL OR m.nameReview = '' THEN m.name
                ELSE m.nameReview
            END AS HIDDEN sortName
        ");

        // Orden final
        $qb->addOrderBy('rank', 'ASC')
        ->addOrderBy('sortName', 'ASC');

        if ($isCritical) {
            $qb->andWhere('pm.isCritical = true');
        }

        $planMeasuresByMeasureId = [];
        foreach ($plan->getPlanMeasures() as $planMeasure) {
            $planMeasureId = $planMeasure->getMeasure()?->getId();
            if ($planMeasureId !== null) {
                $planMeasuresByMeasureId[(int) $planMeasureId] = $planMeasure;
            }
        }

        // La fase de implementación debe incluir los block_skip como "No aplica".
        $allMeasures = array_values(array_filter(
            $qb->getQuery()->getResult(),
            fn (Measure $measure): bool => isset($planMeasuresByMeasureId[(int) $measure->getId()])
                && $this->operationalStateResolver->matches($planMeasuresByMeasureId[(int) $measure->getId()], $state)
        ));
        $total = count($allMeasures);

        $page    = max(1, (int)$request->query->get('page', 1));
        $perPage = 10;
        $offset  = ($page - 1) * $perPage;
        $measures = array_slice($allMeasures, $offset, $perPage);

        // Datos para gráficos (WEB, con filtros)
        $filtersArr = [
            'protocol'          => $protocol,
            'category'          => $category,
            'department'        => $department,
            'ods'               => $ods,
            'impact_area'       => $impactArea,
            'triple_balance_axis'=> $tripleBalance,
            'scope'             => $scope,
            'esg'               => $esg,
            'is_applicable'     => $isApplicable,
            'will_implement'    => $willImplement,
            'pending_selection' => $pendingSelection,
            'only_implemented'  => $onlyImplemented,
            'state'             => $state,
        ];
        $filteredPlanMeasures = $this->getFilteredPlanMeasures($plan, $project, $filtersArr);

        $effective = 0; $nonApplicable = 0; $agreed = 0; $implemented = 0;
        foreach ($filteredPlanMeasures as $pm) {
            if ($pm->isApplicable() === true)  $effective++;
            if ($pm->isApplicable() === false) $nonApplicable++;
            if ($pm->willImplement())          $agreed++;
            if ($pm->isImplemented())          $implemented++;
        }

        $measuresTotal = (int) $total;

        $planChartsConfig = $this->buildReviewChartsConfig(
            $filteredPlanMeasures,
            $plan->getProtocol()?->getId(),
            $plan->getPlanMeasures()->toArray()
        );

        // Puntuación total y ganada para el protocolo del plan
        $scoreMax = 0;
        $scoreGained = 0;

        foreach ($plan->getPlanMeasures() as $pm) {
            $m = $pm->getMeasure();
            if (!$m || !$this->catalogResolver->isCatalogMeasure($m, $project)) {
                continue;
            }
            if ($m->getProtocol()?->getId() !== $plan->getProtocol()?->getId()) continue;

            $score = $m->getScore();
            if ($score !== null) {
                $scoreMax += (int) $score;
                if ($pm->isApplicable() === true && $pm->willImplement() === true) {
                    $scoreGained += (int) $score;
                }
            }
        }

        $uiLocale = $request->getLocale();
        $reviewPhase = CommercialPhase::IMPLEMENTATION;
        $projectTier = $this->featureGate->getTier($project, $reviewPhase);
        $projectTierLabel = $this->t->trans('backend.plan.tier.level.' . $projectTier);
        $projectTierSummary = $this->featureGate->getPlanDescription($project, $reviewPhase) ?? $this->t->trans('backend.plan.tier.basic_summary');
        $availableUpgradeTargets = $checkoutService->getAvailableUpgradeTargets($project, $reviewPhase);
        $upgradeCta = $this->buildUpgradeCta($project, $plan, $reviewPhase, $projectTier, $availableUpgradeTargets, $commercialPlanRepository, $measureRepository);

        return $this->render('backend/plan/review.html.twig', [
            'project'          => $project,
            'plan'             => $plan,
            'projectTier'      => $projectTier,
            'projectTierLabel'  => $projectTierLabel,
            'projectTierSummary'=> $projectTierSummary,
            'canUseChecklist'  => $this->featureGate->canUseFeature($project, $reviewPhase, 'sustainability_plan.checklist'),
            'canUseResponsibles'=> $this->featureGate->canUseFeature($project, $reviewPhase, 'sustainability_plan.responsibles'),
            'canUseInternalNotes'=> $this->featureGate->canUseFeature($project, $reviewPhase, 'sustainability_plan.internal_notes'),
            'evidenceCount'    => $this->countProjectEvidenceFiles($plan),
            'evidenceLimit'    => $this->featureGate->getMaxEvidenceCount($project, $reviewPhase),
            'commercialCards'  => $this->buildCommercialFeatureCards($project, $reviewPhase),
            'upgradeCta'       => $upgradeCta,
            'hasWatermark'     => $this->featureGate->hasWatermark($project, $reviewPhase),
            'taxonomyPresenter'=> $this->taxonomyPresenter,
            'collaborationSummary' => $this->collaborationService->buildProgressSummary($plan, $project),
            'commitmentSummary' => $this->commitmentLevelService->buildSummary($plan, $project),
            'customMeasures'   => $this->collaborationService->getCustomMeasures($plan),
            'crewMembersByMeasure' => $this->buildCrewMembersByMeasure($plan, $project),
            'planMeasures'     => $plan->getPlanMeasures(),
            'measures'         => $measures,
            'currentPage'      => $page,
            'totalPages'       => max(1, (int)ceil($total / $perPage)),
            'offset'           => $offset,
            'perPage'          => $perPage,
            'positionById'     => $positionById,
            'paginationQuery'  => $paginationQuery,
            'filters'          => [
                'protocol'          => $protocol,
                'category'          => $category,
                'department'        => $department,
                'ods'               => $ods,
                'impact_area'       => $impactArea,
                'triple_balance_axis'=> $tripleBalance,
                'scope'             => $scope,
                'esg'               => $esg,
                'is_applicable'     => $isApplicable,
                'will_implement'    => $willImplement,
                'pending_selection' => $pendingSelection,
                'only_implemented'  => $onlyImplemented,
                'is_critical'       => $isCritical,
                'state'             => $state,
            ],
            'protocols'        => $protocols,
            'categories'       => $measureRepository->getCategories($project, $uiLocale),
            'departments'      => $measureRepository->getDepartments($project, $uiLocale),
            'odsList'          => $measureRepository->getOds($project, $uiLocale),
            'impactAreas'      => $measureRepository->getImpactAreas($project, $uiLocale),
            'tripleBalanceAxes'=> $measureRepository->getTripleBalanceAxes($project, $uiLocale),
            'scopeList'        => $measureRepository->getScopes($project, $uiLocale),
            'esgList'          => $em->getRepository(EsG::class)->findAll(),
            'planChartsConfig' => $planChartsConfig,
            'scoreMax'         => $scoreMax,
            'scoreGained'      => $scoreGained,
            'openId'           => $openId,
            'operationalStateResolver' => $this->operationalStateResolver,
        ]);
    }

    #[Route('/update-selection', name: 'update_selection', methods: ['POST'])]
    public function updateSelection(
        Request $request,
        MeasureRepository $measureRepo,
        PlanMeasureRepository $planMeasureRepo,
        PlanRepository $planRepo,
        SustainabilityPlanBlockAnswerRepository $blockAnswerRepository,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        $project = $activeProjectService->getActiveProject();
        if (!$user || !$project) {
            return new JsonResponse(['success' => false, 'error' => 'No project or user'], 400);
        }

        // ⛔ Permiso mínimo para acceder al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $measureId = $request->request->get('measureId');
        $field     = $request->request->get('field');
        $value     = $request->request->get('value');

        if (!$measureId || !$field) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
        }

        /** @var Measure|null $measure */
        $measure = $measureRepo->find($measureId);
        if (!$measure) {
            return new JsonResponse(['success' => false, 'error' => 'Measure not found'], 404);
        }
        if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
            return new JsonResponse(['success' => false, 'error' => 'Feature not available for current plan tier'], 403);
        }

        if (!$this->isReviewInlineFieldAllowed($project, $field)) {
            return new JsonResponse(['success' => true, 'nextUrl' => null]);
        }

        // Asegura Plan
        $plan = $planRepo->findOneBy(['project' => $project]);
        if ($this->isImplementationField($field) && (!$plan instanceof Plan || $plan->getStatus() !== 'completo')) {
            return $this->implementationNotReadyResponse();
        }

        if (!$plan) {
            // Vas a CREAR plan -> requiere EDIT del proyecto
            $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

            $plan = new Plan();
            $plan->setProject($project);
            $plan->setUser($user);
            $em->persist($plan);
            $em->flush();
        }

        // A partir de aquí, vas a modificar datos del plan -> requiere EDIT del plan
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        // Asegura PlanMeasure
        $planMeasure = $planMeasureRepo->findOneBy(['plan' => $plan, 'measure' => $measure]);
        if (!$planMeasure) {
            $planMeasure = new PlanMeasure();
            $plan->addPlanMeasure($planMeasure);
            $planMeasure->setMeasure($measure);
            // Deja el resto en NULL hasta respuesta explícita del usuario
        }

        $samePrimaryDecision = $field === 'decision'
            && $planMeasure->getPrimaryDecision() === (string) $value;
        $gamificationBefore = $field === 'decision' && !$samePrimaryDecision
            ? $this->gamificationService->captureTransition($plan, $project, $planMeasure)
            : null;

        // --- Mutaciones por campo ---
        $nextUrl = null;
        $implementedError = null;

        switch ($field) {
            case 'blockQuestion':
                $block = $measure->getMeasureBlock();
                if (!$block || !$block->hasScreeningQuestion()) {
                    return new JsonResponse(['success' => false, 'error' => 'Unknown field'], 400);
                }

                $applies = filter_var((string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($applies === null) {
                    return new JsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
                }

                $currentMeasureId = $measure->getId();
                if ($currentMeasureId === null) {
                    return new JsonResponse([
                        'success' => false,
                        'error'   => $this->t->trans('backend.plan.flash.validation_unexpected_error'),
                    ], 409);
                }

                $visibleMeasuresBefore = $this->planCompletionService->getVisibleMeasures($plan, $project, $measureRepo);
                $visibleIndexByMeasureId = [];
                foreach ($visibleMeasuresBefore as $visibleIndex => $visibleMeasure) {
                    $visibleMeasureId = $visibleMeasure->getId();
                    if ($visibleMeasureId !== null) {
                        $visibleIndexByMeasureId[(int) $visibleMeasureId] = (int) $visibleIndex;
                    }
                }

                if (!isset($visibleIndexByMeasureId[(int) $currentMeasureId])) {
                    return new JsonResponse([
                        'success' => false,
                        'error'   => $this->t->trans('backend.plan.flash.validation_unexpected_error'),
                    ], 409);
                }

                $currentIndex = $visibleIndexByMeasureId[(int) $currentMeasureId];
                $blockMeasures = array_values(array_filter(
                    $visibleMeasuresBefore,
                    static fn (Measure $candidate) => $candidate->getMeasureBlock()?->getId() === $block->getId()
                ));
                $this->blockQuestionService->applyAnswer($plan, $block, $applies, $user instanceof User ? $user : null, $blockMeasures);

                if ($applies) {
                    $nextUrl = $this->generateUrl('backend_plan_measures', ['i' => $currentIndex]);
                } else {
                    $visibleMeasuresAfter = $this->planCompletionService->getVisibleMeasures($plan, $project, $measureRepo);
                    $nextVisibleMeasureIndex = null;

                    foreach ($visibleMeasuresAfter as $visibleIndex => $visibleMeasure) {
                        $visibleMeasureId = $visibleMeasure->getId();
                        if ($visibleMeasureId === null) {
                            continue;
                        }

                        $originalIndex = $visibleIndexByMeasureId[(int) $visibleMeasureId] ?? null;
                        if (!is_int($originalIndex) || $originalIndex <= $currentIndex) {
                            continue;
                        }

                        $nextVisibleMeasureIndex = (int) $visibleIndex;
                        break;
                    }

                    if ($nextVisibleMeasureIndex !== null) {
                        $nextUrl = $this->generateUrl('backend_plan_measures', ['i' => $nextVisibleMeasureIndex]);
                    } elseif ($this->planCompletionService->isComplete($plan, $project, $measureRepo)) {
                        $nextUrl = $this->resolveTerminalSelectionNextUrl($plan, true, $currentVisibleMeasureIndex ?? 0);
                    } else {
                        return new JsonResponse([
                            'success' => false,
                            'error'   => $this->t->trans('backend.plan.flash.validation_unexpected_error'),
                        ], 409);
                    }
                }
                break;

            case 'isApplicable':
                $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                $planMeasure->setIsApplicable($bool);
                $planMeasure->markAsManual();
                if ($bool === false) {
                    $planMeasure->setIsCritical(null);
                    $planMeasure->setCriticalReason(null);
                    $planMeasure->setWillImplement(null);
                    $planMeasure->setImplemented(null);
                }
                break;

            case 'decision':
                $decision = (string) $value;
                if ($samePrimaryDecision) {
                    break;
                }

                if ($decision === 'true' || $decision === 'false') {
                    $planMeasure->setIsApplicable(true);
                    $planMeasure->setWillImplement($decision === 'true');
                    $planMeasure->setIsCritical(null);
                    $planMeasure->setCriticalReason(null);
                    $planMeasure->setImplemented(null);
                    $planMeasure->markAsManual();
                    break;
                }

                if ($decision === 'na') {
                    $planMeasure->setIsApplicable(false);
                    $planMeasure->setIsCritical(null);
                    $planMeasure->setCriticalReason(null);
                    $planMeasure->setWillImplement(null);
                    $planMeasure->setImplemented(null);
                    $planMeasure->markAsManual();
                    break;
                }

                return new JsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);

            case 'isCritical':
            case 'critical':
                $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                $planMeasure->setIsCritical($bool);
                $planMeasure->markAsManual();
                if ($bool === false) {
                    $planMeasure->setCriticalReason(null);
                }
                break;

            case 'criticalReason':
            case 'critical_reason':
                $text = trim((string)($value ?? ''));
                if ($response = $this->validateCriticalReasonField($planMeasure, $text)) {
                    return $response;
                }
                $planMeasure->setCriticalReason($text !== '' ? $text : null);
                $planMeasure->markAsManual();
                break;

            case 'willImplement':
                // Solo permitir elegir implementar si aplica === true y la crítica fue respondida (null=no respondida)
                if ($planMeasure->isApplicable() === true && $planMeasure->isCritical() !== null) {
                    if ($response = $this->validateCriticalReasonBeforeImplementing($planMeasure)) {
                        return $response;
                    }
                    $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                    $planMeasure->setWillImplement($bool);
                    $planMeasure->markAsManual();
                }
                break;

            case 'implemented':
                // Solo permitir marcar implementado si la medida estaba marcada para implementar
                if ($planMeasure->willImplement() !== true) {
                    return new JsonResponse([
                        'success' => false,
                        'error'   => 'Solo puedes marcar como implementada una medida marcada para implementar.',
                    ], 400);
                }
                $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                if ($bool === true && !$planMeasure->canBeMarkedAsImplemented()) {
                    $planMeasure->setImplemented(false);
                    $planMeasure->markAsManual();
                    $implementedError = $this->implementedRequirementsMessage();
                    break;
                }

                $planMeasure->setImplemented($bool);
                $planMeasure->markAsManual();
                break;

            case 'verification':
                $bool = ($value === 'true');
                $planMeasure->setVerification($bool);
                $planMeasure->markAsManual();
                break;

            case 'action_taken':
                $text = trim((string)$value);
                $planMeasure->setActionTaken($text !== '' ? $text : null);
                $planMeasure->markAsManual();
                $planMeasure->normalizeImplementedState();
                break;

            case 'evidence':
                $text = trim((string)$value);
                $planMeasure->setEvidence($text !== '' ? $text : null);
                $planMeasure->markAsManual();
                $planMeasure->normalizeImplementedState();
                break;

            case 'evidence_metadata':
            case 'evidenceMetadata':
                $rawMetadata = json_decode((string) $value, true);
                if (!is_array($rawMetadata)) {
                    $rawMetadata = [];
                }

                $currentEvidencePaths = array_fill_keys($planMeasure->getEvidencePaths(), true);

                $allowedSourceCodes = [];
                foreach ($measure->getResolvedVerificationSourceLinks() as $link) {
                    $sourceCode = $link->getVerificationSource()?->getCode();
                    if (is_string($sourceCode) && trim($sourceCode) !== '') {
                        $allowedSourceCodes[$sourceCode] = true;
                    }
                }

                $metadata = [];
                foreach ($rawMetadata as $path => $sourceCode) {
                    $path = trim((string) $path);
                    $sourceCode = trim((string) $sourceCode);
                    if ($path === '' || $sourceCode === '' || !isset($allowedSourceCodes[$sourceCode]) || !isset($currentEvidencePaths[$path])) {
                        continue;
                    }

                    $metadata[$path] = $sourceCode;
                }

                $planMeasure->setEvidenceMetadata($metadata);
                $planMeasure->markAsManual();
                $planMeasure->normalizeImplementedState();
                break;

            case 'observations':
                $text = trim((string)$value);
                $planMeasure->setObservations($text !== '' ? $text : null);
                $planMeasure->markAsManual();
                break;

            case 'internalNotes':
            case 'internal_notes':
                $text = trim((string)$value);
                $planMeasure->setInternalNotes($text !== '' ? $text : null);
                $planMeasure->markAsManual();
                break;

            case 'responsibles':
                $ids = [];
                $decoded = json_decode((string) $value, true);
                if (is_array($decoded)) {
                    $ids = array_map('intval', $decoded);
                } else {
                    $ids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', (string) $value) ?: [])));
                }

                $crewRepo = $em->getRepository(CrewMember::class);
                $crewMembers = [];
                if ($ids !== []) {
                    $crewMembers = $crewRepo->createQueryBuilder('c')
                        ->andWhere('c.project = :project')
                        ->andWhere('c.id IN (:ids)')
                        ->setParameter('project', $project)
                        ->setParameter('ids', array_values(array_unique($ids)))
                        ->getQuery()
                        ->getResult();
                }

                $this->collaborationService->syncResponsibleCrewMembers($planMeasure, $crewMembers);
                $planMeasure->markAsManual();
                break;

            default:
                return new JsonResponse(['success' => false, 'error' => 'Unknown field'], 400);
        }

        $em->persist($planMeasure);
        $em->flush();

        if ($gamificationBefore !== null && !$samePrimaryDecision) {
            $this->gamificationService->evaluateWithLock(
                $em,
                $plan,
                $project,
                $planMeasure,
                $gamificationBefore,
                (string) $field
            );
        }

        // Estado del plan
        $complete = $this->planCompletionService->syncStatus($plan, $project, $measureRepo);
        $em->flush();

        if ($implementedError !== null) {
            return new JsonResponse([
                'success' => false,
                'error'   => $implementedError,
                'implemented' => $planMeasure->isImplemented(),
            ], 400);
        }

        if ($this->isTerminalSelectionAction($field, $value)) {
            $visibleMeasures = $this->planCompletionService->getVisibleMeasures($plan, $project, $measureRepo);
            $currentVisibleIndex = $this->planCompletionService->findVisibleMeasureIndex($visibleMeasures, $measure);
            if ($currentVisibleIndex === null) {
                return new JsonResponse([
                    'success' => false,
                    'error'   => $this->t->trans('backend.plan.flash.validation_unexpected_error'),
                ], 409);
            }

            $isLastVisibleMeasure = $currentVisibleIndex >= (count($visibleMeasures) - 1);
            if (!$isLastVisibleMeasure) {
                $nextUrl = $this->generateUrl('backend_plan_measures', ['i' => $currentVisibleIndex + 1]);
            } elseif ($complete) {
                $nextUrl = $this->resolveTerminalSelectionNextUrl($plan, true, $currentVisibleIndex + 1);
            } else {
                $pendingMeasure = $this->planCompletionService->findFirstPendingVisibleMeasure($plan, $project, $measureRepo);
                if ($pendingMeasure !== null) {
                    $pendingIndex = $pendingMeasure['index'] ?? null;
                    if (!is_int($pendingIndex) || $pendingIndex < 0) {
                        return new JsonResponse([
                            'success' => false,
                            'error'   => $this->t->trans('backend.plan.flash.validation_unexpected_error'),
                        ], 409);
                    }

                    $this->addFlash(
                        'warning',
                        $this->pendingMeasureFlashMessage((string) ($pendingMeasure['reason'] ?? ''))
                    );

                    $nextUrl = $this->generateUrl('backend_plan_measures', ['i' => $pendingIndex]);
                } else {
                    return new JsonResponse([
                        'success' => false,
                        'error'   => $this->t->trans('backend.plan.flash.validation_unexpected_error'),
                    ], 409);
                }
            }
        }

        return new JsonResponse([
            'success' => true,
            'nextUrl' => $nextUrl,
            'unchangedDecision' => $samePrimaryDecision,
            'implemented' => $planMeasure->isImplemented(),
        ]);
    }

    /**
     * @param CrewMember[] $members
     * @return array{0:int, 1:int}
     */
    private function sendPlanPdfEmails(
        array $members,
        string $pdfBytes,
        string $filename,
        Project $project,
        MailerInterface $mailer,
        TranslatorInterface $translator
    ): array {
        $from = $this->getParameter('app.mail_from') ?? 'no-reply@begreenmyfriend.local';
        $projectName = (string) $project->getName();
        $subject = $translator->trans('backend.plan.email.subject', ['%project%' => $projectName]);
        $ok = 0;
        $fail = 0;

        foreach ($members as $member) {
            $to = trim((string) $member->getEmail());
            if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                $fail++;
                continue;
            }

            $displayName = trim((string) ($member->getName() ?? ''));
            if ($displayName === '') {
                $displayName = strtok($to, '@') ?: $to;
            }

            $greeting = $translator->trans('backend.plan.email.greeting', ['%name%' => $displayName]);
            $intro = $translator->trans('backend.plan.email.intro', ['%project%' => $projectName]);
            $closing = $translator->trans('backend.plan.email.closing');
            $plain = $greeting . "\n\n" . $intro . "\n\n" . $closing;
            $html = sprintf(
                '<p>%s</p><p>%s</p><p>%s</p>',
                htmlspecialchars($greeting, ENT_QUOTES),
                htmlspecialchars($intro, ENT_QUOTES),
                htmlspecialchars($closing, ENT_QUOTES)
            );

            try {
                $mailer->send(
                    (new Email())
                        ->from($from)
                        ->to($to)
                        ->subject($subject)
                        ->text($plain)
                        ->html($html)
                        ->attach($pdfBytes, $filename, 'application/pdf')
                );
                $ok++;
            } catch (\Throwable) {
                $fail++;
            }
        }

        return [$ok, $fail];
    }

    private function buildEmailAttachmentFilename(Project $project, TranslatorInterface $translator): string
    {
        $slugger = new AsciiSlugger();
        $projectName = (string) $project->getName();
        $projectSlug = $slugger->slug(mb_substr($projectName, 0, 60))->lower();
        $basename = $translator->trans('backend.plan.email.attachment_basename', [
            '%project%' => $projectName,
            '%project_slug%' => $projectSlug,
            '%date%' => (new \DateTimeImmutable())->format('Y-m-d'),
        ]);

        return (string) $slugger->slug($basename)->lower() . '.pdf';
    }

    private function isReviewInlineFieldAllowed(Project $project, string $field): bool
    {
        if ($field === 'decision') {
            return true;
        }

        $fieldFeatureMap = [
            'verification' => 'sustainability_plan.checklist',
            'responsibles' => 'sustainability_plan.responsibles',
            'internalNotes' => 'sustainability_plan.internal_notes',
            'internal_notes' => 'sustainability_plan.internal_notes',
            'evidence_metadata' => 'sustainability_plan.evidence_upload',
            'evidenceMetadata' => 'sustainability_plan.evidence_upload',
        ];

        if (!isset($fieldFeatureMap[$field])) {
            return true;
        }

        return $this->featureGate->canUseFeature($project, CommercialPhase::IMPLEMENTATION, $fieldFeatureMap[$field]);
    }

    private function isImplementationField(string $field): bool
    {
        return in_array($field, self::IMPLEMENTATION_FIELDS, true);
    }

    private function implementationNotReadyResponse(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $this->t->trans('backend.plan.errors.implementation_requires_completed_elaboration'),
        ], 409);
    }


    /**
     * Devuelve true si TODAS las medidas del protocolo seleccionado están contestadas:
     * - Si NO aplica: no requiere más.
     * - Si SÍ aplica: requiere crítica (true|false) y willImplement (true|false).
     */
    private function isPlanCompleteForProtocol(
        Plan $plan,
        Project $project,
        MeasureRepository $measureRepository
    ): bool {
        return $this->planCompletionService->isComplete($plan, $project, $measureRepository);
    }

    private function hasPendingVisibleMeasures(
        Plan $plan,
        Project $project,
        MeasureRepository $measureRepository
    ): bool {
        return $this->planCompletionService->findFirstPendingVisibleMeasure($plan, $project, $measureRepository) !== null;
    }

    /**
     * @return Measure[]
     */
    private function getVisibleMeasuresForProtocol(Plan $plan, Project $project, MeasureRepository $measureRepository): array
    {
        return $this->planCompletionService->getVisibleMeasures($plan, $project, $measureRepository);
    }

    #[Route('/upload-evidences', name: 'upload_evidences', methods: ['POST'])]
    public function uploadEvidences(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepo,
        MeasureRepository $measureRepo,
        PlanMeasureRepository $pmRepo,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): JsonResponse {
        $user = $this->getUser();
        $project = $activeProjectService->getActiveProject();
        if (!$user || !$project) {
            return new JsonResponse(['success' => false, 'error' => 'No project or user'], 400);
        }

        // VOTER: acceso al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $measureId = (int) $request->request->get('measureId', 0);
        $sourceCode = trim((string) $request->request->get('source_code', $request->request->get('sourceCode', '')));
        /** @var UploadedFile[]|UploadedFile|null $files */
        $files = $request->files->all('evidences');
        if (!is_array($files)) {
            $files = $files ? [$files] : [];
        }

        // Reglas
        $maxFiles  = 4;
        $maxBytes  = 4 * 1024 * 1024; // 4 MB

        if (count($files) > $maxFiles) {
            return new JsonResponse(['success' => false, 'error' => sprintf('Puedes cargar como máximo %d archivos por vez.', $maxFiles)], 400);
        }
        foreach ($files as $f) {
            if ($f && $f->getSize() !== null && $f->getSize() > $maxBytes) {
                return new JsonResponse(['success' => false, 'error' => 'Cada archivo debe pesar como máximo 4 MB.'], 400);
            }
        }
        if (!$measureId || empty($files)) {
            return new JsonResponse(['success' => false, 'error' => 'Parámetros inválidos'], 400);
        }
        if (count($files) > 1 && $sourceCode !== '') {
            return new JsonResponse(['success' => false, 'error' => 'La subida de una sola evidencia por vez es obligatoria cuando se asigna fuente.'], 400);
        }

        $measure = $measureRepo->find($measureId);
        if (!$measure) {
            return new JsonResponse(['success' => false, 'error' => 'Measure not found'], 404);
        }
        if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
            return new JsonResponse(['success' => false, 'error' => 'Feature not available for current plan tier'], 403);
        }

        // Asegurar Plan (debe existir; no se crea aquí)
        $plan = $planRepo->findOneBy(['project' => $project]);
        if (!$plan) {
            return new JsonResponse(['success' => false, 'error' => 'Plan not found'], 404);
        }

        // VOTER: vas a modificar evidencias del plan -> EDIT
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        if ($plan->getStatus() !== 'completo') {
            return $this->implementationNotReadyResponse();
        }

        if (!$this->featureGate->canUseFeature($project, CommercialPhase::IMPLEMENTATION, 'sustainability_plan.evidence_upload')) {
            return new JsonResponse(['success' => false, 'error' => 'Feature not available for current plan tier'], 403);
        }

        $allowedSourceCodes = [];
        foreach ($measure->getResolvedVerificationSourceLinks() as $link) {
            $code = $link->getVerificationSource()?->getCode();
            if (is_string($code) && trim($code) !== '') {
                $allowedSourceCodes[$code] = true;
            }
        }
        if ($allowedSourceCodes !== [] && $sourceCode === '') {
            return new JsonResponse(['success' => false, 'error' => 'Debes seleccionar una fuente de verificación.'], 400);
        }
        if ($sourceCode !== '' && !isset($allowedSourceCodes[$sourceCode])) {
            return new JsonResponse(['success' => false, 'error' => 'Fuente de verificación inválida.'], 400);
        }

        $maxEvidenceCount = $this->featureGate->getMaxEvidenceCount($project, CommercialPhase::IMPLEMENTATION);
        if ($maxEvidenceCount !== null) {
            $currentEvidenceCount = $this->countProjectEvidenceFiles($plan);
            $incomingEvidenceCount = 0;
            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $incomingEvidenceCount++;
                }
            }

            if ($currentEvidenceCount + $incomingEvidenceCount > $maxEvidenceCount) {
                return new JsonResponse([
                    'success' => false,
                    'error' => sprintf('Basic permite un máximo de %d evidencias por proyecto.', $maxEvidenceCount),
                ], 403);
            }
        }

        // Asegurar PlanMeasure (solo cuando las validaciones previas ya pasaron)
        $pm = $pmRepo->findOneBy(['plan' => $plan, 'measure' => $measure]);
        if (!$pm) {
            $pm = (new PlanMeasure())->setPlan($plan)->setMeasure($measure);
            $em->persist($pm);
            $em->flush();
        }

        // Directorio de subida
        $publicDir  = $this->getParameter('kernel.project_dir') . '/public';
        $projectDir = (string) $project->getId();
        $uploadRel  = '/uploads/evidences/' . $projectDir;
        $uploadAbs  = $publicDir . $uploadRel;
        if (!is_dir($uploadAbs)) {
            @mkdir($uploadAbs, 0775, true);
        }

        // Evidencias existentes (una por línea)
        $existing = array_filter(array_map('trim', explode("\n", (string) $pm->getEvidence())));
        $metadata = $pm->getEvidenceMetadata() ?? [];

        $added = [];
        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }
            $orig = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $ext  = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
            $safe = preg_replace('/[^a-z0-9\-_.]+/i', '_', $orig);

            $target = $safe . '_' . substr(bin2hex(random_bytes(3)), 0, 5) . '.' . $ext;
            $file->move($uploadAbs, $target);

            $relPath = $uploadRel . '/' . $target;
            $existing[] = $relPath;
            $added[] = $relPath;
            if ($sourceCode !== '') {
                $metadata[$relPath] = $sourceCode;
            }
        }

        $existing = array_values(array_unique($existing));
        $pm->setEvidence(implode("\n", $existing));
        $pm->setEvidenceMetadata($metadata);
        $pm->normalizeImplementedState();
        $em->persist($pm);
        $em->flush();

        return new JsonResponse(['success' => true, 'files' => $added]);
    }

    #[Route('/delete-evidence', name: 'delete_evidence', methods: ['POST'])]
    public function deleteEvidence(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepo,
        MeasureRepository $measureRepo,
        PlanMeasureRepository $pmRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        $project = $activeProjectService->getActiveProject();
        if (!$user || !$project) {
            return new JsonResponse(['success' => false, 'error' => 'No project or user'], 400);
        }

        // VOTER: acceso al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $measureId = (int) $request->request->get('measureId', 0);
        $file = trim((string) $request->request->get('file', ''));

        if (!$measureId || $file === '') {
            return new JsonResponse(['success' => false, 'error' => 'Parámetros inválidos'], 400);
        }

        $measure = $measureRepo->find($measureId);
        if (!$measure) {
            return new JsonResponse(['success' => false, 'error' => 'Measure not found'], 404);
        }
        if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
            return new JsonResponse(['success' => false, 'error' => 'Feature not available for current plan tier'], 403);
        }

        $plan = $planRepo->findOneBy(['project' => $project]);
        if (!$plan) {
            return new JsonResponse(['success' => false, 'error' => 'Plan not found'], 404);
        }
        // VOTER: vas a modificar evidencias del plan -> EDIT
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        if ($plan->getStatus() !== 'completo') {
            return $this->implementationNotReadyResponse();
        }

        $pm = $pmRepo->findOneBy(['plan' => $plan, 'measure' => $measure]);
        if (!$pm) {
            return new JsonResponse(['success' => false, 'error' => 'PlanMeasure not found'], 404);
        }

        $list = array_filter(array_map('trim', explode("\n", (string) $pm->getEvidence())));
        $newList = [];
        $removed = false;
        foreach ($list as $path) {
            if ($removed === false && $path === $file) {
                $removed = true;
                $abs = $this->getParameter('kernel.project_dir') . '/public' . $path;
                if (is_file($abs)) {
                    @unlink($abs);
                }
                $pm->removeEvidenceSourceCodeForPath($path);
                continue;
            }
            $newList[] = $path;
        }

        if ($removed) {
            $pm->setEvidence(implode("\n", $newList));
            $pm->normalizeImplementedState();
            $em->persist($pm);
            $em->flush();
        }

        return new JsonResponse([
            'success' => true,
            'removed' => $removed,
            'implemented' => $pm->isImplemented(),
        ]);
    }

    #[Route('/closure/download', name: 'closure_download_pdf', methods: ['GET'])]
    public function downloadClosurePdf(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        Request $request,
        TranslatorInterface $translator
    ): Response {
        return $this->handlePreviewOrDownload(
            activeProjectService: $activeProjectService,
            planRepository: $planRepository,
            measureRepository: $measureRepository,
            protocolRepository: $protocolRepository,
            em: $em,
            request: $request,
            asPdf: true,
            translator: $translator,
            phase: CommercialPhase::ELABORATION,
            requireClosure: true,
            forcedFilters: ['state' => PlanMeasureOperationalStateResolver::ALL]
        );
    }

    #[Route('/done/email', name: 'closure_send_email', methods: ['POST'])]
    public function sendClosureEmail(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        TranslatorInterface $translator,
        SustainabilityPlanClosureEmailRecipientResolver $recipientResolver
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            return $this->redirectToRoute('backend_plan_welcome');
        }

        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        if ($plan->getStatus() !== 'completo' || $plan->getCustomMeasuresCompletedAt() === null) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        if (!$this->featureGate->canUseFeature(
            $project,
            CommercialPhase::ELABORATION,
            'sustainability_plan.export.email'
        )) {
            $this->addFlash('info', 'backend.plan.closure.feature_unavailable');
            return $this->redirectToRoute('backend_plan_done');
        }

        if (!$this->isCsrfTokenValid('closure_send_plan_email', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.plan.closure.email.invalid_csrf');
            return $this->redirectToRoute('backend_plan_done');
        }

        try {
            $members = $recipientResolver->resolve($project, $request->request->all('crew_ids'));
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'backend.plan.closure.email.invalid_recipients');
            return $this->redirectToRoute('backend_plan_done');
        }

        if ($members === []) {
            $this->addFlash('warning', 'backend.plan.email.select_member');
            return $this->redirectToRoute('backend_plan_done');
        }

        $pdfBytes = $this->buildPdfBytesForFilters(
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $protocolRepository,
            ['state' => PlanMeasureOperationalStateResolver::ALL],
            $em,
            $translator,
            CommercialPhase::ELABORATION
        );

        [$ok, $fail] = $this->sendPlanPdfEmails(
            $members,
            $pdfBytes,
            $this->buildEmailAttachmentFilename($project, $translator),
            $project,
            $mailer,
            $translator
        );

        if ($ok > 0) {
            $this->addFlash('success', 'backend.plan.email.sent_ok');
        }
        if ($fail > 0) {
            $this->addFlash('danger', 'backend.plan.email.sent_fail');
        }

        return $this->redirectToRoute('backend_plan_done');
    }

    #[Route('/download', name: 'download_pdf', methods: ['GET'])]
    public function downloadPdf(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        Request $request,
        TranslatorInterface $translator
    ): Response {
        return $this->handlePreviewOrDownload(
            activeProjectService: $activeProjectService,
            planRepository:       $planRepository,
            measureRepository:    $measureRepository,
            protocolRepository:   $protocolRepository,
            em:                   $em,
            request:              $request,
            asPdf:                true,
            translator:           $translator
        );
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        Request $request,
        TranslatorInterface $translator
    ): Response {
        return $this->handlePreviewOrDownload(
            activeProjectService: $activeProjectService,
            planRepository:       $planRepository,
            measureRepository:    $measureRepository,
            protocolRepository:   $protocolRepository,
            em:                   $em,
            request:              $request,
            asPdf:                false,
            translator:           $translator
        );
    }

    /**
     * Manejador único para vista previa (HTML) y descarga (PDF),
     * reutilizando el builder de contexto para evitar duplicación.
     */
    private function handlePreviewOrDownload(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        Request $request,
        bool $asPdf,
        TranslatorInterface $translator,
        CommercialPhase $phase = CommercialPhase::IMPLEMENTATION,
        bool $requireClosure = false,
        ?array $forcedFilters = null
    ): Response {
        // --- Guards de acceso y estado ---
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        // Debe poder ver el proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        // Debe existir plan y estar completo
        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan || $plan->getStatus() !== 'completo') {
            $this->addFlash('info', 'backend.plan.errors.not_complete');
            return $this->redirectToRoute('backend_plan_measures');
        }

        // Debe poder ver el plan
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        if ($requireClosure && $plan->getCustomMeasuresCompletedAt() === null) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        if ($requireClosure && !$this->featureGate->canUseFeature(
            $project,
            CommercialPhase::ELABORATION,
            'sustainability_plan.unified_pdf'
        )) {
            $this->addFlash('info', 'backend.plan.closure.feature_unavailable');
            return $this->redirectToRoute('backend_plan_done');
        }

        // 1) Leer filtros de la query
        $filters = [
            'protocol'           => $request->query->get('protocol'),
            'category'           => $request->query->get('category'),
            'department'         => $request->query->get('department'),
            'ods'                => $request->query->get('ods'),
            'impact_area'        => $request->query->get('impact_area'),
            'triple_balance_axis'=> $request->query->get('triple_balance_axis'),
            'scope'              => $request->query->get('scope'),
            'esg'                => $request->query->get('esg'),
            'is_applicable'      => $request->query->get('is_applicable'),
            'will_implement'     => $request->query->get('will_implement'),
            'pending_selection'  => $request->query->get('pending_selection'),
            'only_implemented'   => $request->query->get('only_implemented'),
            'state'              => $request->query->get('state'),
            'is_critical'        => $request->query->get('is_critical'),
        ];
        if ($forcedFilters !== null) {
            $filters = array_replace($filters, $forcedFilters);
        }

        // 2) Construir contexto unificado
        $ctx = $this->buildPdfContext(
            activeProjectService: $activeProjectService,
            planRepository:       $planRepository,
            measureRepository:    $measureRepository,
            protocolRepository:   $protocolRepository,
            em:                   $em,
            filters:              $filters,
            translator:           $translator,
            phase:                $phase
        );

        if ($asPdf) {
            // 3A) Renderizar HTML del PDF y generar bytes
            $html    = $this->renderPdfHtml($ctx);
            $pdfData = $this->pdfBytesFromHtml($html);

            // 4A) Responder como descarga
            /** @var Project $project */
            $project = $ctx['project'];
            $slugger = new AsciiSlugger();
            $projectNameSlug = $slugger->slug(substr($project->getName(), 0, 20))->lower();
            $filename = 'plan_sostenibilidad_' . $projectNameSlug . '.pdf';

            $response = new Response($pdfData);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set(
                'Content-Disposition',
                ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . $filename . '"'
            );
            $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'public');
            $response->headers->set('Content-Length', (string) strlen($pdfData));

            return $response;
        }

        // 3B) Vista previa HTML
        return $this->render('backend/plan/_preview.html.twig', [
            'project'        => $ctx['project'],
            'plan'           => $ctx['plan'],
            'measuresByDpto' => $ctx['measuresByDpto'],
            'customMeasures' => $ctx['customMeasures'],
            'activeFilters'  => $ctx['activeFilters'],
            'taxonomyPresenter' => $ctx['taxonomyPresenter'],
            'currentUserLabel'   => $ctx['currentUserLabel'],
            'scoreMax'       => $ctx['scoreMax'],
            'scoreGained'    => $ctx['scoreGained'],
            'scorePct'       => $ctx['scorePct'],
            'commitmentSummary' => $ctx['commitmentSummary'],
            // 'planChartsUrls' => $ctx['planChartsUrls'],
            'preview'        => true,
        ]);
    }

    private function buildPdfBytesForFilters(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        array $filters,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        CommercialPhase $phase = CommercialPhase::IMPLEMENTATION
    ): string {
        $ctx  = $this->buildPdfContext(
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $protocolRepository,
            $em,
            $filters,
            $translator,
            true,
            $phase
        );
        $html = $this->renderPdfHtml($ctx);
        return $this->pdfBytesFromHtml($html);
    }

    private function buildActiveFilterLabels(
        ?string $filterProtocol,
        ?string $filterCategory,
        ?string $filterDepartment,
        ?string $filterOds,
        ?string $filterImpactArea,
        ?string $filterTripleBalanceAxis,
        ?string $filterScope,
        ?string $filterEsg,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        TranslatorInterface $translator
    ): array {
        $protocolLabel = null;
        if ($filterProtocol) {
            if (ctype_digit((string) $filterProtocol)) {
                $protocol = $em->getRepository(Protocol::class)->find((int) $filterProtocol);
                $protocolLabel = $protocol?->getName();
            } else {
                $protocolLabel = $filterProtocol;
            }
        }

        $categoryLabel = null;
        if ($filterCategory && ctype_digit((string) $filterCategory)) {
            $category = $em->getRepository(Category::class)->find((int) $filterCategory);
            $categoryLabel = $category?->getName();
        }

        $departmentLabel = null;
        if ($filterDepartment && ctype_digit((string) $filterDepartment)) {
            $dept = $em->getRepository(Department::class)->find((int) $filterDepartment);
            $departmentLabel = $dept?->getDisplayName();
        }

        $odsLabel = null;
        if ($filterOds && ctype_digit((string) $filterOds)) {
            $ods = $em->getRepository(Ods::class)->find((int) $filterOds);
            $odsLabel = method_exists($ods, 'getName') ? $ods?->getName() : ($ods?->getName());
        }

        $esgLabel = null;
        if ($filterEsg && ctype_digit((string) $filterEsg)) {
            $esg = $em->getRepository(EsG::class)->find((int) $filterEsg);
            $esgLabel = method_exists($esg, 'getName') ? $esg?->getName() : ($esg?->getName());
        }

        $impactAreaLabel = null;
        if ($filterImpactArea && ctype_digit((string) $filterImpactArea)) {
            $impactArea = $em->getRepository(\App\Entity\ImpactArea::class)->find((int) $filterImpactArea);
            $impactAreaLabel = $impactArea?->getName();
        }

        $tripleBalanceAxisLabel = null;
        if ($filterTripleBalanceAxis && ctype_digit((string) $filterTripleBalanceAxis)) {
            $axis = $em->getRepository(\App\Entity\TripleBalanceAxis::class)->find((int) $filterTripleBalanceAxis);
            $tripleBalanceAxisLabel = $axis?->getName();
        }

        $scopeLabel = null;
        if ($filterScope && ctype_digit((string) $filterScope)) {
            $scope = $em->getRepository(Scope::class)->find((int) $filterScope);
            $scopeLabel = $scope?->getName();
        }

        $labelProtocol   = $translator->trans('backend.plan.filters.protocol');
        $labelCategory   = $translator->trans('backend.plan.filters.category');
        $labelDepartment = $translator->trans('backend.plan.filters.department');
        $labelOds        = $translator->trans('backend.plan.filters.ods');
        $labelImpactArea = $translator->trans('backend.plan.filters.impact_area');
        $labelTriple     = $translator->trans('backend.plan.filters.triple_balance');
        $labelScope      = $translator->trans('backend.plan.filters.scope');
        $labelEsg        = $translator->trans('backend.plan.filters.esg');

        return array_filter([
            $labelProtocol   => $protocolLabel,
            $labelCategory   => $categoryLabel,
            $labelDepartment => $departmentLabel,
            $labelOds        => $odsLabel,
            $labelImpactArea => $impactAreaLabel,
            $labelTriple     => $tripleBalanceAxisLabel,
            $labelScope      => $scopeLabel,
            $labelEsg        => $esgLabel,
        ]);
    }

    private function buildPdfContext(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        array $filters,
        TranslatorInterface $translator,
        bool $useDepartmentActionText = false,
        CommercialPhase $phase = CommercialPhase::IMPLEMENTATION
    ): array {
        $project = $activeProjectService->getActiveProject();
        if (!$project) throw $this->createNotFoundException('backend.projects.flash.no_active');

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) throw $this->createNotFoundException('backend.plan.errors.not_found');

        $filterDepartment      = $filters['department']         ?? null;
        $filterCategory        = $filters['category']           ?? null;
        $filterProtocol        = $filters['protocol']           ?? null;
        $filterOds             = $filters['ods']                ?? null;
        $filterImpactArea      = $filters['impact_area']       ?? null;
        $filterTripleBalance   = $filters['triple_balance_axis'] ?? null;
        $filterScope           = $filters['scope']             ?? null;
        $filterEsg             = $filters['esg']                ?? null;
        $filterApplicable      = $filters['is_applicable']      ?? null;
        $filterImplement       = $filters['will_implement']     ?? null;
        $filterPending         = $filters['pending_selection']  ?? null;
        $filterOnlyImplemented = $filters['only_implemented']   ?? null;
        $filterState           = $filters['state']              ?? PlanMeasureOperationalStateResolver::PENDING;
        $filterCritical        = $filters['is_critical']        ?? null;

        $filtersArr = [
            'protocol'          => $filterProtocol,
            'category'          => $filterCategory,
            'department'        => $filterDepartment,
            'ods'               => $filterOds,
            'impact_area'       => $filterImpactArea,
            'triple_balance_axis'=> $filterTripleBalance,
            'scope'             => $filterScope,
            'esg'               => $filterEsg,
            'is_applicable'     => $filterApplicable,
            'will_implement'    => $filterImplement,
            'pending_selection' => $filterPending,
            'only_implemented'  => $filterOnlyImplemented,
            'state'             => $filterState,
            'is_critical'       => $filterCritical,
        ];

        $filteredPlanMeasures = $this->getFilteredPlanMeasures($plan, $project, $filtersArr);

        $measuresByDpto = [];
        $noDeptLabel = $translator->trans('backend.plan.labels.no_department');
        foreach ($filteredPlanMeasures as $pm) {
            $m = $pm->getMeasure();
            if (!$m) {
                continue;
            }

            $dpto = $m->getPrimaryDepartment()?->getDisplayName() ?? $noDeptLabel;
            $measuresByDpto[$dpto][] = $pm;
        }

        $measuresTotal = $this->countFilteredMeasures($plan, $project, $filtersArr);

        // --- PUNTUACIÓN ALCANZADA (con filtros aplicados) ---
        $scoreMax = 0;
        $scoreGained = 0;

        foreach ($filteredPlanMeasures as $pm) {
            $m = $pm->getMeasure();
            if (!$m) {
                continue;
            }
            $score = (int) ($m->getScore() ?? 0);
            $scoreMax += $score;

            if ($score > 0 && $pm->isApplicable() === true && $pm->willImplement() === true) {
                $scoreGained += $score;
            }
        }

        $scorePct = $scoreMax > 0 ? round(100 * $scoreGained / $scoreMax) : null;

        $effective = $nonApplicable = $agreed = $implemented = 0;
        foreach ($filteredPlanMeasures as $pm) {
            if ($pm->isApplicable() === true)  $effective++;
            if ($pm->isApplicable() === false) $nonApplicable++;
            if ($pm->willImplement())          $agreed++;
            if ($pm->isImplemented())          $implemented++;
        }

        $planChartsConfig = $this->buildChartsConfig($measuresTotal, $effective, $nonApplicable, $agreed, $implemented);
        $planChartsUrls   = $this->buildQuickChartUrlsFromConfig($planChartsConfig);

        $activeMain = $this->buildActiveFilterLabels(
            $filterProtocol,
            $filterCategory,
            $filterDepartment,
            $filterOds,
            $filterImpactArea,
            $filterTripleBalance,
            $filterScope,
            $filterEsg,
            $protocolRepository,
            $em,
            $translator
        );

        $activeFlags = [];
        $stateLabels = [
            PlanMeasureOperationalStateResolver::ALL => $translator->trans('backend.plan.filters.state_all'),
            PlanMeasureOperationalStateResolver::PENDING => $translator->trans('backend.plan.filters.state_pending'),
            PlanMeasureOperationalStateResolver::IN_PROGRESS => $translator->trans('backend.plan.filters.state_in_progress'),
            PlanMeasureOperationalStateResolver::IMPLEMENTED => $translator->trans('backend.plan.filters.state_implemented'),
            'discarded' => $translator->trans('backend.plan.filters.state_discarded'),
            PlanMeasureOperationalStateResolver::NOT_APPLICABLE => $translator->trans('backend.plan.filters.state_not_applicable'),
        ];
        if (isset($stateLabels[$filterState])) {
            $activeFlags[$stateLabels[$filterState]] = $translator->trans('backend.common.yes');
        }
        if ($filterCritical) {
            $activeFlags[$translator->trans('backend.plan.filters.critical')] = $translator->trans('backend.common.yes');
        }

        $activeFilters = array_merge($activeMain, $activeFlags);
        $projectTierLabel = $this->featureGate->getPlanLabel($project, $phase);
        $projectTierSummary = $this->featureGate->getPlanDescription($project, $phase) ?? $this->t->trans('backend.plan.tier.basic_summary');

        return [
            'project'        => $project,
            'plan'           => $plan,
            'projectTier'    => $this->featureGate->getTier($project, $phase),
            'projectTierLabel'=> $projectTierLabel,
            'projectTierSummary'=> $projectTierSummary,
            'currentUserLabel'=> $this->buildCurrentUserLabel(),
            'hasWatermark'   => $this->featureGate->hasWatermark($project, $phase),
            'taxonomyPresenter'=> $this->taxonomyPresenter,
            'collaborationSummary' => $this->collaborationService->buildProgressSummary($plan, $project),
            'commitmentSummary' => $this->commitmentLevelService->buildSummary($plan, $project),
            'customMeasures' => $this->collaborationService->getCustomMeasures($plan),
            'crewMembersByMeasure' => $this->buildCrewMembersByMeasure($plan, $project),
            'activeFilters'  => $activeFilters,
            'measuresByDpto' => $measuresByDpto,
            'planChartsUrls' => $planChartsUrls,
            'scoreMax'       => $scoreMax,
            'scoreGained'    => $scoreGained,
            'scorePct'       => $scorePct,
            'useDepartmentActionText' => $useDepartmentActionText,
        ];
    }

    private function renderPdfHtml(array $context): string
    {
        return $this->renderView('backend/plan/pdf.html.twig', $context);
    }

    private function buildCurrentUserLabel(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return '';
        }

        $fullName = trim((string) $user->getName() . ' ' . (string) $user->getSurnames());
        if ($fullName !== '') {
            return $fullName;
        }

        return (string) ($user->getEmail() ?? '');
    }

    private function pdfBytesFromHtml(string $html, string $orientation = 'landscape'): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    private function getFilteredPlanMeasures(Plan $plan, Project $project, array $filters): array
    {
        $result = [];
        $state = $filters['state'] ?? PlanMeasureOperationalStateResolver::PENDING;

        foreach ($plan->getPlanMeasures() as $pm) {
            $m = $pm->getMeasure();
            if (!$m || !$this->catalogResolver->isCatalogMeasure($m, $project)) {
                continue;
            }

            if ($filters['category'] && $m->getCategory()?->getId() != $filters['category']) {
                continue;
            }

            if ($filters['protocol']) {
                $proto = $m->getProtocol();
                $matchByName = $proto?->getName() === $filters['protocol'];
                $matchById   = $proto?->getId() == $filters['protocol'];
                if (!$matchByName && !$matchById) continue;
            }

            if ($filters['scope'] && $m->getScope()?->getId() != $filters['scope']) continue;
            if ($filters['esg'] && $m->getEsg()?->getId() != $filters['esg']) continue;
            if (!$this->taxonomyPresenter->matchesFilters($m, $filters)) continue;

            if (!empty($filters['is_critical']) && !$pm->isCritical()) {
                continue;
            }

            if (!$this->operationalStateResolver->matches($pm, (string) $state)) {
                continue;
            }

            $result[] = $pm;
        }

        return $result;
    }

    private function countFilteredMeasures(
        Plan $plan,
        Project $project,
        array $filters
    ): int
    {
        return count($this->getFilteredPlanMeasures($plan, $project, $filters));
    }

    /**
     * @return array<int, int>
     */
    private function getSkippedMeasureBlockIds(Plan $plan): array
    {
        $ids = [];

        foreach ($plan->getBlockAnswers() as $answer) {
            if ($answer->applies() === false && $answer->getMeasureBlock()?->getId() !== null) {
                $ids[(int) $answer->getMeasureBlock()->getId()] = (int) $answer->getMeasureBlock()->getId();
            }
        }

        return $ids;
    }

    /**
     * @param array<int, Measure> $measures
     * @return array<int, Measure>
     */
    private function filterMeasuresBySkippedBlocks(array $measures, Plan $plan): array
    {
        $skippedBlockIds = $this->getSkippedMeasureBlockIds($plan);
        if ($skippedBlockIds === []) {
            return $measures;
        }

        return array_values(array_filter($measures, static function (Measure $measure) use ($skippedBlockIds): bool {
            $blockId = $measure->getMeasureBlock()?->getId();
            return $blockId === null || !isset($skippedBlockIds[(int) $blockId]);
        }));
    }

    /**
     * @return array{measure: Measure, index: int, reason: string}|null
     */
    private function findFirstPendingVisibleMeasure(
        Plan $plan,
        Project $project,
        MeasureRepository $measureRepository
    ): ?array {
        return $this->planCompletionService->findFirstPendingVisibleMeasure($plan, $project, $measureRepository);
    }

    /**
     * @param array<int, Measure> $visibleMeasures
     */
    private function findVisibleMeasureIndex(array $visibleMeasures, Measure $measure): ?int
    {
        return $this->planCompletionService->findVisibleMeasureIndex($visibleMeasures, $measure);
    }

    /**
     * @param iterable<int, PlanMeasure> $planMeasures
     * @return array<int, PlanMeasure>
     */
    private function filterPlanMeasuresBySkippedBlocks(iterable $planMeasures, Plan $plan): array
    {
        $skippedBlockIds = $this->getSkippedMeasureBlockIds($plan);
        if ($skippedBlockIds === []) {
            return is_array($planMeasures) ? $planMeasures : iterator_to_array($planMeasures, false);
        }

        $result = [];
        foreach ($planMeasures as $planMeasure) {
            $measure = $planMeasure->getMeasure();
            $blockId = $measure?->getMeasureBlock()?->getId();
            if ($blockId !== null && isset($skippedBlockIds[(int) $blockId])) {
                continue;
            }

            $result[] = $planMeasure;
        }

        return $result;
    }

    private function hasCriticalReason(PlanMeasure $planMeasure): bool
    {
        return trim((string) ($planMeasure->getCriticalReason() ?? '')) !== '';
    }

    private function validateCriticalReasonField(PlanMeasure $planMeasure, string $text): ?JsonResponse
    {
        if ($text === '' && $planMeasure->isCritical() === true) {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->criticalReasonRequiredMessage(),
            ], 400);
        }

        return null;
    }

    private function validateCriticalReasonBeforeImplementing(PlanMeasure $planMeasure): ?JsonResponse
    {
        if ($planMeasure->isCritical() === true && !$this->hasCriticalReason($planMeasure)) {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->criticalReasonRequiredMessage(),
            ], 400);
        }

        return null;
    }

    private function implementedRequirementsMessage(): string
    {
        return $this->t->trans('backend.plan.review.stimulus.implemented_require_action_and_evidence');
    }

    private function canAdvanceFromCurrentMeasure(PlanMeasure $planMeasure): bool
    {
        $applies = $planMeasure->isApplicable();
        if ($applies === false) {
            return true;
        }

        if ($applies !== true) {
            return false;
        }

        $willImplement = $planMeasure->willImplement();
        if ($willImplement === false) {
            return true;
        }

        if ($willImplement !== true) {
            return false;
        }

        $critical = $planMeasure->isCritical();
        if ($critical === false) {
            return true;
        }

        if ($critical === true) {
            return $this->hasCriticalReason($planMeasure);
        }

        return false;
    }

    private function criticalReasonRequiredMessage(): string
    {
        return $this->t->trans('backend.plan.measures.critical_reason_required');
    }

    private function pendingMeasureFlashMessage(string $reason): string
    {
        return match ($reason) {
            'critical_reason_missing' => 'backend.plan.flash.pending_critical_reason',
            default => 'backend.plan.flash.pending_measure',
        };
    }

    private function isTerminalSelectionAction(string $field, mixed $value): bool
    {
        if ($field === 'decision') {
            return in_array((string) $value, ['false', 'na'], true);
        }

        if ($field === 'critical') {
            return $value === 'false';
        }

        if ($field === 'willImplement') {
            return true;
        }

        return $field === 'isApplicable' && $value === 'false';
    }

    private function resolveTerminalSelectionNextUrl(Plan $plan, bool $planComplete, int $nextIndex): string
    {
        if ($planComplete && !$this->shouldShowCustomMeasuresStep($plan)) {
            return $this->generateUrl('backend_plan_done');
        }

        return $this->generateUrl('backend_plan_measures', ['i' => $nextIndex]);
    }

    private function shouldShowCustomMeasuresStep(Plan $plan): bool
    {
        return $plan->getStatus() === 'completo' && $plan->getCustomMeasuresCompletedAt() === null;
    }

    private function findBlockAnswer(Plan $plan, ?MeasureBlock $block): ?SustainabilityPlanBlockAnswer
    {
        if (!$block || $block->getId() === null) {
            return null;
        }

        foreach ($plan->getBlockAnswers() as $answer) {
            if ($answer->getMeasureBlock()?->getId() === $block->getId()) {
                return $answer;
            }
        }

        return null;
    }

    private function findPlanMeasureForMeasure(Plan $plan, Measure $measure): ?PlanMeasure
    {
        $measureId = $measure->getId();
        if ($measureId === null) {
            return null;
        }

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure->getMeasure()?->getId() === $measureId) {
                return $planMeasure;
            }
        }

        return null;
    }

    private function createVisibleMeasuresQueryBuilder(
        MeasureRepository $measureRepository,
        Protocol $protocol,
        Project $project
    ): QueryBuilder
    {
        $qb = $measureRepository->createQueryBuilder('m')
            ->join('m.protocol', 'p')
            ->leftJoin('m.category', 'c')
            ->leftJoin('m.department', 'd')
            ->leftJoin('m.measureBlock', 'mb')
            ->addSelect('c', 'd', 'mb')
            ->andWhere('p = :protocol')
            ->setParameter('protocol', $protocol);
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p', $project);

        return $qb;
    }

    /**
     * Construye la config de los 4 gráficos de review calculados por puntos.
     */
    private function buildReviewChartsConfig(array $planMeasures, ?int $protocolId, ?array $allPlanMeasures = null): array
    {
        $c1       = '#2ecc71';
        $c2       = 'rgba(63, 195, 138, 0.40)';
        $c3       = '#e9ecef';
        $c1Border = '#27ae60';
        $c2Border = 'rgba(63, 195, 138, 1)';
        $c3Border = '#7f8c8d';

        $applicabilitySource = $allPlanMeasures ?? $planMeasures;

        $totalPoints = 0;
        $applicablePoints = 0;
        $nonApplicablePoints = 0;
        $assumedPoints = 0;
        $implementedPoints = 0;

        foreach ($applicabilitySource as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure) {
                continue;
            }

            if ($protocolId !== null && $measure->getProtocol()?->getId() !== $protocolId) {
                continue;
            }

            $points = max(0, (int) ($measure->getScore() ?? 0));
            $totalPoints += $points;

            if ($planMeasure->isApplicable() === true) {
                $applicablePoints += $points;
            } elseif ($planMeasure->isApplicable() === false) {
                $nonApplicablePoints += $points;
            }
        }

        foreach ($planMeasures as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure) {
                continue;
            }

            if ($protocolId !== null && $measure->getProtocol()?->getId() !== $protocolId) {
                continue;
            }

            $points = max(0, (int) ($measure->getScore() ?? 0));

            if ($planMeasure->isApplicable() === true && $planMeasure->willImplement() === true) {
                $assumedPoints += $points;
            }

            if ($planMeasure->isApplicable() === true && $planMeasure->willImplement() === true && $planMeasure->isImplemented() === true) {
                $implementedPoints += $points;
            }
        }

        $applicabilityPct = $this->percentageFromPoints($applicablePoints, $totalPoints);
        $notApplicablePct = $totalPoints > 0 ? round(100 - $applicabilityPct, 1) : 0.0;

        $commitmentPct = $this->percentageFromPoints($assumedPoints, $applicablePoints);

        $complianceImplementedPct = $this->percentageFromPoints($implementedPoints, $assumedPoints);
        $complianceNotImplementedPct = $assumedPoints > 0 ? round(100 - $complianceImplementedPct, 1) : 0.0;

        $achievementsPct = $this->percentageFromPoints($implementedPoints, $applicablePoints);
        $achievementsNotPct = $applicablePoints > 0 ? round(100 - $achievementsPct, 1) : 0.0;

        return [
            'applicability' => [
                'type' => 'bar',
                'title' => $this->t->trans('backend.plan.review.charts.applicability.title'),
                'labels' => [
                    $this->t->trans('backend.plan.review.charts.applicability.total_possible'),
                    $this->t->trans('backend.plan.review.charts.applicability.applicable'),
                    $this->t->trans('backend.plan.review.charts.applicability.not_applicable'),
                ],
                'datasets' => [[
                    'data' => [100, $applicabilityPct, $notApplicablePct],
                    'backgroundColor' => [$c2, $c1, $c3],
                    'borderColor' => [$c2Border, $c1Border, $c3Border],
                    'borderWidth' => 1,
                    'hoverBackgroundColor' => [$c2, $c1, $c3],
                ]],
                'percentValues' => true,
                'showLegend' => false,
                'showDataLabels' => false,
                'showTitle' => false,
                'options' => [
                    'layout' => [
                        'padding' => [
                            'top' => 24,
                            'bottom' => 6,
                            'left' => 8,
                            'right' => 8,
                        ],
                    ],
                    'scales' => [
                        'y' => [
                            'beginAtZero' => true,
                            'max' => 100,
                            'grace' => '8%',
                        ],
                    ],
                ],
            ],
            'commitment' => [
                'type' => 'bar',
                'title' => $this->t->trans('backend.plan.review.charts.commitment.title'),
                'labels' => [
                    $this->t->trans('backend.plan.review.charts.commitment.total_possible'),
                    $this->t->trans('backend.plan.review.charts.commitment.to_implement'),
                ],
                'datasets' => [[
                    'data' => [100, $commitmentPct],
                    'backgroundColor' => [$c2, $c1],
                    'borderColor' => [$c2Border, $c1Border],
                    'borderWidth' => 1,
                    'hoverBackgroundColor' => [$c2, $c1],
                ]],
                'percentValues' => true,
                'showLegend' => false,
                'showDataLabels' => false,
                'showTitle' => false,
                'options' => [
                    'indexAxis' => 'y',
                    'scales' => [
                        'x' => [
                            'beginAtZero' => true,
                            'max' => 100,
                            'grace' => '8%',
                        ],
                    ],
                ],
            ],
            'compliance' => [
                'type' => 'bar',
                'title' => $this->t->trans('backend.plan.review.charts.compliance.title'),
                'labels' => [
                    $this->t->trans('backend.plan.review.charts.compliance.total_possible'),
                    $this->t->trans('backend.plan.review.charts.compliance.implemented'),
                    $this->t->trans('backend.plan.review.charts.compliance.not_implemented'),
                ],
                'datasets' => [[
                    'data' => [100, $complianceImplementedPct, $complianceNotImplementedPct],
                    'backgroundColor' => [$c2, $c1, $c3],
                    'borderColor' => [$c2Border, $c1Border, $c3Border],
                    'borderWidth' => 1,
                    'hoverBackgroundColor' => [$c2, $c1, $c3],
                ]],
                'percentValues' => true,
                'showLegend' => false,
                'showDataLabels' => false,
                'showTitle' => false,
                'options' => [
                    'layout' => [
                        'padding' => [
                            'top' => 24,
                            'bottom' => 6,
                            'left' => 8,
                            'right' => 8,
                        ],
                    ],
                    'scales' => [
                        'y' => [
                            'beginAtZero' => true,
                            'max' => 100,
                            'grace' => '8%',
                        ],
                    ],
                ],
            ],
            'achievements' => [
                'type' => 'bar',
                'title' => $this->t->trans('backend.plan.review.charts.achievements.title'),
                'labels' => [
                    $this->t->trans('backend.plan.review.charts.achievements.total_possible'),
                    $this->t->trans('backend.plan.review.charts.achievements.achieved'),
                    $this->t->trans('backend.plan.review.charts.achievements.not_achieved'),
                ],
                'datasets' => [[
                    'data' => [100, $achievementsPct, $achievementsNotPct],
                    'backgroundColor' => [$c2, $c1, $c3],
                    'borderColor' => [$c2Border, $c1Border, $c3Border],
                    'borderWidth' => 1,
                    'hoverBackgroundColor' => [$c2, $c1, $c3],
                ]],
                'percentValues' => true,
                'showLegend' => false,
                'showDataLabels' => false,
                'showTitle' => false,
                'options' => [
                    'indexAxis' => 'y',
                    'scales' => [
                        'x' => [
                            'beginAtZero' => true,
                            'max' => 100,
                            'grace' => '8%',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function percentageFromPoints(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }

    /**
     * Construye la config de los 4 gráficos (web y base para PDF).
     * Pies muestran % + cantidad:
     *  - Compromiso: Implementar vs No implementar (base: Aplicables)
     *  - Implementación: Implementadas vs No implementadas (base: Implementar)
     *  - Alcance: En alcance (Aplicables) vs Fuera de alcance (base: Totales)
     * Terminología alineada a los filtros.
     */
    private function buildChartsConfig(
        int $measuresTotal,
        int $effective,
        int $nonApplicable,
        int $agreed,
        int $implemented
    ): array {
        // Paleta
        $c1       = '#2ecc71';
        $c2       = 'rgba(63, 195, 138, 0.40)';
        $c3       = '#e9ecef';
        $c1Border = '#27ae60';
        $c2Border = 'rgba(63, 195, 138, 1)';
        $c3Border = '#7f8c8d';

        // % compromiso: Implementar / Aplicables
        $commitmentPct     = $effective > 0 ? round(($agreed / $effective) * 100) : 0;

        // % implementación: Implementadas / Implementar
        $implementationPct = $agreed > 0 ? round(($implemented / $agreed) * 100) : 0;

        // % alcance: Aplicables / Totales
        $reachPct          = $measuresTotal > 0 ? round(($effective / $measuresTotal) * 100) : 0;

        // Valores ABSOLUTOS para pies (antes del “safe pie”)
        $alcanceDataRaw        = [$measuresTotal - $effective, $effective];     // Fuera, En
        $commitmentDataRaw     = [$effective - $agreed, $agreed];               // No implementar, Implementar
        $implementationDataRaw = [$agreed - $implemented, $implemented];        // No implementadas, Implementadas

        // Evitar datasets [0,0] que no renderizan
        $alcanceData        = $this->safePie($alcanceDataRaw);
        $commitmentData     = $this->safePie($commitmentDataRaw);
        $implementationData = $this->safePie($implementationDataRaw);

        return [
            // 1) Barras (valores absolutos)
            'summary' => [
                'type'   => 'bar',
                'title'  => $this->t->trans('backend.plan.charts.title_measures'),
                'labels' => [
                    $this->t->trans('backend.plan.charts.total'),
                    $this->t->trans('backend.plan.charts.applicable'),
                    $this->t->trans('backend.plan.charts.non_applicable'),
                ],
                'datasets' => [[
                    'label' => $this->t->trans('backend.plan.charts.measures'),
                    'data'  => [$measuresTotal, $effective, $nonApplicable],
                    'backgroundColor' => [$c2, $c1, $c3],
                    'borderColor'     => [$c2Border, $c1Border, $c3Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c2, $c1, $c3],
                ]],
                'options' => [
                    'scales' => ['y' => [
                        'beginAtZero' => true,
                        'grace' => '12%',
                    ]],
                ],
            ],

            // 2) Pie: alcance (Aplicables vs Fuera de alcance, base Totales)
            'alcance' => [
                'type'          => 'pie',
                'title'         => $this->t->trans('backend.plan.charts.reach_title', ['%pct%' => $reachPct]),
                'labels'        => [
                    $this->t->trans('backend.plan.charts.out_of_scope'),
                    $this->t->trans('backend.plan.charts.in_scope'),
                ],
                'datasets'      => [[
                    'label' => $this->t->trans('backend.plan.charts.reach'),
                    'data'  => $alcanceData,
                    'backgroundColor' => [$c3, $c1],
                    'borderColor'     => [$c3Border, $c1Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c3, $c1],
                ]],
            ],

            // 3) Pie: compromiso (Implementar vs No implementar, base Aplicables)
            'commitment' => [
                'type'          => 'pie',
                'title'         => $this->t->trans('backend.plan.charts.commitment_title', ['%pct%' => $commitmentPct]),
                'labels'        => [
                    $this->t->trans('backend.plan.charts.not_to_implement'),
                    $this->t->trans('backend.plan.charts.to_implement'),
                ],
                'datasets'      => [[
                    'label' => $this->t->trans('backend.plan.charts.commitment'),
                    'data'  => $commitmentData,
                    'backgroundColor' => [$c3, $c1],
                    'borderColor'     => [$c3Border, $c1Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c3, $c1],
                ]],
            ],

            // 4) Pie: implementación (Implementadas vs No implementadas, base Implementar)
            'implementation' => [
                'type'          => 'pie',
                'title'         => $this->t->trans('backend.plan.charts.performance_title', ['%pct%' => $implementationPct]),
                'labels'        => [
                    $this->t->trans('backend.plan.charts.not_implemented'),
                    $this->t->trans('backend.plan.charts.implemented'),
                ],
                'datasets'      => [[
                    'label' => $this->t->trans('backend.plan.charts.performance'),
                    'data'  => $implementationData,
                    'backgroundColor' => [$c3, $c1],
                    'borderColor'     => [$c3Border, $c1Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c3, $c1],
                ]],
            ],
        ];
    }

    /**
     * @return array{state: string}
     */
    private function reviewDefaultFilters(): array
    {
        return [
            'state' => PlanMeasureOperationalStateResolver::PENDING,
        ];
    }

    private function buildCommercialFeatureCards(Project $project, CommercialPhase $phase): array
    {
        $definitions = $phase === CommercialPhase::IMPLEMENTATION
            ? [
                'sustainability_plan.department_pdf' => 'PDF por departamentos',
                'sustainability_plan.advanced_exports' => 'Exportaciones avanzadas',
                'sustainability_plan.internal_notes' => 'Notas internas',
                'sustainability_plan.responsibles' => 'Responsables',
                'sustainability_plan.checklist' => 'Checklist',
                'sustainability_plan.validation_summary' => 'Resumen de validación',
                'sustainability_plan.branding' => 'Branding',
            ]
            : [
                'sustainability_plan.department_pdf' => 'PDF por departamentos',
                'sustainability_plan.advanced_exports' => 'Exportaciones avanzadas',
                'sustainability_plan.public_comments' => 'Comentarios personalizados',
                'sustainability_plan.custom_measures' => 'Medidas personalizadas',
            ];

        $cards = [];
        foreach ($definitions as $feature => $label) {
            $state = $this->featureGate->getFeatureState($project, $phase, $feature);
            if ($state['visible'] && !$state['enabled']) {
                $cards[] = [
                    'label' => $label,
                    'reason' => $state['reason'] ?? null,
                ];
            }
        }

        return $cards;
    }

    /**
     * @param array<string, array{label?:string, amountCents?:int|null, priceId?:string|null}> $availableUpgradeTargets
     *
     * @return array{
     *     mode: string,
     *     label: ?string,
     *     title: ?string,
     *     options: array<int, array{
     *         targetTier: string,
     *         name: string,
     *         description: ?string,
     *         priceAmount: ?int,
     *         priceCurrency: string,
     *         priceLabel: string,
     *         ctaLabel: string,
     *         allowedScores: int[]
     *     }>
     * }
     */
    private function buildUpgradeCta(
        Project $project,
        Plan $plan,
        CommercialPhase $phase,
        string $projectTier,
        array $availableUpgradeTargets,
        CommercialPlanRepository $commercialPlanRepository,
        MeasureRepository $measureRepository,
        bool $checkoutEnabled = true
    ): array {
        if ($projectTier === ProjectSubscription::TIER_PRO) {
            return [
                'mode' => 'none',
                'phase' => $phase->value,
                'label' => $this->t->trans('backend.plan.upgrade.active_title'),
                'title' => null,
                'options' => [],
            ];
        }

        if (!$checkoutEnabled) {
            return [
                'mode' => 'unavailable',
                'phase' => $phase->value,
                'label' => $this->t->trans('backend.plan.upgrade.phase_checkout_pending'),
                'title' => $this->t->trans('backend.plan.upgrade.select_title'),
                'options' => [],
            ];
        }

        $candidateTiers = match ($projectTier) {
            ProjectSubscription::TIER_BASIC => [ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO],
            ProjectSubscription::TIER_STANDARD => [ProjectSubscription::TIER_PRO],
            default => [],
        };

        $protocol = $plan->getProtocol();
        $commercialPlans = [];
        $measureCounts = [];
        foreach ([ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO] as $tier) {
            $commercialPlan = $commercialPlanRepository->findActiveByPhaseAndCode($phase, $tier);
            if (!$commercialPlan instanceof CommercialPlan) {
                continue;
            }

            $commercialPlans[$tier] = $commercialPlan;
            $measureCounts[$tier] = $protocol instanceof Protocol
                ? $measureRepository->countCatalogMeasuresForProtocol($protocol, $commercialPlan->getAllowedScores())
                : null;
        }

        $options = [];
        foreach ($candidateTiers as $targetTier) {
            $targetData = $availableUpgradeTargets[$targetTier] ?? null;
            if (!is_array($targetData) || !array_key_exists('priceId', $targetData) || trim((string) $targetData['priceId']) === '') {
                continue;
            }

            $commercialPlan = $commercialPlans[$targetTier] ?? null;
            if (!$commercialPlan instanceof CommercialPlan) {
                continue;
            }

            $displayAmountCents = isset($targetData['amountCents']) && is_int($targetData['amountCents'])
                ? $targetData['amountCents']
                : $commercialPlan->getPriceAmount();
            $priceLabel = $this->formatPlanPrice($displayAmountCents, $commercialPlan->getPriceCurrency());
            $targetTierLabel = ucfirst($targetTier);
            $options[] = [
                'targetTier' => $targetTier,
                'phase' => $phase->value,
                'name' => $targetTierLabel,
                'description' => $commercialPlan->getDescription(),
                'priceAmount' => $displayAmountCents,
                'priceCurrency' => $commercialPlan->getPriceCurrency(),
                'priceLabel' => $priceLabel,
                'ctaLabel' => $this->t->trans('backend.plan.upgrade.upgrade_to', [
                    '%name%' => $targetTierLabel,
                    '%price%' => $priceLabel,
                ]),
                'allowedScores' => $commercialPlan->getAllowedScores(),
                'measureCount' => $measureCounts[$targetTier] ?? null,
            ];
        }

        if ($options === []) {
            return [
                'mode' => 'unavailable',
                'phase' => $phase->value,
                'label' => $this->t->trans('backend.plan.upgrade.price_id_missing'),
                'title' => $this->t->trans('backend.plan.upgrade.select_title'),
                'options' => [],
                'currentTier' => $projectTier,
                'commercialPlans' => $commercialPlans,
                'measureCounts' => $measureCounts,
            ];
        }

        if (\count($options) === 1) {
            return [
                'mode' => 'single',
                'phase' => $phase->value,
                'label' => $options[0]['ctaLabel'],
                'title' => $this->t->trans('backend.plan.upgrade.select_title'),
                'options' => $options,
                'currentTier' => $projectTier,
                'commercialPlans' => $commercialPlans,
                'measureCounts' => $measureCounts,
            ];
        }

        return [
            'mode' => 'comparison',
            'phase' => $phase->value,
            'label' => $this->t->trans('backend.plan.upgrade.open_selector'),
            'title' => $this->t->trans('backend.plan.upgrade.select_title'),
            'options' => $options,
            'currentTier' => $projectTier,
            'commercialPlans' => $commercialPlans,
            'measureCounts' => $measureCounts,
        ];
    }

    private function formatPlanPrice(?int $priceAmount, string $currency): string
    {
        if ($priceAmount === null) {
            return $this->t->trans('backend.common.placeholder');
        }

        $amount = number_format($priceAmount / 100, 2, ',', '.');
        $currency = strtoupper(trim($currency));
        $currencyLabel = $currency === 'EUR' ? '€' : $currency;

        return sprintf('%s %s', $amount, $currencyLabel);
    }

    private function countProjectEvidenceFiles(Plan $plan): int
    {
        $paths = [];
        foreach ($plan->getPlanMeasures() as $pm) {
            $evidence = trim((string) $pm->getEvidence());
            if ($evidence === '') {
                continue;
            }

            foreach (array_filter(array_map('trim', explode("\n", $evidence))) as $path) {
                $paths[$path] = true;
            }
        }

        return count($paths);
    }

    /**
     * @return array<int, CrewMember[]>
     */
    private function buildCrewMembersByMeasure(Plan $plan, Project $project): array
    {
        $crewMembers = $project->getCrewMembers();
        $result = [];

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            $measure = $planMeasure->getMeasure();
            if (!$measure) {
                continue;
            }

            $measureId = $measure->getId();
            if ($measureId === null) {
                continue;
            }

            $result[$measureId] = $this->collaborationService->sortCrewMembersForMeasure($measure, $crewMembers);
        }

        return $result;
    }


    // Añade este helper en la clase PlanController (por ejemplo, debajo de buildQuickChartUrlsFromConfig)
    private function safePie(array $data): array
    {
        $sum = 0;
        foreach ($data as $v) { $sum += (float) $v; }
        if ($sum > 0) {
            return $data;
        }
        // Si todo es 0, devolvemos un dataset mínimo para que Chart.js pinte el pie
        $n = count($data);
        if ($n === 2) return [1, 0];
        if ($n === 3) return [1, 0, 0];
        return [1];
    }



    /**
     * Genera URLs de QuickChart para PDF con fuentes más grandes.
     */
    private function buildQuickChartUrlsFromConfig(array $planChartsConfig): array
    {
        $urls = [];

        foreach ($planChartsConfig as $key => $cfg) {
            $isPie = in_array($cfg['type'], ['pie','doughnut'], true);

            $chartConfig = [
                'type' => $cfg['type'],
                'data' => [
                    'labels'   => $cfg['labels'] ?? [],
                    'datasets' => $cfg['datasets'] ?? [],
                ],
                'options' => [
                    'plugins' => [
                        'legend' => [
                            'position' => 'bottom',
                            'labels' => [
                                'font' => ['size' => 16],
                            ],
                        ],
                        'title'  => [
                            'display' => !empty($cfg['title']),
                            'text'    => $cfg['title'] ?? '',
                            'font'    => ['size' => 18, 'weight' => '600'],
                        ],
                    ],
                    'responsive' => true,
                    'maintainAspectRatio' => false,
                ],
            ];

            // Más espacio para el título en pies
            if ($isPie) {
                $chartConfig['options']['layout'] = [
                    'padding' => ['top' => 16, 'bottom' => 4, 'left' => 4, 'right' => 4]
                ];
            }

            // Aumentar tamaño de etiquetas de ejes en barras
            if (($cfg['type'] ?? null) === 'bar') {
                $chartConfig['options']['scales'] = [
                    'x' => ['ticks' => ['font' => ['size' => 14]]],
                    'y' => ['ticks' => ['font' => ['size' => 14]]],
                ];
            }

            // Datalabels y tooltips en pies: "% (cantidad)"
            $extraParams = '';
            if ($isPie) {
                $extraParams = '&plugins=datalabels';
                $chartConfig['options']['plugins']['datalabels'] = [
                    'anchor'   => 'center',
                    'align'    => 'center',
                    'offset'   => 0,
                    'clamp'    => true,
                    'clip'     => false,
                    'font'     => ['weight' => '700', 'size' => 16],
                    'display'  => 'function(ctx){ const v = ctx.dataset.data?.[ctx.dataIndex]; return v > 0; }',
                    'formatter'=> 'function(value, ctx){ var ds=ctx.chart.data.datasets[0].data||[]; var s=ds.reduce((a,b)=>a+(+b||0),0); if(!s) return null; var p=Math.round((value/s)*100); return p + "% (" + value + ")"; }',
                ];

                $chartConfig['options']['plugins']['tooltip'] = [
                    'callbacks' => [
                        'label' => 'function(ctx){ var ds=ctx.dataset.data||[]; var s=ds.reduce((a,b)=>a+(+b||0),0); var v=ctx.parsed; var p=s?Math.round((v/s)*100):0; return ctx.label + ": " + v + " (" + p + "%)"; }'
                    ]
                ];
            }

            $encoded = urlencode(json_encode($chartConfig));
            $urls[$key] = "https://quickchart.io/chart?c={$encoded}&w=900&h=520&backgroundColor=white{$extraParams}";
        }

        return $urls;
    }
}
