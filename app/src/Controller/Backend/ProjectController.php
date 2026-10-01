<?php

namespace App\Controller\Backend;

use App\Entity\{CrewDepartment, CrewPosition, EmissionRecord, Plan, Project, ProjectCompany, ProjectFundingSource, ProjectMembership, ProjectPhaseDate, User};
use App\Form\{ProjectType, CrewMemberCollectionType};
use App\Repository\{CrewDepartmentRepository, CrewMemberRepository, CrewPositionRepository, ProjectBillingDocumentRepository, ProjectRepository, EmissionRecordRepository, PlanRepository};
use App\Security\ProjectVoter;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Service\ActiveProjectService;
use App\Service\Animation\AnimationProjectConfigurationUpdater;
use App\Service\CrewCatalogScopeResolver;
use App\Service\CrewImport\CrewImportApplier;
use App\Service\CrewImport\CrewCatalogContextProvider;
use App\Service\CrewImport\CrewImportConfirmationBuilder;
use App\Service\CrewImport\CrewImportProposalBuilder;
use App\Service\CrewImport\CrewImportProposalStorage;
use App\Service\CrewImport\CrewImportSpreadsheetExtractor;
use App\Exception\CrewImport\CrewImportProposalStorageException;
use App\Exception\CrewImport\CrewImportReviewValidationException;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportProposal;
use App\Entity\ProjectSubscription;
use App\Service\ProjectFeatureGate;
use App\Service\ProjectCompanyLogoStorage;
use App\Service\StripeInvoiceStorageService;
use App\Service\SustainabilityPlanCollaborationService;
use App\Service\SustainabilityPlanImplementationPhaseService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{ Request, Response, RedirectResponse, StreamedResponse, ResponseHeaderBag };
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/project', name: 'backend_project_')]
#[IsGranted('ROLE_USER')]
class ProjectController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $t,
        private readonly ProjectFeatureGate $featureGate,
        private readonly ProjectBillingDocumentRepository $billingDocumentRepository,
        private readonly StripeInvoiceStorageService $invoiceStorageService,
        private readonly SustainabilityPlanCollaborationService $collaborationService,
        private readonly SustainabilityPlanImplementationPhaseService $implementationPhaseService,
        private readonly ProjectCompanyLogoStorage $companyLogoStorage,
        private readonly AnimationProjectConfigurationUpdater $animationConfigurationUpdater,
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
        $sort     = (string) $request->query->get('sort', 'date_desc');
        if (!in_array($sort, ['date_desc', 'date_asc', 'name_asc', 'name_desc'], true)) {
            $sort = 'date_desc';
        }

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
            ->leftJoin('p.projectCompanies', 'pc')
            ->addSelect('pm', 'mu', 'cu', 'sub', 'pc');

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

        match ($sort) {
            'date_asc' => $query->orderBy('p.createdAt', 'ASC')->addOrderBy('p.name', 'ASC'),
            'name_asc' => $query->orderBy('p.name', 'ASC'),
            'name_desc' => $query->orderBy('p.name', 'DESC'),
            default => $query->orderBy('p.createdAt', 'DESC')->addOrderBy('p.name', 'ASC'),
        };

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
            'dashboardEmissionChartHasMore' => $dashboardEmissionChartHasMore,
            'filters'       => [
                'name'      => $name,
                'type'      => $type,
                'country'   => $country,
                'owner'     => $owner,
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'sort'      => $sort,
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
            default => $this->t->trans('backend.projects.dashboard.phases.common.not_started'),
        };

        $planState = match ($planStatus) {
            'completo' => 'completed',
            'incompleto' => 'in_progress',
            default => 'not_started',
        };

        $emissionPhaseLabel = $emissionCount > 0
            ? number_format($emissionSum, 1, ',', '.')
            : '—';
        $emissionPhaseNote = $emissionCount > 0
            ? 'kgCO₂e'
            : $this->t->trans('backend.projects.dashboard.phases.co2.no_records');
        $elaborationComplete = $planStatus === 'completo';
        $hasImplementationActivity = $plan instanceof Plan
            && $this->collaborationService->hasImplementationActivity($plan);
        $implementationState = $plan instanceof Plan
            ? $this->implementationPhaseService->resolve($plan, $project)
            : SustainabilityPlanImplementationPhaseService::NOT_STARTED;
        $implementationStateLabel = $this->t->trans('backend.projects.dashboard.phases.common.' . $implementationState);

        return [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'creator' => $project->getUser() ? [
                'name' => trim(sprintf('%s %s', $project->getUser()->getName() ?? '', $project->getUser()->getSurnames() ?? '')),
                'email' => $project->getUser()->getEmail(),
            ] : null,
            'typeKey' => $project->getType(),
            'typeLabel' => $this->translateProjectType($project->getType() ?? ''),
            'modalityKey' => $project->getType() === 'rodaje'
                ? $project->getFilmingType()
                : $project->getEventTypePrimary(),
            'modalityLabel' => $this->translateProjectModality($project),
            'descriptionLabel' => $this->formatProjectDescription($project),
            'companies' => array_map(fn (ProjectCompany $company): array => [
                'id' => $company->getId(),
                'typeKey' => $company->getType(),
                'typeLabel' => $this->t->trans('backend.projects.form.project_company_type.options.' . $company->getType()),
                'name' => $company->getName(),
                'logoUrl' => $company->getLogoUrl(),
            ], $project->getProjectCompanies()->toArray()),
            'country' => $project->getCountry(),
            'ownerLabel' => $this->formatProjectOwner($project),
            'tierCode' => $projectTierCode,
            'tierLabel' => $this->featureGate->getPlanLabel($project, CommercialPhase::ELABORATION),
            'tierDescription' => $this->featureGate->getPlanDescription($project, CommercialPhase::ELABORATION),
            'isActive' => $isActive,
            'createdAt' => $project->getCreatedAt(),
            'plan' => [
                'exists' => $plan !== null,
                'id' => $plan?->getId(),
                'status' => $planStatus,
                'canDelete' => $planStatus === 'incompleto' && !$hasImplementationActivity,
                'label' => $planLabel,
                'class' => $planState,
                'measureCount' => $plan?->getPlanMeasures()->count() ?? 0,
                'statusChangedAt' => $plan?->getStatusChangedAt(),
            ],
            'emissionCount' => $emissionCount,
            'emissionSum' => $emissionSum,
            'billingDocumentCount' => $billingDocumentCount,
            'phases' => [
                [
                    'code' => '01',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.elaboration'),
                    'stateLabel' => $planPhaseLabel,
                    'state' => $planState,
                    'icon' => 'bi-clipboard-check',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.elaboration').' · '.$planPhaseLabel,
                    'primaryTarget' => $elaborationComplete ? 'elaboration_done' : 'plan',
                ],
                [
                    'code' => '02',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.signage'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'state' => 'not_started',
                    'icon' => 'bi-megaphone',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.signage').' · '.$this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'primaryTarget' => null,
                ],
                [
                    'code' => '03',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.bgos'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'state' => 'not_started',
                    'icon' => 'bi-calendar-check',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.bgos').' · '.$this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'primaryTarget' => 'bgos',
                    'isHighlighted' => true,
                ],
                [
                    'code' => '04',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.implementation'),
                    'stateLabel' => $implementationStateLabel,
                    'state' => $implementationState,
                    'icon' => 'bi-list-check',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.implementation').' · '.$implementationStateLabel,
                    'primaryTarget' => 'implementation',
                ],
                [
                    'code' => '05',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.co2'),
                    'stateLabel' => $this->t->trans($emissionCount > 0
                        ? 'backend.projects.dashboard.phases.common.in_progress'
                        : 'backend.projects.dashboard.phases.common.not_started'),
                    'state' => $emissionCount > 0 ? 'in_progress' : 'not_started',
                    'icon' => 'bi-cloud-arrow-up',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.co2').' · '.$emissionPhaseLabel.' '.$emissionPhaseNote,
                    'primaryTarget' => 'emissions',
                ],
                [
                    'code' => '06',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.report'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'state' => 'not_started',
                    'icon' => 'bi-file-earmark-text',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.report').' · '.$this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'primaryTarget' => 'report',
                ],
                [
                    'code' => '07',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.compensation'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'state' => 'not_started',
                    'icon' => 'bi-tree',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.compensation').' · '.$this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'primaryTarget' => null,
                ],
                [
                    'code' => '08',
                    'label' => $this->t->trans('backend.projects.dashboard.phase_names.certification'),
                    'stateLabel' => $this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'state' => 'not_started',
                    'icon' => 'bi-award',
                    'title' => $this->t->trans('backend.projects.dashboard.phase_names.certification').' · '.$this->t->trans('backend.projects.dashboard.phases.common.not_started'),
                    'primaryTarget' => null,
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

    private function translateProjectModality(Project $project): ?string
    {
        $key = $project->getType() === 'rodaje'
            ? $project->getFilmingType()
            : $project->getEventTypePrimary();
        if ($key === null || $key === '') {
            return null;
        }

        $prefix = $project->getType() === 'rodaje'
            ? 'backend.projects.form.filming_type.options.'
            : 'backend.projects.form.event_type_primary.options.';

        return $this->t->trans($prefix . $key);
    }

    private function formatProjectDescription(Project $project): string
    {
        if ($project->getType() === 'rodaje') {
            $filmingType = $project->getFilmingType();
            if ($filmingType === null || $filmingType === '') {
                return $this->translateProjectType('rodaje');
            }

            $typeLabel = $this->t->trans('backend.projects.form.filming_type.options.' . $filmingType);
            $genre = $project->getFilmingGenre();
            if ($genre === null || $genre === '') {
                return $typeLabel;
            }

            return $this->t->trans('backend.projects.dashboard.project_description.filming', [
                '%type%' => $typeLabel,
                '%genre%' => $this->t->trans('backend.projects.form.filming_genre.options.' . $genre),
            ]);
        }

        if ($project->getType() === 'evento') {
            $eventType = $project->getEventTypePrimary();
            if ($eventType === null || $eventType === '') {
                return $this->translateProjectType('evento');
            }

            return $this->t->trans('backend.projects.dashboard.project_description.event', [
                '%subtype%' => mb_strtolower($this->t->trans('backend.projects.form.event_type_primary.options.' . $eventType)),
            ]);
        }

        return $this->translateProjectType('');
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
        ActiveProjectService $activeProjectService,
        \App\Service\ProjectDocument\ProjectDocumentStorage $projectDocumentStorage
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
            'show_emission_source' => false,
        ]);
        $form->handleRequest($request);
        $this->normalizeProject($project);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAnimationConfiguration($form, $project);

            /** @var User $creator */
            $creator = $this->getUser();

            $project->setUser($creator);

            $membership = (new ProjectMembership())
                ->setUser($creator)
                ->setProject($project)
                ->setProjectRole('owner');

            $project->addProjectMembership($membership);

            $this->ensureBasicSubscriptions($project);

            $newLogoPaths = [];
            $newProjectDocumentFiles = [];
            $connection = $em->getConnection();
            $connection->beginTransaction();

            try {
                $em->persist($project);
                $em->persist($membership);
                $em->flush();

                $this->processProjectDocuments(
                    $form,
                    $projectDocumentStorage,
                    $newProjectDocumentFiles
                );

                [, $newLogoPaths] = $this->processCompanyLogos($form);
                $em->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                foreach ($newLogoPaths as $path) {
                    $this->companyLogoStorage->delete($path);
                }

                foreach ($newProjectDocumentFiles as $document) {
                    try {
                        $projectDocumentStorage->delete($document);
                    } catch (\Throwable) {
                    }
                    $document->clearFileMetadata();
                }

                $form->addError(new FormError('backend.projects.form.documents.validation.storage_error'));

                return $this->render('backend/project/form.html.twig', [
                    'form' => $form->createView(),
                    'edit' => false,
                ]);
            }

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
    public function edit(
        Project $project,
        Request $request,
        EntityManagerInterface $em,
        \App\Service\ProjectDocument\ProjectDocumentStorage $projectDocumentStorage
    ): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $wizardStep = min(5, max(1, (int) $request->request->get('_wizard_step', $request->query->get('step', 1))));

        $this->reorderPhases($project);

        // Guardar fases originales antes de modificar
        $originalPhases = new ArrayCollection();
        foreach ($project->getPhaseDates() as $phaseDate) {
            $originalPhases->add($phaseDate);
        }
        $originalCompanies = new ArrayCollection($project->getProjectCompanies()->toArray());
        $originalDocuments = new ArrayCollection($project->getProjectDocuments()->toArray());

        $originalLogoPaths = [];
        foreach ($originalCompanies as $company) {
            $originalLogoPaths[spl_object_id($company)] = $company->getLogoPath();
        }

        $form = $this->createForm(ProjectType::class, $project, [
            'show_commercial_tier' => false,
        ]);
        $form->handleRequest($request);
        $this->normalizeProject($project);

        $canSaveCurrentStep = false;
        if ($form->isSubmitted()) {
            $projectDocumentsValid = !$form->has('projectDocuments')
                || $form->get('projectDocuments')->isValid();

            $canSaveCurrentStep = $form->isValid()
                || (
                    $wizardStep < 5
                    && $projectDocumentsValid
                    && !$this->hasErrorsForWizardStep($form, $wizardStep)
                );
        }

        if ($canSaveCurrentStep) {
            $this->applyAnimationConfiguration($form, $project);

            // Eliminar fases eliminadas en el formulario
            foreach ($originalPhases as $originalPhase) {
                if (!$project->getPhaseDates()->contains($originalPhase)) {
                    $em->remove($originalPhase);
                }
            }

            $removedLogoPaths = [];
            foreach ($originalCompanies as $originalCompany) {
                if (!$project->getProjectCompanies()->contains($originalCompany) && $originalCompany->hasLogo()) {
                    $removedLogoPaths[] = $originalCompany->getLogoPath();
                }
            }

            $removedProjectDocumentStoredNames = [];
            foreach ($originalDocuments as $originalDocument) {
                if (!$project->getProjectDocuments()->contains($originalDocument)
                    && $originalDocument instanceof \App\Entity\ProjectDocument
                    && $originalDocument->isFile()
                    && null !== $originalDocument->getStoredName()) {
                    $removedProjectDocumentStoredNames[] = $originalDocument->getStoredName();
                }
            }

            $newLogoPaths = [];
            $replacedLogoPaths = [];
            $newProjectDocumentFiles = [];
            $connection = $em->getConnection();
            $connection->beginTransaction();

            try {
                $em->flush();

                $this->processProjectDocuments(
                    $form,
                    $projectDocumentStorage,
                    $newProjectDocumentFiles
                );

                [$replacedLogoPaths, $newLogoPaths] = $this->processCompanyLogos($form);
                $em->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                foreach ($newLogoPaths as $path) {
                    $this->companyLogoStorage->delete($path);
                }

                foreach ($newProjectDocumentFiles as $document) {
                    try {
                        $projectDocumentStorage->delete($document);
                    } catch (\Throwable) {
                    }
                    $document->clearFileMetadata();
                }

                foreach ($project->getProjectCompanies() as $company) {
                    $company->setLogoPath($originalLogoPaths[spl_object_id($company)] ?? null);
                }
                $form->addError(new FormError('backend.projects.form.project_company.storage_error'));
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
                    'projectUpgradeUrl' => $this->generateUrl('backend_project_billing', ['phase' => CommercialPhase::ELABORATION->value, '_fragment' => 'billing-project-'.$project->getId()]),
                ]);
            }

            foreach (array_unique([...$removedLogoPaths, ...$replacedLogoPaths]) as $path) {
                $this->companyLogoStorage->delete($path);
            }

            $projectId = $project->getId();
            if (null !== $projectId) {
                foreach (array_unique($removedProjectDocumentStoredNames) as $storedName) {
                    try {
                        $projectDocumentStorage->deleteStoredFile($projectId, $storedName);
                    } catch (\Throwable) {
                        $this->addFlash('warning', 'backend.projects.form.documents.validation.storage_cleanup_error');
                    }
                }
            }

            $this->addFlash('success', 'backend.projects.flash.updated');
            return $this->redirectToRoute('backend_project_edit', [
                'id' => $project->getId(),
                'step' => $wizardStep,
            ]);
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
                'phase' => CommercialPhase::ELABORATION->value,
                '_fragment' => 'billing-project-'.$project->getId(),
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
                'phase' => CommercialPhase::ELABORATION->value,
                '_fragment' => 'billing-project-'.$project->getId(),
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

        if (!$this->animationConfigurationUpdater->copyProjectConfiguration($project, $newProject)) {
            $this->addFlash('danger', 'backend.projects.flash.clone_animation_configuration_missing');

            return $this->redirectToRoute('backend_project_index');
        }

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
        CrewPositionRepository $positionRepository,
        CrewDepartmentRepository $departmentRepository,
        CrewCatalogScopeResolver $scopeResolver,
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

        // === 1) Catálogo profesional del scope efectivo del proyecto ===
        $project = $activeProjectService->getActiveProject();
        if (!$project instanceof Project) {
            throw $this->createNotFoundException('No active project available for the crew template.');
        }

        $scope = $scopeResolver->resolve($project);
        $allowedDepartments = $departmentRepository->findByScope($scope);
        $positions = [];
        foreach ($allowedDepartments as $department) {
            foreach ($positionRepository->findByCrewDepartment($department) as $position) {
                $positions[] = $position;
            }
        }

        // === 2) Hoja oculta "Listas" con el mapeo Departamento→Cargo y lista única de Deptos ===
        $listsTitle = 'Listas';
        $listSheet = new Worksheet($spreadsheet, $listsTitle);
        $spreadsheet->addSheet($listSheet);

        // A: Departamento (por fila), B: Cargo (por fila)
        $rowAB = 1;
        foreach ($positions as $pos) {
            $dept = $pos->getCrewDepartment();
            if (!$dept) { continue; }
            $listSheet->setCellValue("A{$rowAB}", $dept->getName());
            $listSheet->setCellValue("B{$rowAB}", $pos->getName());
            $rowAB++;
        }
        $mapCount = $rowAB - 1;

        // Lista única de departamentos permitidos (columna D)
        $uniqueDeptNames = array_map(
            static fn (CrewDepartment $department): string => (string) $department->getName(),
            $allowedDepartments
        );

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
        CrewImportSpreadsheetExtractor $extractor,
        CrewImportProposalBuilder $proposalBuilder,
        CrewImportProposalStorage $storage,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        if (!$this->isCsrfTokenValid('crew_import_upload_'.$project->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('crewFile');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'backend.projects.flash.crew_import_no_file');
            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        if (!$this->isValidCrewImportUpload($file)) {
            $this->addFlash('danger', $this->t->trans('backend.projects.crew.import.errors.bad_format'));
            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        $extraction = $extractor->extract($file->getPathname());
        if (!$extraction->isOfficialTemplate()) {
            $errorKey = $extraction->status === CrewImportExtraction::READ_ERROR
                ? 'backend.projects.crew.import.errors.read_failed'
                : 'backend.projects.crew.import.errors.bad_format';
            $this->addFlash('danger', $this->t->trans($errorKey));

            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        $proposal = $proposalBuilder->proposal($project, $extraction);
        try {
            $token = $storage->store($proposal, $this->currentUserId(), $this->sessionId($request));
        } catch (CrewImportProposalStorageException) {
            $this->addFlash('danger', 'backend.projects.crew.import.errors.storage_failed');

            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        return $this->redirectToRoute('backend_project_crew_import_review', [
            'id' => $project->getId(),
            'token' => $token,
        ]);
    }

    #[Route('/{id}/crew/import/{token}/review', name: 'crew_import_review', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function reviewCrewImport(
        Project $project,
        string $token,
        Request $request,
        CrewImportProposalStorage $storage,
        CrewCatalogContextProvider $catalogContextProvider,
        CrewMemberRepository $crewMemberRepository,
    ): Response {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $proposal = $this->loadCrewImportProposal($storage, $token, $project, $request);

        return $this->renderCrewImportReview(
            $project,
            $token,
            $proposal,
            $catalogContextProvider,
            $crewMemberRepository,
        );
    }

    #[Route('/{id}/crew/import/{token}/confirm', name: 'crew_import_confirm', requirements: ['token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function confirmCrewImport(
        Project $project,
        string $token,
        Request $request,
        EntityManagerInterface $em,
        CrewImportProposalStorage $storage,
        CrewImportConfirmationBuilder $confirmationBuilder,
        CrewImportApplier $applier,
        CrewCatalogContextProvider $catalogContextProvider,
        CrewMemberRepository $crewMemberRepository,
    ): Response {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        if (!$this->isCsrfTokenValid($this->crewImportReviewCsrfId($project, $token), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $proposal = $this->loadCrewImportProposal($storage, $token, $project, $request);
        $reviewInput = ['people' => $request->request->all('people')];
        try {
            $confirmed = $confirmationBuilder->build($proposal, $project, $reviewInput);
        } catch (CrewImportReviewValidationException $exception) {
            return $this->renderCrewImportReview(
                $project,
                $token,
                $proposal,
                $catalogContextProvider,
                $crewMemberRepository,
                $exception->errors,
                $reviewInput['people'],
            );
        }

        $applier->apply($project, $confirmed);
        $em->flush();
        $storage->delete($token, (int) $project->getId(), $this->currentUserId(), $this->sessionId($request));
        $this->addFlash('success', 'backend.projects.flash.crew_import_ok');

        return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
    }

    #[Route('/{id}/crew/import/{token}/cancel', name: 'crew_import_cancel', requirements: ['token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function cancelCrewImport(
        Project $project,
        string $token,
        Request $request,
        CrewImportProposalStorage $storage,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        if (!$this->isCsrfTokenValid($this->crewImportReviewCsrfId($project, $token), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $storage->delete($token, (int) $project->getId(), $this->currentUserId(), $this->sessionId($request));
        } catch (CrewImportProposalStorageException $exception) {
            throw $this->createNotFoundException('Crew import proposal is not available.', $exception);
        }

        return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
    }

    private function loadCrewImportProposal(
        CrewImportProposalStorage $storage,
        string $token,
        Project $project,
        Request $request,
    ): CrewImportProposal {
        try {
            return $storage->load($token, (int) $project->getId(), $this->currentUserId(), $this->sessionId($request));
        } catch (CrewImportProposalStorageException $exception) {
            throw $this->createNotFoundException('Crew import proposal is not available.', $exception);
        }
    }

    /** @param list<string> $errors @param array<int|string, mixed> $reviewInput */
    private function renderCrewImportReview(
        Project $project,
        string $token,
        CrewImportProposal $proposal,
        CrewCatalogContextProvider $catalogContextProvider,
        CrewMemberRepository $crewMemberRepository,
        array $errors = [],
        array $reviewInput = [],
    ): Response {
        return $this->render('backend/project/crew_import_review.html.twig', [
            'project' => $project,
            'token' => $token,
            'proposal' => $proposal,
            'catalog' => $catalogContextProvider->provide($project),
            'existingCrewMembers' => $crewMemberRepository->findByProject($project),
            'errors' => $errors,
            'reviewInput' => $reviewInput,
            'csrfId' => $this->crewImportReviewCsrfId($project, $token),
        ]);
    }

    private function isValidCrewImportUpload(UploadedFile $file): bool
    {
        if (!$file->isValid() || !is_readable($file->getPathname())) {
            return false;
        }

        $size = $file->getSize();
        if (!is_int($size) || $size <= 0 || $size > 2 * 1024 * 1024) {
            return false;
        }

        return in_array(
            strtolower($file->getClientOriginalExtension()),
            ['xls', 'xlsx'],
            true
        );
    }

    private function currentUserId(): int
    {
        $user = $this->getUser();
        if (!$user instanceof User || ($id = $user->getId()) === null || $id <= 0) {
            throw $this->createAccessDeniedException();
        }

        return $id;
    }

    private function sessionId(Request $request): string
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $sessionId = $session->getId();
        if ($sessionId === '') {
            throw $this->createAccessDeniedException();
        }

        return $sessionId;
    }

    private function crewImportReviewCsrfId(Project $project, string $token): string
    {
        return 'crew_import_review_'.$project->getId().'_'.$token;
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Project $project,
        Request $request,
        EntityManagerInterface $em,
        \App\Service\ProjectDocument\ProjectDocumentStorage $projectDocumentStorage
    ): Response
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

                $companyLogoPaths = array_map(
                    static fn (ProjectCompany $company): ?string => $company->getLogoPath(),
                    $project->getProjectCompanies()->toArray(),
                );

                $projectId = $project->getId();
                $projectDocumentStoredNames = [];
                foreach ($project->getProjectDocuments() as $document) {
                    if ($document->isFile() && null !== $document->getStoredName()) {
                        $projectDocumentStoredNames[] = $document->getStoredName();
                    }
                }

                $em->remove($project);
                $em->flush();

                foreach ($companyLogoPaths as $companyLogoPath) {
                    $this->companyLogoStorage->delete($companyLogoPath);
                }

                if (null !== $projectId) {
                    foreach ($projectDocumentStoredNames as $storedName) {
                        try {
                            $projectDocumentStorage->deleteStoredFile($projectId, $storedName);
                        } catch (\Throwable) {
                        }
                    }
                }
                $this->addFlash('success', 'backend.projects.flash.deleted');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'backend.projects.flash.delete_failed');
            }
        }

        return $this->redirectToRoute('backend_project_index');
    }

    /**
     * @param list<\App\Entity\ProjectDocument> $storedDocuments
     */
    private function processProjectDocuments(
        FormInterface $form,
        \App\Service\ProjectDocument\ProjectDocumentStorage $storage,
        array &$storedDocuments
    ): void {
        foreach ($form->get('projectDocuments')->all() as $documentForm) {
            if (!$documentForm->isValid()) {
                continue;
            }

            $document = $documentForm->getData();
            if (!$document instanceof \App\Entity\ProjectDocument) {
                continue;
            }

            if (!$document->isFile()) {
                $document->clearFileMetadata();
                continue;
            }

            $file = $documentForm->get('file')->getData();
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $storage->store($document, $file);
            $storedDocuments[] = $document;
        }
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function processCompanyLogos(FormInterface $form): array
    {
        $oldPaths = [];
        $newPaths = [];

        foreach ($form->get('projectCompanies')->all() as $companyForm) {
            $company = $companyForm->getData();
            if (!$company instanceof ProjectCompany) {
                continue;
            }

            $oldPath = $company->getLogoPath();
            $file = $companyForm->get('logoFile')->getData();
            if ($file instanceof UploadedFile) {
                $newPaths[] = $this->companyLogoStorage->store($company, $file);
                if ($oldPath !== null) {
                    $oldPaths[] = $oldPath;
                }
                continue;
            }

            if ($companyForm->get('removeLogo')->getData() && $oldPath !== null) {
                $company->setLogoPath(null);
                $oldPaths[] = $oldPath;
            }
        }

        return [$oldPaths, $newPaths];
    }

    private function hasErrorsForWizardStep(FormInterface $form, int $wizardStep): bool
    {
        $fieldSteps = [
            'name' => 1,
            'country' => 1,
            'type' => 1,
            'emissionSourceName' => 1,
            'commercialTier' => 1,
            'filmingType' => 1,
            'filmingGenre' => 1,
            'distributionMedia' => 1,
            'animationConfiguration' => 1,
            'episodios' => 1,
            'duracionEpisodio' => 1,
            'eventTypePrimary' => 1,
            'eventModality' => 1,
            'eventAttendeesCount' => 3,
            'eventOnlineConnections' => 3,
            'mainLocation' => 2,
            'presupuesto' => 2,
            'projectCompanies' => 2,
            'projectDocuments' => 2,
            'phaseDates' => 3,
            'projectFundingSources' => 4,
            'ecoManagerStatus' => 4,
        ];

        foreach ($form->getErrors(true, true) as $error) {
            $origin = $error->getOrigin();
            if (!$origin instanceof FormInterface) {
                return true;
            }

            while ($origin->getParent() !== null && $origin->getParent() !== $form) {
                $origin = $origin->getParent();
            }

            if ($origin === $form) {
                $template = $error->getMessageTemplate();
                if ($template === 'backend.projects.form.validation.funding_total_invalid') {
                    if ($wizardStep === 4) {
                        return true;
                    }
                    continue;
                }
                if (str_starts_with($template, 'backend.project.validation.')) {
                    if ($wizardStep === 3) {
                        return true;
                    }
                    continue;
                }

                return true;
            }

            if (($fieldSteps[$origin->getName()] ?? 0) === $wizardStep) {
                return true;
            }
        }

        return false;
    }

    private function applyAnimationConfiguration(FormInterface $form, Project $project): void
    {
        if ('rodaje' !== $project->getType()
            || ProjectCatalog::FILMING_GENRE_ANIMATION !== $project->getFilmingGenre()) {
            return;
        }

        $animationForm = $form->get('animationConfiguration');
        $data = $animationForm->getData();
        if (!is_array($data) || !$animationForm->isValid()) {
            return;
        }

        $this->animationConfigurationUpdater->updateProject(
            project: $project,
            techniques: is_array($data['techniques'] ?? null) ? $data['techniques'] : [],
            structure: is_string($data['structure'] ?? null) ? $data['structure'] : null,
            shootingAnswered: is_bool($data['shootingAnswered'] ?? null) ? $data['shootingAnswered'] : null,
            processingLevel: is_string($data['processingLevel'] ?? null) ? $data['processingLevel'] : null,
            processingInfrastructures: is_array($data['processingInfrastructures'] ?? null) ? $data['processingInfrastructures'] : [],
            usesAi: is_bool($data['usesAi'] ?? null) ? $data['usesAi'] : null,
        );
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
            'implementation' => $this->redirectToRoute('backend_plan_review', ['state' => 'all']),
            'bgos' => $this->redirectToRoute('backend_bgos_index'),
            'emissions' => $this->redirectToRoute('backend_emission_index'),
            'report' => $this->redirectToRoute('report_emission_overview_pdf'),
            default => $this->redirectToRoute('app_backend'),
        };
    }
}
