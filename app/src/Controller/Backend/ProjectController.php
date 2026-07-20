<?php

namespace App\Controller\Backend;

use App\Entity\{Department, EmissionActivity, EmissionRecord, Plan, Position, Project, ProjectCompany, ProjectFundingSource, CrewMember, ProjectMembership, ProjectPhaseDate, User};
use App\Form\{ CrewMemberImportType, ProjectType, CrewMemberType, CrewMemberCollectionType };
use App\Repository\{ ProjectBillingDocumentRepository, ProjectRepository, CrewMemberRepository, PositionRepository, DepartmentRepository, EmissionRecordRepository, PlanRepository };
use App\Security\ProjectVoter;
use App\Enum\CommercialPhase;
use App\Service\ActiveProjectService;
use App\Entity\ProjectSubscription;
use App\Service\ProjectFeatureGate;
use App\Service\StripeInvoiceStorageService;
use App\Service\SustainabilityPlanCollaborationService;
use Gedmo\Translatable\Entity\Translation;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{ Request, Response, RedirectResponse, StreamedResponse, ResponseHeaderBag };
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/project', name: 'backend_project_')]
#[IsGranted('ROLE_USER')]
class ProjectController extends AbstractController
{
    private const DASHBOARD_PHASE_HEADERS = [
        ['code' => '01', 'translationKey' => 'backend.commercial_phases.elaboration'],
        ['code' => '02', 'translationKey' => 'backend.commercial_phases.implementation'],
        ['code' => '03', 'translationKey' => 'backend.commercial_phases.signage'],
        ['code' => '04', 'translationKey' => 'backend.commercial_phases.co2'],
        ['code' => '05', 'translationKey' => 'backend.commercial_phases.report'],
        ['code' => '06', 'translationKey' => 'backend.commercial_phases.compensation'],
        ['code' => '07', 'translationKey' => 'backend.commercial_phases.certification'],
    ];

    public function __construct(
        private readonly TranslatorInterface $t,
        private readonly ProjectFeatureGate $featureGate,
        private readonly ProjectBillingDocumentRepository $billingDocumentRepository,
        private readonly StripeInvoiceStorageService $invoiceStorageService,
        private readonly SustainabilityPlanCollaborationService $collaborationService,
    ) {}

    #[Route('/', name: 'index')]
    public function index(
        ProjectRepository $projectRepository,
        PlanRepository $planRepository,
        EmissionRecordRepository $emissionRecordRepository,
        ActiveProjectService $activeProjectService,
        Request $request
    ): Response {
        /** @var User $user */
        $user    = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN'); // SUPER_ADMIN incluido

        // Filtros GET
        $name     = trim((string) $request->query->get('name', ''));
        $type     = (string) $request->query->get('type', '');
        $country  = (string) $request->query->get('country', '');
        $owner    = trim((string) $request->query->get('owner', ''));
        $dateFrom = (string) $request->query->get('date_from', '');
        $dateTo   = (string) $request->query->get('date_to', '');

        // Paginación
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 10;
        $offset  = ($page - 1) * $perPage;
        $activeProject = $activeProjectService->getActiveProject();

        // Query base (membresías)
        $qb = $projectRepository->createQueryBuilder('p')
            ->leftJoin('p.projectMemberships', 'pm')
            ->leftJoin('pm.user', 'mu') // miembro
            ->leftJoin('p.user', 'cu')  // creador
            ->leftJoin('p.subscriptions', 'sub')
            ->addSelect('pm', 'mu', 'cu', 'sub');

        // Alcance por rol
        if (!$isAdmin) {
            $qb->andWhere('pm.user = :me')->setParameter('me', $user);
        }

        // Filtros
        if ($name !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :name')
               ->setParameter('name', '%'.mb_strtolower($name).'%');
        }
        if ($type !== '') {
            $qb->andWhere('p.type = :type')->setParameter('type', $type);
        }
        if ($country !== '') {
            $qb->andWhere('p.country = :country')->setParameter('country', $country);
        }
        if ($isAdmin && $owner !== '') {
            $qb->andWhere('LOWER(cu.email) LIKE :owner')
               ->setParameter('owner', '%'.mb_strtolower($owner).'%');
        }
        if ($dateFrom !== '') {
            try {
                $qb->andWhere('p.createdAt >= :from')
                   ->setParameter('from', new \DateTimeImmutable($dateFrom.' 00:00:00'));
            } catch (\Throwable $e) {}
        }
        if ($dateTo !== '') {
            try {
                $qb->andWhere('p.createdAt <= :to')
                   ->setParameter('to', new \DateTimeImmutable($dateTo.' 23:59:59'));
            } catch (\Throwable $e) {}
        }

        // Total
        $qbCount = clone $qb;
        $total = (int) $qbCount
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Lista filtrada completa para métricas y paginado consistente
        $query = $qb->select('DISTINCT p');

        if ($activeProject?->getId()) {
            $query
                ->addSelect('CASE WHEN p.id = :activeProjectId THEN 0 ELSE 1 END AS HIDDEN activeProjectOrder')
                ->setParameter('activeProjectId', $activeProject->getId())
                ->orderBy('activeProjectOrder', 'ASC')
                ->addOrderBy('p.name', 'ASC');
        } else {
            $query->orderBy('p.name', 'ASC');
        }

        $dashboardProjects = [];
        $dashboardEmissionProjects = [];
        $tierCounts = [
            ProjectSubscription::TIER_BASIC => 0,
            ProjectSubscription::TIER_STANDARD => 0,
            ProjectSubscription::TIER_PRO => 0,
        ];
        $planCounts = [
            'completo' => 0,
            'incompleto' => 0,
            'sin_plan' => 0,
        ];
        $emissionsTotal = 0;
        $billingDocumentsTotal = 0;

        $filteredProjects = (clone $query)
            ->getQuery()
            ->getResult();

        foreach ($filteredProjects as $project) {
            $projectTierCode = $this->featureGate->getTier($project, CommercialPhase::ELABORATION);
            if (!isset($tierCounts[$projectTierCode])) {
                $tierCounts[$projectTierCode] = 0;
            }
            $tierCounts[$projectTierCode]++;

            $plan = $planRepository->findOneBy(['project' => $project]);
            $projectPlanStatus = $plan?->getStatus();
            if ($projectPlanStatus === null) {
                $planCounts['sin_plan']++;
            } elseif (isset($planCounts[$projectPlanStatus])) {
                $planCounts[$projectPlanStatus]++;
            }

            $projectEmissionCount = (int) $emissionRecordRepository->count(['project' => $project]);
            $projectEmissionSum = (float) $emissionRecordRepository->createQueryBuilder('er')
                ->select('COALESCE(SUM(er.emission), 0)')
                ->andWhere('er.project = :project')
                ->setParameter('project', $project)
                ->getQuery()
                ->getSingleScalarResult();
            $projectBillingDocumentCount = (int) $this->billingDocumentRepository->count(['project' => $project]);
            $emissionsTotal += $projectEmissionSum;
            $billingDocumentsTotal += $projectBillingDocumentCount;

            $dashboardProject = $this->buildDashboardProjectRow(
                project: $project,
                plan: $plan,
                projectTierCode: $projectTierCode,
                emissionCount: $projectEmissionCount,
                emissionSum: $projectEmissionSum,
                billingDocumentCount: $projectBillingDocumentCount,
                isActive: $activeProject && $activeProject->getId() === $project->getId(),
            );
            $dashboardProjects[] = $dashboardProject;

            if ($projectEmissionCount > 0) {
                $dashboardEmissionProjects[] = [
                    'id' => $dashboardProject['id'],
                    'name' => $dashboardProject['name'],
                    'emissionSum' => $dashboardProject['emissionSum'],
                    'createdAt' => $dashboardProject['createdAt'],
                ];
            }
        }

        usort($dashboardEmissionProjects, static function (array $left, array $right): int {
            $leftCreatedAt = $left['createdAt'] ?? null;
            $rightCreatedAt = $right['createdAt'] ?? null;

            if ($leftCreatedAt instanceof \DateTimeInterface && $rightCreatedAt instanceof \DateTimeInterface) {
                $dateCompare = $rightCreatedAt <=> $leftCreatedAt;
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }
            } elseif ($leftCreatedAt instanceof \DateTimeInterface) {
                return -1;
            } elseif ($rightCreatedAt instanceof \DateTimeInterface) {
                return 1;
            }

            return strcmp((string) $left['name'], (string) $right['name']);
        });
        $dashboardEmissionChartHasMore = count($dashboardEmissionProjects) > 8;
        $dashboardProjectsForChart = array_slice($dashboardEmissionProjects, 0, 8);

        $dashboardProjects = array_slice($dashboardProjects, $offset, $perPage);

        $activePlan = null;
        $activeProjectEmissionCount = 0;
        $activeProjectEmissionSum = 0.0;
        $activeProjectBillingDocumentCount = 0;
        if ($activeProject) {
            $activePlan = $planRepository->findOneBy(['project' => $activeProject]);
            $activeProjectEmissionCount = (int) $emissionRecordRepository->count(['project' => $activeProject]);
            $activeProjectEmissionSum = (float) $emissionRecordRepository->createQueryBuilder('er')
                ->select('COALESCE(SUM(er.emission), 0)')
                ->andWhere('er.project = :project')
                ->setParameter('project', $activeProject)
                ->getQuery()
                ->getSingleScalarResult();
            $activeProjectBillingDocumentCount = (int) $this->billingDocumentRepository->count(['project' => $activeProject]);
        }

        $activeFilters = array_filter([
            $name,
            $type,
            $country,
            $owner,
            $dateFrom,
            $dateTo,
        ], static fn($value) => $value !== '' && $value !== null);

        return $this->render('backend/project/index.html.twig', [
            'projects'      => $dashboardProjects,
            'dashboardProjects' => $dashboardProjects,
            'dashboardTopEmissionProjects' => $dashboardProjectsForChart,
            'dashboardSummary' => [
                'totalProjects' => $total,
                'pageProjects' => count($dashboardProjects),
                'activeProject' => $activeProject,
                'activeProjectTierLabel' => $activeProject ? $this->featureGate->getPlanLabel($activeProject, CommercialPhase::ELABORATION) : null,
                'activeProjectTierCode' => $activeProject ? $this->featureGate->getTier($activeProject, CommercialPhase::ELABORATION) : null,
                'activeProjectPlanStatus' => $activePlan?->getStatus(),
                'activeProjectPlanStatusLabel' => $activePlan ? $this->t->trans('backend.plan.status.' . $activePlan->getStatus()) : null,
                'activeProjectPlanMeasures' => $activePlan ? $activePlan->getPlanMeasures()->count() : 0,
                'activeProjectEmissionCount' => $activeProjectEmissionCount,
                'activeProjectEmissionSum' => $activeProjectEmissionSum,
                'activeProjectBillingDocuments' => $activeProjectBillingDocumentCount,
                'tiers' => $tierCounts,
                'plans' => $planCounts,
                'emissionsTotal' => $emissionsTotal,
                'billingDocumentsTotal' => $billingDocumentsTotal,
                'activeFilters' => count($activeFilters),
                'hasActiveFilters' => count($activeFilters) > 0,
            ],
            'dashboardPhaseHeaders' => array_map(fn (array $phase): array => [
                'code' => $phase['code'],
                'label' => $this->t->trans($phase['translationKey']),
            ], self::DASHBOARD_PHASE_HEADERS),
            'dashboardEmissionChartHasMore' => $dashboardEmissionChartHasMore,
            'filters'       => [
                'name'      => $name,
                'type'      => $type,
                'country'   => $country,
                'owner'     => $owner,
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
            ],
            'is_admin'     => $isAdmin,
            'currentPage'  => $page,
            'totalPages'   => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    private function buildDashboardProjectRow(
        Project $project,
        ?Plan $plan,
        string $projectTierCode,
        int $emissionCount,
        float $emissionSum,
        int $billingDocumentCount,
        bool $isActive
    ): array {
        $planStatus = $plan?->getStatus();
        $planLabel = $planStatus !== null
            ? $this->t->trans('backend.plan.status.' . $planStatus)
            : $this->t->trans('backend.projects.dashboard.phases.common.pending');
        $planPhaseLabel = match ($planStatus) {
            'completo' => $this->t->trans('backend.projects.dashboard.phases.common.completed'),
            'incompleto' => $this->t->trans('backend.projects.dashboard.phases.common.in_progress'),
            default => $this->t->trans('backend.projects.dashboard.phases.common.pending'),
        };

        $planClass = match ($planStatus) {
            'completo' => 'bg-success',
            'incompleto' => 'bg-warning text-dark',
            default => 'bg-light text-muted border',
        };

        $emissionPhaseLabel = $emissionCount > 0
            ? number_format($emissionSum, 1, ',', '.')
            : '—';
        $emissionPhaseNote = $emissionCount > 0
            ? 'kgCO₂e'
            : $this->t->trans('backend.projects.dashboard.phases.co2.no_records');
        $implementationTierLabel = $this->featureGate->getPlanLabel($project, CommercialPhase::IMPLEMENTATION);
        $hasImplementationActivity = $plan instanceof Plan
            && $this->collaborationService->hasImplementationActivity($plan);
        $elaborationComplete = $planStatus === 'completo';

        if (!$elaborationComplete) {
            $implementationStateLabel = $this->t->trans('backend.projects.dashboard.phases.implementation.available_after_elaboration');
            $implementationStateClass = 'bg-light text-muted border';
            $implementationIcon = 'bi-lock';
            $implementationActionLabel = $this->t->trans('backend.projects.dashboard.phases.elaboration.continue');
            $implementationActionTarget = 'plan';
        } elseif ($hasImplementationActivity) {
            $implementationStateLabel = $this->t->trans('backend.projects.dashboard.phases.common.in_progress');
            $implementationStateClass = 'bg-warning text-dark';
            $implementationIcon = 'bi-hourglass-split';
            $implementationActionLabel = $this->t->trans('backend.projects.dashboard.phases.implementation.continue');
            $implementationActionTarget = 'implementation';
        } else {
            $implementationStateLabel = $this->t->trans('backend.projects.dashboard.phases.implementation.ready');
            $implementationStateClass = 'bg-info text-dark';
            $implementationIcon = 'bi-play-fill';
            $implementationActionLabel = $this->t->trans('backend.projects.dashboard.phases.implementation.open');
            $implementationActionTarget = 'implementation';
        }

        return [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'typeKey' => $project->getType(),
            'typeLabel' => $this->translateProjectType($project->getType() ?? ''),
            'country' => $project->getCountry(),
            'ownerLabel' => $this->formatProjectOwner($project),
            'tierCode' => $projectTierCode,
            'tierLabel' => $this->featureGate->getPlanLabel($project, CommercialPhase::ELABORATION),
            'tierDescription' => $this->featureGate->getPlanDescription($project, CommercialPhase::ELABORATION),
            'isActive' => $isActive,
            'createdAt' => $project->getCreatedAt(),
            'plan' => [
                'exists' => $plan !== null,
                'status' => $planStatus,
                'label' => $planLabel,
                'class' => $planClass,
                'measureCount' => $plan?->getPlanMeasures()->count() ?? 0,
                'statusChangedAt' => $plan?->getStatusChangedAt(),
            ],
            'emissionCount' => $emissionCount,
            'emissionSum' => $emissionSum,
            'billingDocumentCount' => $billingDocumentCount,
            'phases' => [
                [
                    'code' => '01',
                    'label' => $this->t->trans('backend.commercial_phases.elaboration'),
                    'stateLabel' => $planPhaseLabel,
                    'stateClass' => $planClass,
                    'icon' => $plan?->getStatus() === 'completo'
                        ? 'bi-check-lg'
                        : ($plan ? 'bi-hourglass-split' : 'bi-dash-lg'),
                    'note' => '',
                    'title' => '01 · '.$this->t->trans('backend.commercial_phases.elaboration').' · '.$planPhaseLabel,
                    'isReal' => $plan !== null,
                    'tierLabel' => $this->featureGate->getPlanLabel($project, CommercialPhase::ELABORATION),
                    'primaryTarget' => $elaborationComplete ? 'elaboration_done' : 'plan',
                    'primaryLabel' => $this->t->trans($plan === null
                        ? 'backend.projects.dashboard.phases.elaboration.create'
                        : ($elaborationComplete
                            ? 'backend.projects.dashboard.phases.elaboration.view_close'
                            : 'backend.projects.dashboard.phases.elaboration.continue')),
                    'billingPhase' => CommercialPhase::ELABORATION->value,
                ],
                [
                    'code' => '02',
                    'label' => $this->t->trans('backend.commercial_phases.implementation'),
                    'stateLabel' => $implementationStateLabel,
                    'stateClass' => $implementationStateClass,
                    'icon' => $implementationIcon,
                    'note' => '',
                    'title' => '02 · '.$this->t->trans('backend.commercial_phases.implementation').' · '.$implementationStateLabel,
                    'isReal' => $elaborationComplete,
                    'tierLabel' => $implementationTierLabel,
                    'primaryTarget' => $implementationActionTarget,
                    'primaryLabel' => $implementationActionLabel,
                    'billingPhase' => CommercialPhase::IMPLEMENTATION->value,
                ],
                // TODO: Implementar estado real de Cartelería cuando exista módulo funcional.
                [
                    'code' => '03',
                    'label' => $this->t->trans('backend.commercial_phases.signage'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'stateClass' => 'bg-light text-muted border',
                    'icon' => 'bi-download',
                    'note' => '',
                    'title' => '03 · '.$this->t->trans('backend.commercial_phases.signage').' · '.$this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'isReal' => false,
                ],
                [
                    'code' => '04',
                    'label' => $this->t->trans('backend.commercial_phases.co2'),
                    'stateLabel' => $emissionPhaseLabel,
                    'stateClass' => $emissionCount > 0
                        ? 'bg-info text-dark'
                        : 'bg-light text-muted border',
                    'icon' => 'bi-cloud-arrow-up-fill',
                    'note' => $emissionPhaseNote,
                    'title' => '04 · '.$this->t->trans('backend.commercial_phases.co2').' · ' . ($emissionCount > 0
                        ? $this->t->trans('backend.projects.dashboard.phases.common.in_progress').' · '.$emissionPhaseLabel.' '.$emissionPhaseNote
                        : $this->t->trans('backend.projects.dashboard.phases.common.pending').' · '.$emissionPhaseNote),
                    'isReal' => $emissionCount > 0,
                    'primaryTarget' => 'emissions',
                    'primaryLabel' => $this->t->trans('backend.projects.dashboard.phases.co2.open'),
                ],
                // TODO: Implementar estado real de Informe final cuando exista generación/cierre de informe.
                [
                    'code' => '05',
                    'label' => $this->t->trans('backend.commercial_phases.report'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'stateClass' => 'bg-light text-muted border',
                    'icon' => 'bi-file-earmark-text',
                    'note' => '',
                    'title' => '05 · '.$this->t->trans('backend.commercial_phases.report').' · '.$this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'isReal' => false,
                ],
                // TODO: Implementar estado real de Compensación cuando exista flujo de compensación.
                [
                    'code' => '06',
                    'label' => $this->t->trans('backend.commercial_phases.compensation'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'stateClass' => 'bg-light text-muted border',
                    'icon' => 'bi-tree',
                    'note' => '',
                    'title' => '06 · '.$this->t->trans('backend.commercial_phases.compensation').' · '.$this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'isReal' => false,
                ],
                // TODO: Implementar estado real de Certificación cuando exista flujo/cierre de certificación.
                [
                    'code' => '07',
                    'label' => $this->t->trans('backend.commercial_phases.certification'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'stateClass' => 'bg-light text-muted border',
                    'icon' => 'bi-award',
                    'note' => '',
                    'title' => '07 · '.$this->t->trans('backend.commercial_phases.certification').' · '.$this->t->trans('backend.projects.dashboard.phases.common.coming_soon'),
                    'isReal' => false,
                ],
            ],
        ];
    }

    private function translateProjectType(string $type): string
    {
        return match ($type) {
            'rodaje' => $this->t->trans('backend.aux.project_type.filming'),
            'evento' => $this->t->trans('backend.aux.project_type.event'),
            default => $this->t->trans('backend.aux.project_type.generic'),
        };
    }

    private function formatProjectOwner(Project $project): string
    {
        $owner = $project->getUser();
        if (!$owner) {
            return '—';
        }

        $parts = array_filter([
            trim((string) $owner->getName()),
            trim((string) $owner->getSurnames()),
        ]);
        $name = trim(implode(' ', $parts));

        if ($name === '') {
            return '—';
        }

        return $name;
    }

    #[Route('/new', name: 'new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        ActiveProjectService $activeProjectService
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $project = new Project();
        $this->ensureBasicSubscriptions($project);

        // Fases por defecto
        foreach (['actividad', 'preproduccion', 'postproduccion'] as $phaseName) {
            $phaseDate = new ProjectPhaseDate();
            $phaseDate->setPhase($phaseName);
            $project->addPhaseDate($phaseDate);
        }
        $this->reorderPhases($project);

        $form = $this->createForm(ProjectType::class, $project, [
            'show_commercial_tier' => false,
        ]);
        $form->handleRequest($request);
        $this->normalizeProject($project);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $creator */
            $creator = $this->getUser();

            $project->setUser($creator);

            $membership = (new ProjectMembership())
                ->setUser($creator)
                ->setProject($project)
                ->setProjectRole('owner');

            $project->addProjectMembership($membership);

            $this->ensureBasicSubscriptions($project);

            $em->persist($project);
            $em->persist($membership);
            $em->flush();

            $activeProjectService->setActiveProject($project);

            $this->addFlash('success', 'backend.projects.flash.created');
            return $this->redirectToRoute('backend_project_created', ['id' => $project->getId()]);
        }

        return $this->render('backend/project/form.html.twig', [
            'form' => $form->createView(),
            'edit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $this->reorderPhases($project);

        // Guardar fases originales antes de modificar
        $originalPhases = new ArrayCollection();
        foreach ($project->getPhaseDates() as $phaseDate) {
            $originalPhases->add($phaseDate);
        }

        $form = $this->createForm(ProjectType::class, $project, [
            'show_commercial_tier' => false,
        ]);
        $form->handleRequest($request);
        $this->normalizeProject($project);

        if ($form->isSubmitted() && $form->isValid()) {

            // Eliminar fases eliminadas en el formulario
            foreach ($originalPhases as $originalPhase) {
                if (!$project->getPhaseDates()->contains($originalPhase)) {
                    $em->remove($originalPhase);
                }
            }

            $em->flush();

            $this->addFlash('success', 'backend.projects.flash.updated');
            return $this->redirectToRoute('backend_project_index');
        }

        $lockedPhases = [];
        foreach ($project->getPhaseDates() as $phaseDate) {
            $lockedPhases[$phaseDate->getId()] = $em->getRepository(EmissionRecord::class)
                ->count(['phase' => $phaseDate]) > 0;
        }

        return $this->render('backend/project/form.html.twig', [
            'form' => $form->createView(),
            'edit' => true,
            'project' => $project,
            'lockedPhases' => $lockedPhases,
            'projectTier' => $this->featureGate->getTier($project, CommercialPhase::ELABORATION),
            'projectTierLabel' => $this->featureGate->getPlanLabel($project, CommercialPhase::ELABORATION),
            'projectTierSummary' => $this->featureGate->getPlanDescription($project, CommercialPhase::ELABORATION),
            'projectUpgradeUrl' => $this->generateUrl('backend_project_billing', [
                'id' => $project->getId(),
                'phase' => CommercialPhase::ELABORATION->value,
                'from' => 'project',
            ]),
        ]);
    }

    #[Route('/{id}/created', name: 'created')]
    public function created(int $id, ProjectRepository $projectRepository, ActiveProjectService $activeProjectService): Response
    {
        $project = $projectRepository->find($id);
        if (!$project instanceof Project) {
            $this->addFlash('warning', 'backend.projects.flash.project_not_found');

            return $this->redirectToRoute('backend_project_index');
        }

        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $activeProjectService->setActiveProject($project);

        return $this->render('backend/project/created.html.twig', [
            'project' => $project,
            'projectTier' => $this->featureGate->getTier($project, CommercialPhase::ELABORATION),
            'projectTierLabel' => $this->featureGate->getPlanLabel($project, CommercialPhase::ELABORATION),
            'projectTierSummary' => $this->featureGate->getPlanDescription($project, CommercialPhase::ELABORATION),
            'projectUpgradeUrl' => $this->generateUrl('backend_project_billing', [
                'id' => $project->getId(),
                'phase' => CommercialPhase::ELABORATION->value,
                'from' => 'project',
            ]),
            'continueUrl' => $this->generateUrl('backend_project_index'),
        ]);
    }

    private function reorderPhases(Project $project): void
    {
        $phases = $project->getPhaseDates()->toArray();
        $orderedPhases = [];

        foreach (['preproduccion', 'actividad', 'postproduccion'] as $key) {
            foreach ($phases as $phaseDate) {
                if ($phaseDate->getPhase() === $key) {
                    $orderedPhases[] = $phaseDate;
                    break;
                }
            }
        }

        $project->getPhaseDates()->clear();
        foreach ($orderedPhases as $phaseDate) {
            $project->addPhaseDate($phaseDate);
        }
    }

    #[Route('/{id}/clone', name: 'clone', methods: ['GET'])]
    public function clone(Project $project, EntityManagerInterface $em): RedirectResponse
    {
        /** @var User $creator */
        $creator = $this->getUser();

        // 1) Clonar datos básicos del proyecto
        $newProject = new Project();
        $this->ensureBasicSubscriptions($newProject);
        $newProject
            ->setName($project->getName() . ' (copia)')
            ->setType($project->getType())
            ->setCountry($project->getCountry())
            ->setEmissionSourceName($project->getEmissionSourceName())
            ->setUser($creator)
            ->setCreatedAt(new \DateTimeImmutable());

        // ---- copiar campos de RODAJE ----
        $newProject
            ->setFilmingType($project->getFilmingType())
            ->setFilmingGenre($project->getFilmingGenre())
            ->setDistributionMedia($project->getDistributionMedia());

        // ---- copiar campos de EVENTO ----
        $newProject
            ->setEventTypePrimary($project->getEventTypePrimary())
            ->setEventModality($project->getEventModality())
            ->setEventAttendeesCount($project->getEventAttendeesCount())
            ->setEventOnlineConnections($project->getEventOnlineConnections());

        // ---- copiar campos COMUNES (texto) ----
        $newProject
            ->setPresupuesto($project->getPresupuesto())
            ->setMainLocation($project->getMainLocation())
            ->setEcoManagerStatus($project->getEcoManagerStatus())
            ->setEpisodios($project->getEpisodios())
            ->setDuracionEpisodio($project->getDuracionEpisodio());

        foreach ($project->getProjectCompanies() as $company) {
            $newCompany = (new ProjectCompany())
                ->setType($company->getType())
                ->setName($company->getName())
                ->setPosition($company->getPosition());
            $newProject->addProjectCompany($newCompany);
        }

        foreach ($project->getProjectFundingSources() as $source) {
            $newSource = (new ProjectFundingSource())
                ->setType($source->getType())
                ->setName($source->getName())
                ->setPercentage($source->getPercentage())
                ->setPosition($source->getPosition());
            $newProject->addProjectFundingSource($newSource);
        }

        $this->normalizeProject($newProject);

        $em->persist($newProject);
        $em->flush(); // obtener ID

        // 2) OWNER = usuario autenticado
        $membership = (new ProjectMembership())
            ->setUser($creator)
            ->setProject($newProject)
            ->setProjectRole('owner');

        $newProject->addProjectMembership($membership);
        $em->persist($membership);

        // 3) Clonar fases
        foreach ($project->getPhaseDates() as $phase) {
            $newPhase = new ProjectPhaseDate();
            $newPhase
                ->setProject($newProject)
                ->setPhase($phase->getPhase())
                ->setStartDate($phase->getStartDate())
                ->setEndDate($phase->getEndDate());

            $em->persist($newPhase);
        }

        $em->flush();

        $this->addFlash('success', 'backend.projects.flash.cloned');

        return $this->redirectToRoute('backend_project_index');
    }

    private function ensureBasicSubscriptions(Project $project): void
    {
        $this->ensureBasicSubscription($project, CommercialPhase::ELABORATION);
        $this->ensureBasicSubscription($project, CommercialPhase::IMPLEMENTATION);
    }

    private function ensureBasicSubscription(Project $project, CommercialPhase $phase): ProjectSubscription
    {
        $subscription = $project->getSubscriptionForPhase($phase);
        if (!$subscription) {
            $subscription = new ProjectSubscription();
            $subscription->setPhase($phase);
            $project->addSubscription($subscription);
        }

        $subscription
            ->setTier(ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_SYSTEM)
            ->setCurrency('EUR')
            ->setPaidAmountCents(null)
            ->setPaymentReference(null)
            ->setStripeCheckoutSessionId(null)
            ->setStripePaymentIntentId(null)
            ->setStripeInvoiceId(null)
            ->setStripeCustomerId(null)
            ->setStripeHostedInvoiceUrl(null)
            ->setStripeInvoicePdfUrl(null)
            ->setLastPaymentStatus(null)
            ->setPaidAt(null)
            ->setTargetTier(null);

        return $subscription;
    }

    private function syncCommercialTier(Project $project, string $tier, EntityManagerInterface $em): void
    {
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION) ?? new ProjectSubscription();
        $subscription->setPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setProject($project)
            ->setTier(in_array($tier, [ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO], true) ? $tier : ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL)
            ->setPaidAmountCents(null)
            ->setPaymentReference(null)
            ->setStripeCheckoutSessionId(null)
            ->setStripePaymentIntentId(null)
            ->setStripeInvoiceId(null)
            ->setStripeCustomerId(null)
            ->setStripeHostedInvoiceUrl(null)
            ->setStripeInvoicePdfUrl(null)
            ->setLastPaymentStatus(null)
            ->setPaidAt(null)
            ->setTargetTier(null);

        if (!$project->getSubscriptionForPhase(CommercialPhase::ELABORATION)) {
            $project->addSubscription($subscription);
            $em->persist($subscription);
        }
    }

    private function normalizeProject(Project $project): void
    {
        $project->normalizeState();
    }

    #[Route('/{id}/edit-crew', name: 'edit_crew')]
    public function editCrew(
        Project $project,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        // Miembros originales
        $originalMembers = new ArrayCollection();
        foreach ($project->getCrewMembers() as $member) {
            $originalMembers->add($member);
        }

        $form = $this->createForm(CrewMemberCollectionType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($originalMembers as $originalMember) {
                if (!$project->getCrewMembers()->contains($originalMember)) {
                    $em->remove($originalMember);
                }
            }

            $em->flush();

            $this->addFlash('success', 'backend.projects.flash.crew_updated');
            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        return $this->render('backend/project/edit_crew.html.twig', [
            'form'    => $form->createView(),
            'project' => $project,
        ]);
    }

    #[Route('/crew/template/download', name: 'template_download', methods: ['GET'])]
    public function downloadCrewTemplate(
        PositionRepository $positionRepository,
        DepartmentRepository $departmentRepository,
        ActiveProjectService $activeProjectService,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet();

        // Hoja principal
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->t->trans('backend.projects.crew.template.sheet_title'));

        // Encabezados
        $headers = [
            $this->t->trans('backend.projects.crew.template.headers.name'),
            $this->t->trans('backend.projects.crew.template.headers.last_name'),
            $this->t->trans('backend.projects.crew.template.headers.position'),
            $this->t->trans('backend.projects.crew.template.headers.department'),
            $this->t->trans('backend.projects.crew.template.headers.email'),
            $this->t->trans('backend.projects.crew.template.headers.phone'),
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        // === 1) Filtrado por tipo de proyecto ===
        $projectType = $activeProjectService->getActiveProject()?->getType(); // 'rodaje' | 'evento' | null
        // Criterio: permitir departamentos genéricos (null) y los del tipo
        $allDepartments = $departmentRepository->findAll();
        $allowedDepartments = array_values(array_filter($allDepartments, function (\App\Entity\Department $d) use ($projectType) {
            $pt = $d->getProjectType(); // null | 'rodaje' | 'evento'
            return $pt === null || ($projectType !== null && $pt === $projectType);
        }));

        // Indice rápido por ID para validar posiciones
        $allowedDeptIds = array_fill_keys(array_map(fn($d) => $d->getId(), $allowedDepartments), true);

        // Cargos sólo de departamentos permitidos
        $allPositions = $positionRepository->findAll();
        $positions = array_values(array_filter($allPositions, function (\App\Entity\Position $p) use ($allowedDeptIds) {
            $dept = $p->getDepartment();
            return $dept && isset($allowedDeptIds[$dept->getId()]);
        }));

        // Si por algún motivo no hay nada permitido, caemos a todos (evita plantilla vacía)
        if (empty($allowedDepartments)) {
            $allowedDepartments = $allDepartments;
            $allowedDeptIds = array_fill_keys(array_map(fn($d) => $d->getId(), $allowedDepartments), true);
            $positions = array_values(array_filter($allPositions, function (\App\Entity\Position $p) use ($allowedDeptIds) {
                $dept = $p->getDepartment();
                return $dept && isset($allowedDeptIds[$dept->getId()]);
            }));
        }

        // === 2) Hoja oculta "Listas" con el mapeo Departamento→Cargo y lista única de Deptos ===
        $listsTitle = 'Listas';
        $listSheet = new Worksheet($spreadsheet, $listsTitle);
        $spreadsheet->addSheet($listSheet);

        // A: Departamento (por fila), B: Cargo (por fila)
        $rowAB = 1;
        foreach ($positions as $pos) {
            $dept = $pos->getDepartment();
            if (!$dept) { continue; }
            $listSheet->setCellValue("A{$rowAB}", $dept->getName());
            $listSheet->setCellValue("B{$rowAB}", $pos->getName());
            $rowAB++;
        }
        $mapCount = $rowAB - 1;

        // Lista única de departamentos permitidos (columna D)
        $uniqueDeptNames = [];
        foreach ($allowedDepartments as $d) {
            $uniqueDeptNames[$d->getName()] = true;
        }
        $uniqueDeptNames = array_keys($uniqueDeptNames);
        sort($uniqueDeptNames, SORT_NATURAL | SORT_FLAG_CASE);

        $rowD = 1;
        foreach ($uniqueDeptNames as $dn) {
            $listSheet->setCellValue("D{$rowD}", $dn);
            $rowD++;
        }
        $deptCount = $rowD - 1;

        $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // === 3) Validaciones de datos (dependientes) ===
        $maxRows = 100;

        // Departamento (col D): lista de 'Listas'!D1:D{deptCount}
        for ($row = 2; $row <= $maxRows; $row++) {
            $dvDept = new DataValidation();
            $dvDept->setType(DataValidation::TYPE_LIST);
            $dvDept->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $dvDept->setAllowBlank(true);
            $dvDept->setShowDropDown(true);
            $dvDept->setFormula1("'{$listsTitle}'!\$D\$1:\$D\$" . max(1, $deptCount));
            $sheet->getCell("D{$row}")->setDataValidation($dvDept);
        }

        // Cargo (col C): dependiente del valor de D{row}
        for ($row = 2; $row <= $maxRows; $row++) {
            $formula = sprintf(
                '=OFFSET(%s!$B$1, MATCH($D%d, %s!$A:$A, 0)-1, 0, COUNTIF(%s!$A:$A, $D%d), 1)',
                $listsTitle, $row, $listsTitle, $listsTitle, $row
            );
            $dvPos = new DataValidation();
            $dvPos->setType(DataValidation::TYPE_LIST);
            $dvPos->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $dvPos->setAllowBlank(true);
            $dvPos->setShowDropDown(true);
            $dvPos->setFormula1($formula);
            $sheet->getCell("C{$row}")->setDataValidation($dvPos);
        }

        // === 4) Filas de ejemplo COHERENTES con el tipo ===
        $exampleDept = $uniqueDeptNames[0] ?? $this->t->trans('backend.projects.crew.template.example.department');

        // Busca 1–2 cargos que pertenezcan a ese departamento ejemplo dentro del mapeo filtrado
        $posExamples = [];
        if ($mapCount > 0 && $deptCount > 0) {
            for ($r = 1; $r <= $mapCount; $r++) {
                $dn = (string)$listSheet->getCell("A{$r}")->getValue();
                $pn = (string)$listSheet->getCell("B{$r}")->getValue();
                if ($dn === $exampleDept && $pn !== '') {
                    if (!in_array($pn, $posExamples, true)) {
                        $posExamples[] = $pn;
                    }
                    if (count($posExamples) >= 2) break;
                }
            }
        }

        // Si no logramos encontrar cargos de ejemplo coherentes, cae a un placeholder
        $examplePos1 = $posExamples[0] ?? $this->t->trans('backend.projects.crew.template.example.position');
        $examplePos2 = $posExamples[1] ?? $examplePos1;

        // Relleno de ejemplos
        $sheet->fromArray(
            [
                $this->t->trans('backend.projects.crew.template.example.name1'),
                $this->t->trans('backend.projects.crew.template.example.last_name1'),
                $examplePos1, $exampleDept,
                'ana.perez@email.com',
                '+34 600 123 456'
            ],
            null,
            'A2'
        );
        $sheet->fromArray(
            [
                $this->t->trans('backend.projects.crew.template.example.name2'),
                $this->t->trans('backend.projects.crew.template.example.last_name2'),
                $examplePos2, $exampleDept,
                'luis.garcia@email.com',
                '+34 600 987 654'
            ],
            null,
            'A3'
        );

        // === 5) Hoja "Referencias" (informativa) solo con permitidos ===
        $infoSheet = new Worksheet($spreadsheet, $this->t->trans('backend.projects.crew.template.info_sheet'));
        $spreadsheet->addSheet($infoSheet);

        $infoSheet->setCellValue('A1', $this->t->trans('backend.projects.crew.template.info_headers.departments'));
        $infoSheet->setCellValue('B1', $this->t->trans('backend.projects.crew.template.info_headers.sample_position'));

        $r = 2;
        foreach ($uniqueDeptNames as $dn) {
            $infoSheet->setCellValue("A{$r}", $dn);
            $r++;
        }

        $seen = [];
        $r = 2;
        for ($i = 1; $i <= $mapCount; $i++) {
            $dn = (string)$listSheet->getCell("A{$i}")->getValue();
            $pn = (string)$listSheet->getCell("B{$i}")->getValue();
            if ($dn !== '' && $pn !== '' && !isset($seen[$dn])) {
                $infoSheet->setCellValue("B{$r}", $pn);
                $seen[$dn] = true;
                $r++;
            }
        }

        // Anchos
        foreach (['A','B','C','D','E','F'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $infoSheet->getColumnDimension('A')->setAutoSize(true);
        $infoSheet->getColumnDimension('B')->setAutoSize(true);

        // Descargar
        $filename = $this->t->trans('backend.projects.crew.template.filename');

        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/{id}/import-crew', name: 'import_crew', methods: ['POST'])]
    public function importCrew(
        Project $project,
        Request $request,
        EntityManagerInterface $em
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $file = $request->files->get('crewFile');
        if (!$file) {
            $this->addFlash('danger', 'backend.projects.flash.crew_import_no_file');
            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        [$ok, $messages] = $this->processCrewFile($file, $project, $em);

        if (!$ok) {
            foreach ($messages as $msg) {
                $this->addFlash('danger', $msg);
            }
        } else {
            $em->flush();
            $this->addFlash('success', 'backend.projects.flash.crew_import_ok');
            // avisos no bloqueantes
            foreach ($messages as $warn) {
                $this->addFlash('warning', $warn);
            }
        }

        return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
    }

    private function processCrewFile($file, Project $project, EntityManagerInterface $em): array
    {
        if (!$file) {
            return [false, [$this->t->trans('backend.projects.crew.import.errors.no_file')]];
        }

        try {
            $spreadsheet = IOFactory::load($file->getPathname());
        } catch (\Throwable $e) {
            return [false, [$this->t->trans('backend.projects.crew.import.errors.read_failed')]];
        }

        $sheet = $spreadsheet->getActiveSheet();

        // Normalizador simple (minúsculas + quitar tildes básicas)
        $norm = function (?string $s): string {
            $s = trim((string) $s);
            $s = mb_strtolower($s);
            $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
            return $s;
        };

        // === Cabeceras (fila 1), tolera columnas extra ===
        $headerCells = [];
        $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn()); // p.ej. "G" -> 7
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col); // 1 -> "A"
            $val = (string) $sheet->getCell($letter . '1')->getValue();
            if ($val !== '') {
                $headerCells[$col] = $val;
            }
        }

        // Aliases por campo admitidos (ES/EN)
        $aliases = [
            'name'       => ['nombre', 'name', 'first name', 'firstname', 'first_name'],
            'last_name'  => ['apellido', 'apellidos', 'last name', 'lastname', 'last_name', 'surname'],
            'position'   => ['cargo', 'puesto', 'position', 'role', 'job title', 'job'],
            'department' => ['departamento', 'department'],
            'email'      => ['email', 'e-mail', 'mail', 'correo', 'correo electronico', 'email address'],
            'phone'      => ['telefono', 'teléfono', 'phone', 'telephone', 'phone number', 'mobile'],
        ];

        // Mapea columna → campo
        $colIdx = [
            'name'       => null,
            'last_name'  => null,
            'position'   => null,
            'department' => null,
            'email'      => null,
            'phone'      => null,
        ];

        foreach ($headerCells as $colNum => $raw) {
            $h = $norm($raw);
            foreach ($aliases as $field => $list) {
                if (in_array($h, $list, true) && $colIdx[$field] === null) {
                    $colIdx[$field] = $colNum;
                    break;
                }
            }
        }

        // Campo mínimo requerido
        if ($colIdx['name'] === null) {
            return [false, [$this->t->trans('backend.projects.crew.import.errors.bad_format')]];
        }

        $errors   = [];
        $warnings = [];
        $projectType = $project->getType(); // 'rodaje' | 'evento'

        // Itera filas con datos
        $maxRow = $sheet->getHighestRow();
        for ($i = 2; $i <= $maxRow; $i++) {
            $get = function (?int $col) use ($sheet, $i): string {
                if (!$col) return '';
                $letter = Coordinate::stringFromColumnIndex($col);
                return trim((string) $sheet->getCell($letter . $i)->getValue());
            };

            $name           = $get($colIdx['name']);
            $lastName       = $get($colIdx['last_name']);
            $positionName   = $get($colIdx['position']);
            $departmentName = $get($colIdx['department']);
            $email          = $get($colIdx['email']);
            $phone          = $get($colIdx['phone']);

            // Fila vacía
            if ($name === '' && $lastName === '' && $positionName === '' && $departmentName === '' && $email === '' && $phone === '') {
                continue;
            }

            if ($name === '') {
                $errors[] = $this->t->trans('backend.projects.crew.import.errors.name_required', ['%row%' => $i]);
                continue;
            }

            // Buscar/crear por email+proyecto
            $member = null;
            if ($email !== '') {
                $member = $em->getRepository(CrewMember::class)->findOneBy([
                    'email'   => $email,
                    'project' => $project,
                ]);
            }
            if (!$member) {
                $member = new CrewMember();
            }

            $member->setProject($project);
            $member->setName($name);
            $member->setLastName($lastName !== '' ? $lastName : null);
            $member->setEmail($email !== '' ? $email : null);
            $member->setPhone($phone !== '' ? $phone : null);

            $projectType = $project->getType(); // 'rodaje' | 'evento'

            /// Department por nombre y tipo del proyecto (o genérico)
            $department = null;
            if ($departmentName !== '') {
                $department = $this->resolveDepartmentByAnyLocale($em, $departmentName, $projectType);
                if (!$department) {
                    $errors[] = $this->t->trans('backend.projects.crew.import.errors.department_not_found', [
                        '%line%'       => $i,
                        '%dept%'      => $departmentName,
                        '%type%' => $projectType,
                    ]);
                    continue;
                }
            }

            // Position (acotada por departamento si existe)
            $position = null;
            if ($positionName !== '') {
                $position = $this->resolvePositionByAnyLocale($em, $positionName, $department);
                if (!$position) {
                    $errors[] = $this->t->trans('backend.projects.crew.import.errors.position_not_found', [
                        '%row%'  => $i,
                        '%pos%'  => $positionName,
                        '%dept%' => $department?->getName() ?? '',
                    ]);
                    continue;
                }
            }

            // Coherencia Department/Position
            if ($position && $position->getDepartment()) {
                $posDept = $position->getDepartment();
                if ($department && $department->getId() !== $posDept->getId()) {
                    $warnings[] = $this->t->trans('backend.projects.crew.import.warnings.position_overrides_department', [
                        '%row%'  => $i,
                        '%pos%'  => $positionName,
                        '%dept%' => $posDept->getName(),
                    ]);
                }
                $member->setDepartment($posDept);
            } else {
                $member->setDepartment($department);
            }

            $member->setPosition($position ?: null);
            $em->persist($member);
        }

        return [count($errors) === 0, array_merge($warnings, $errors)];
    }

    private function resolveDepartmentByAnyLocale(
        EntityManagerInterface $em,
        string $name,
        ?string $projectType // 'rodaje' | 'evento' | null
    ): ?Department {
        $nameLower = mb_strtolower(trim($name));

        // 1) Match por nombre base (p.ej. español)
        $qb = $em->getRepository(Department::class)->createQueryBuilder('d')
            ->andWhere('LOWER(d.name) = :n')->setParameter('n', $nameLower);
        if ($projectType !== null) {
            $qb->andWhere('(d.projectType IS NULL OR d.projectType = :t)')->setParameter('t', $projectType);
        }
        $dep = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($dep) return $dep;

        // 2) Match por traducción (en, …)
        $qbT = $em->createQueryBuilder()
            ->select('d')
            ->from(Department::class, 'd')
            ->join(Translation::class, 't', 'WITH',
                't.objectClass = :cls AND t.field = :field AND t.foreignKey = d.id'
            )
            ->andWhere('LOWER(t.content) = :n')
            ->setParameter('cls', Department::class)
            ->setParameter('field', 'name')
            ->setParameter('n', $nameLower);
        if ($projectType !== null) {
            $qbT->andWhere('(d.projectType IS NULL OR d.projectType = :t)')->setParameter('t', $projectType);
        }
        return $qbT->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function resolvePositionByAnyLocale(
        EntityManagerInterface $em,
        string $name,
        ?Department $department
    ): ?Position {
        $nameLower = mb_strtolower(trim($name));

        // 1) Match por nombre base
        $qb = $em->getRepository(Position::class)->createQueryBuilder('p')
            ->andWhere('LOWER(p.name) = :n')->setParameter('n', $nameLower);
        if ($department) {
            $qb->andWhere('p.department = :d')->setParameter('d', $department);
        }
        $pos = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($pos) return $pos;

        // 2) Match por traducción
        $qbT = $em->createQueryBuilder()
            ->select('p')
            ->from(Position::class, 'p')
            ->join(Translation::class, 't', 'WITH',
                't.objectClass = :cls AND t.field = :field AND t.foreignKey = p.id'
            )
            ->andWhere('LOWER(t.content) = :n')
            ->setParameter('cls', Position::class)
            ->setParameter('field', 'name')
            ->setParameter('n', $nameLower);
        if ($department) {
            $qbT->andWhere('p.department = :d')->setParameter('d', $department);
        }
        return $qbT->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        // Autorización: ADMIN o miembro del proyecto
        if (!$this->isGranted('ROLE_ADMIN')) {
            $currentUser = $this->getUser();
            $isMember = false;
            foreach ($project->getProjectMemberships() as $m) {
                if ($m->getUser() === $currentUser) { $isMember = true; break; }
            }
            if (!$isMember) { throw $this->createAccessDeniedException(); }
        }

        if ($this->isCsrfTokenValid('delete'.$project->getId(), $request->request->get('_token'))) {
            try {
                $plan = $em->getRepository(Plan::class)->findOneBy(['project' => $project]);
                $emissions = $em->getRepository(EmissionRecord::class)->findBy(['project' => $project]);

                if ($emissions) {
                    $this->addFlash('danger', 'backend.projects.flash.delete_has_emissions');
                    return $this->redirectToRoute('backend_project_index');
                }

                if ($plan) {
                    $measures = count($plan->getPlanMeasures());
                    if ($measures) {
                        $this->addFlash('danger', $this->t->trans('backend.projects.flash.delete_has_measures', [
                            '%count%' => $measures
                        ]));
                        return $this->redirectToRoute('backend_project_index');
                    } else {
                        $em->remove($plan);
                    }
                }

                $em->remove($project);
                $em->flush();
                $this->addFlash('success', 'backend.projects.flash.deleted');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'backend.projects.flash.delete_failed');
            }
        }

        return $this->redirectToRoute('backend_project_index');
    }

    #[Route('/select-project/{id}', name: 'select_project', methods: ['POST','GET'], requirements: ['id' => '\d+'])]
    public function selectProject(int $id, ProjectRepository $projectRepository, ActiveProjectService $activeProjectService, Request $request): RedirectResponse
    {
        $project = $projectRepository->find($id);
        if (!$project instanceof Project) {
            $this->addFlash('warning', 'backend.projects.flash.project_not_found');

            return $this->redirectToRoute('backend_project_index');
        }

        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $activeProjectService->setActiveProject($project);

        $target = $request->query->getString('target');

        return match ($target) {
            'plan' => $this->redirectToRoute('backend_plan_index'),
            'elaboration_done' => $this->redirectToRoute('backend_plan_done'),
            'implementation' => $this->redirectToRoute('backend_plan_review', ['state' => 'implement']),
            'emissions' => $this->redirectToRoute('backend_emission_index'),
            default => $this->redirectToRoute('app_backend'),
        };
    }
}
