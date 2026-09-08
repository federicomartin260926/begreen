<?php

namespace App\Controller\Backend;

// App
use App\Entity\{Category, EmissionActivity, EmissionRecord};
use App\Exception\OpenRouteServiceException;
use App\Form\{EmissionRecordType, WoodEmissionType};
use App\Repository\{CategoryRepository, EmissionActivityRepository, EmissionRecordRepository, ProjectRepository};
use App\Security\{EmissionRecordVoter, ProjectVoter};
use App\Service\{ActiveProjectService, OpenRouteService};
use App\Service\Emission\{WoodCatalog, WoodEmissionCalculator};
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionSnapshot;

// Doctrine / Gedmo
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Translation;

// Symfony
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Symfony Contracts
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/emission')]
#[IsGranted('ROLE_USER')]
class EmissionController extends AbstractController
{
    #[Route('/landing', name: 'backend_emission_landing')]
    public function landing(
        ActiveProjectService $activeProjectService,
        EmissionRecordRepository $recordRepository,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $phases = $project->getPhaseDates();
        $records = $recordRepository->findBy(['project' => $project]);

        $chartData = []; // ['Rodaje' => 123, 'Preproducción' => 45, ...]

        foreach ($phases as $phase) {
            $labelKey = match ($phase->getPhase()) {
                'preproduccion' => $project->getType() === 'evento'
                    ? 'backend.emission.phase.montaje'
                    : 'backend.emission.phase.preproduccion',
                'actividad' => $project->getType() === 'evento'
                    ? 'backend.emission.phase.evento'
                    : 'backend.emission.phase.rodaje',
                'postproduccion' => $project->getType() === 'evento'
                    ? 'backend.emission.phase.desmontaje'
                    : 'backend.emission.phase.postproduccion',
                default => null,
            };

            $label = $labelKey ? $t->trans($labelKey) : ucfirst((string) $phase->getPhase());
            $chartData[$label] = 0;

            foreach ($records as $record) {
                if ($record->getPhase()?->getId() === $phase->getId()) {
                    $chartData[$label] += (float) $record->getEmission();
                }
            }
        }

        return $this->render('backend/emission/landing.html.twig', [
            'project' => $project,
            'phases' => $phases,
            'chartData' => $chartData,
        ]);
    }

    #[Route('/records', name: 'backend_emission_index')]
    public function index(
        EmissionRecordRepository $recordRepository,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        EntityManagerInterface $em,
        TranslatorInterface $t,
        TransportEmissionSnapshot $transportSnapshot,
        EnergyEmissionSnapshot $energySnapshot,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        Request $request
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $records = $recordRepository->findByProjectOrderByPhaseAndDate($project);
        $allCategories = $categoryRepository->findEnabledInEmissionCalculator();
        $categoryData = $this->buildEmissionCategoryData($records, $allCategories, $em, $waterSnapshot, $accommodationSnapshot, $cateringSnapshot, $t);
        $categoriesVM = $categoryData['categoriesVM'];
        $allChart = $categoryData['allChart'];
        $categoriesNavigation = $categoryData['categoriesNavigation'];
        $hasAnyEmissionRecords = $records !== [];

        // IDs canónicos por nombre ES base (no depende del listener)
        $energyId    = $categoryData['energyId'];
        $transportId = $categoryData['transportId'];
        $waterId     = $categoryData['waterId'];
        $accommodationId = $categoryData['accommodationId'];
        $cateringId = $categoryData['cateringId'];

        if ($categoriesNavigation === []) {
            return $this->render('backend/emission/index.html.twig', [
                'project'             => $project,
                'categoriesNavigation' => [],
                'chartDataByCategory' => ['all' => $allChart],
                'energyId'            => $energyId,
                'transportId'         => $transportId,
                'waterId'             => $waterId,
                'accommodationId'     => $accommodationId,
                'cateringId'          => $cateringId,
                'selectedCategoryId'   => 0,
                'selectedCategoryName' => '',
                'selectedCategoryCount'=> 0,
                'selectedCategoryChartKey' => 'all',
                'selectedCategoryRecords' => [],
                'selectedCategoryTotalEmission' => 0.0,
                'currentPage'          => 1,
                'totalPages'           => 1,
                'paginationQuery'      => [],
                'perPage'              => 10,
                'newRecordUrl'         => '#',
                'hasCategories'        => false,
                'hasAnyEmissionRecords'=> $hasAnyEmissionRecords,
                'transportV20RecordIds' => [],
                'energyV1RecordIds' => [],
                'energyV1Summaries' => [],
                'waterV1RecordIds' => [],
                'waterV1Summaries' => [],
                'accommodationV1RecordIds' => [],
                'accommodationV1Summaries' => [],
                'cateringV1RecordIds' => [],
                'cateringV1Summaries' => [],
            ]);
        }

        $selectedCategoryId = $request->query->getInt('categoryId', 0);
        if ($selectedCategoryId <= 0 || !isset($categoriesVM[$selectedCategoryId])) {
            $defaultCategory = $categoriesNavigation[0];

            return $this->redirectToRoute('backend_emission_index', [
                'categoryId' => $defaultCategory['id'],
            ]);
        }

        $selectedCategory = $categoriesVM[$selectedCategoryId];
        $selectedCategoryName = $selectedCategory['name'];
        $selectedCategoryRecordsAll = $selectedCategory['records'];
        $selectedCategoryChartKey = $selectedCategory['name'];
        $selectedCategoryCount = count($selectedCategoryRecordsAll);
        $selectedCategoryTotalEmission = array_reduce(
            $selectedCategoryRecordsAll,
            static fn (float $carry, EmissionRecord $record): float => $carry + (float) $record->getEmission(),
            0.0
        );

        $perPage = 10;
        $currentPage = max(1, $request->query->getInt('page', 1));
        $totalPages = max(1, (int) ceil($selectedCategoryCount / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $selectedCategoryRecords = array_slice($selectedCategoryRecordsAll, $offset, $perPage);
        $transportV20RecordIds = [];
        $transportV20Summaries = [];
        $energyV1RecordIds = [];
        $energyV1Summaries = [];
        $waterV1RecordIds = [];
        $waterV1Summaries = [];
        $accommodationV1RecordIds = [];
        $accommodationV1Summaries = [];
        $cateringV1RecordIds = [];
        $cateringV1Summaries = [];
        if (null !== $transportId) {
            foreach ($selectedCategoryRecords as $record) {
                if (!$transportSnapshot->isTransportV20Record($record, $transportId)) {
                    continue;
                }

                $transportV20RecordIds[] = $record->getId();

                try {
                    $transportV20Summaries[$record->getId()] = $transportSnapshot->decodeSummary(
                        (string) $record->getCalculationDetails()
                    );
                } catch (\JsonException|\UnexpectedValueException) {
                    // Keep the record visible/editable even if its presentation snapshot is malformed.
                }
            }
        }
        if (null !== $energyId) {
            foreach ($selectedCategoryRecords as $record) {
                if (!$energySnapshot->isEnergyV1Record($record, $energyId)) {
                    continue;
                }

                $energyV1RecordIds[] = $record->getId();
                try {
                    $energyV1Summaries[$record->getId()] = $energySnapshot->decodeSummary(
                        (string) $record->getCalculationDetails()
                    );
                } catch (\JsonException|\UnexpectedValueException) {
                    // Keep corrupt modern records visible without treating them as legacy.
                }
            }
        }
        if (null !== $waterId) {
            foreach ($selectedCategoryRecords as $record) {
                if (!$waterSnapshot->isWaterV1Record($record, $waterId)) {
                    continue;
                }

                $waterV1RecordIds[] = $record->getId();
                try {
                    $input = $waterSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $waterV1Summaries[$record->getId()] = [
                        'waterUseType' => $input->waterUseType,
                        'normalizedUnit' => null === $record->getAmount() ? null : 'm3',
                    ];
                } catch (\JsonException|\UnexpectedValueException) {
                    // Keep corrupt modern records visible without treating them as legacy.
                }
            }
        }
        if (null !== $accommodationId) {
            foreach ($selectedCategoryRecords as $record) {
                if (!$accommodationSnapshot->isAccommodationV1Record($record, $accommodationId)) {
                    continue;
                }

                $accommodationV1RecordIds[] = $record->getId();
                try {
                    $input = $accommodationSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $accommodationV1Summaries[$record->getId()] = [
                        'accommodationType' => $input->accommodationType,
                        'normalizedUnitKey' => match ($input->accommodationType) {
                            'hotel' => 'occupied_room_night',
                            'hostel' => 'guest_night',
                            'apartment' => 'person_night',
                            default => null,
                        },
                    ];
                } catch (\JsonException|\UnexpectedValueException) {
                    // Keep corrupt modern records visible without treating them as legacy.
                }
            }
        }
        if (null !== $cateringId) {
            foreach ($selectedCategoryRecords as $record) {
                if (!$cateringSnapshot->isCateringV1Record($record, $cateringId)) {
                    continue;
                }

                $cateringV1RecordIds[] = $record->getId();
                try {
                    $input = $cateringSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $calculation = $cateringSnapshot->decodeCalculation((string) $record->getCalculationDetails());
                    $cateringV1Summaries[$record->getId()] = [
                        'activityType' => $input->activityType,
                        'normalizedUnitKey' => match ($calculation['normalizedUnit'] ?? null) {
                            'people' => 'people',
                            'prepared_menu' => 'prepared_menu',
                            'prepared sandwich' => 'prepared_sandwich',
                            'L' => 'liter',
                            'service' => 'service',
                            'kg' => 'kg',
                            default => null,
                        },
                    ];
                } catch (\JsonException|\UnexpectedValueException) {
                    // Keep corrupt modern records visible without treating them as legacy.
                }
            }
        }
        $paginationQuery = ['categoryId' => $selectedCategoryId];

        foreach ($categoriesNavigation as &$category) {
            $category['active'] = $category['id'] === $selectedCategoryId;
        }
        unset($category);

        $newRecordUrl = $this->buildEmissionCreateUrl($selectedCategoryId, $energyId, $transportId, $waterId, $accommodationId, $cateringId, $currentPage > 1 ? $currentPage : null);

        return $this->render('backend/emission/index.html.twig', [
            'project'             => $project,
            'categoriesNavigation' => $categoriesNavigation,
            'chartDataByCategory' => array_merge(
                array_column($categoriesVM, 'chart', 'name'),
                ['all' => $allChart]
            ),
            'energyId'            => $energyId,
            'transportId'         => $transportId,
            'waterId'             => $waterId,
            'accommodationId'     => $accommodationId,
            'cateringId'          => $cateringId,
            'selectedCategoryId'   => $selectedCategoryId,
            'selectedCategoryName' => $selectedCategoryName,
            'selectedCategoryCount'=> $selectedCategoryCount,
            'selectedCategoryChartKey' => $selectedCategoryChartKey,
            'selectedCategoryRecords' => $selectedCategoryRecords,
            'selectedCategoryTotalEmission' => $selectedCategoryTotalEmission,
            'currentPage'          => $currentPage,
            'totalPages'           => $totalPages,
            'paginationQuery'      => $paginationQuery,
            'perPage'              => $perPage,
            'newRecordUrl'         => $newRecordUrl,
            'hasCategories'        => $categoriesVM !== [],
            'hasAnyEmissionRecords'=> $hasAnyEmissionRecords,
            'transportV20RecordIds' => $transportV20RecordIds,
            'transportV20Summaries' => $transportV20Summaries,
            'energyV1RecordIds' => $energyV1RecordIds,
            'energyV1Summaries' => $energyV1Summaries,
            'waterV1RecordIds' => $waterV1RecordIds,
            'waterV1Summaries' => $waterV1Summaries,
            'accommodationV1RecordIds' => $accommodationV1RecordIds,
            'accommodationV1Summaries' => $accommodationV1Summaries,
            'cateringV1RecordIds' => $cateringV1RecordIds,
            'cateringV1Summaries' => $cateringV1Summaries,
        ]);
    }

    private function buildEmissionIndexQuery(Request $request, ?int $categoryId = null): array
    {
        $query = $request->query->all();
        if ($categoryId !== null) {
            $query['categoryId'] = $categoryId;
        }

        return array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<int, EmissionRecord> $records
     * @param array<int, Category> $allCategories
     *
     * @return array{
     *     categoriesVM: array<int, array{id:int,name:string,records:array<int, EmissionRecord>,chart:array<string, float>}>,
     *     categoriesNavigation: array<int, array{id:int,name:string,count:int,records:array<int, EmissionRecord>,active:bool,empty:bool,url:string,createUrl:?string,icon:string}>,
     *     allChart: array<string, float>,
     *     energyId: ?int,
     *     transportId: ?int,
     *     waterId: ?int,
     *     accommodationId: ?int,
     *     cateringId: ?int
     * }
     */
    private function buildEmissionCategoryData(
        array $records,
        array $allCategories,
        EntityManagerInterface $em,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        TranslatorInterface $translator,
    ): array
    {
        $categoriesVM = [];
        $allChart = [];

        foreach ($allCategories as $cat) {
            $categoriesVM[$cat->getId()] = [
                'id'      => $cat->getId(),
                'name'    => $cat->getName(),
                'records' => [],
                'chart'   => [],
            ];
        }

        foreach ($records as $record) {
            $activity = $record->getActivity();
            $cat = $record->getEffectiveCategory();
            if (!$cat) {
                continue;
            }

            $catId    = $cat->getId();
            $actName = $activity?->getName() ?? $this->modernActivityName($record, $waterSnapshot, $accommodationSnapshot, $cateringSnapshot, $translator);

            if (!isset($categoriesVM[$catId])) {
                continue;
            }

            $categoriesVM[$catId]['records'][] = $record;
            $categoriesVM[$catId]['chart'][$actName] = ($categoriesVM[$catId]['chart'][$actName] ?? 0) + (float) $record->getEmission();
            $allChart[$actName] = ($allChart[$actName] ?? 0) + (float) $record->getEmission();
        }

        $energyId    = $this->findCategoryIdByNameEs($em, 'Energía');
        $transportId = $this->findCategoryIdByNameEs($em, 'Transporte');
        $waterId     = $this->findCategoryIdByNameEs($em, 'Agua');
        $accommodationId = $this->findCategoryIdByNameEs($em, 'Alojamientos');
        $cateringId = $this->findCategoryIdByNameEs($em, 'Catering');

        $nonEmptyCategories = [];
        $emptyCategories = [];
        foreach ($categoriesVM as $category) {
            $recordCount = count($category['records']);
            $item = [
                'id' => $category['id'],
                'name' => $category['name'],
                'count' => $recordCount,
                'records' => $category['records'],
                'active' => false,
                'empty' => $recordCount === 0,
                'url' => $this->generateUrl('backend_emission_index', ['categoryId' => $category['id']]),
                'createUrl' => $this->buildEmissionCreateUrl($category['id'], $energyId, $transportId, $waterId, $accommodationId, $cateringId),
                'icon' => 'bi-folder2-open',
            ];

            if ($item['empty']) {
                $emptyCategories[] = $item;
            } else {
                $nonEmptyCategories[] = $item;
            }
        }

        return [
            'categoriesVM' => $categoriesVM,
            'categoriesNavigation' => array_merge($nonEmptyCategories, $emptyCategories),
            'allChart' => $allChart,
            'energyId' => $energyId,
            'transportId' => $transportId,
            'waterId' => $waterId,
            'accommodationId' => $accommodationId,
            'cateringId' => $cateringId,
        ];
    }

    private function modernActivityName(
        EmissionRecord $record,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        TranslatorInterface $translator,
    ): string {
        return match ($record->getEffectiveCategory()?->getName()) {
            'Agua' => $this->waterActivityName($record, $waterSnapshot, $translator),
            'Alojamientos' => $this->accommodationActivityName($record, $accommodationSnapshot, $translator),
            'Catering' => $this->cateringActivityName($record, $cateringSnapshot, $translator),
            default => '—',
        };
    }

    private function cateringActivityName(EmissionRecord $record, CateringEmissionSnapshot $snapshot, TranslatorInterface $translator): string
    {
        $category = $record->getEffectiveCategory();
        if (!$snapshot->isCateringV1Record($record, (int) $category?->getId())) {
            return '—';
        }

        try {
            $type = $snapshot->decodeInput((string) $record->getCalculationDetails())->activityType;
        } catch (\JsonException|\UnexpectedValueException) {
            return '—';
        }

        return null === $type || '' === $type ? '—' : $translator->trans('backend.emission.catering_v1.activities.'.$type);
    }

    private function accommodationActivityName(
        EmissionRecord $record,
        AccommodationEmissionSnapshot $snapshot,
        TranslatorInterface $translator,
    ): string {
        $category = $record->getEffectiveCategory();
        if (!$snapshot->isAccommodationV1Record($record, (int) $category?->getId())) {
            return '—';
        }

        try {
            $type = $snapshot->decodeInput((string) $record->getCalculationDetails())->accommodationType;
        } catch (\JsonException|\UnexpectedValueException) {
            return '—';
        }

        return null === $type || '' === $type
            ? '—'
            : $translator->trans('backend.emission.accommodation_v1.types.'.$type);
    }

    private function waterActivityName(
        EmissionRecord $record,
        WaterEmissionSnapshot $snapshot,
        TranslatorInterface $translator,
    ): string {
        $category = $record->getEffectiveCategory();
        if ('Agua' !== $category?->getName() || !$snapshot->isWaterV1Record($record, (int) $category->getId())) {
            return '—';
        }

        try {
            $waterUseType = $snapshot->decodeInput((string) $record->getCalculationDetails())->waterUseType;
        } catch (\JsonException|\UnexpectedValueException) {
            return '—';
        }

        return null === $waterUseType || '' === $waterUseType
            ? '—'
            : $translator->trans('backend.emission.water_v1.water_use_types.'.$waterUseType);
    }

    private function buildEmissionCreateUrl(
        int $categoryId,
        ?int $energyId,
        ?int $transportId,
        ?int $waterId,
        ?int $accommodationId,
        ?int $cateringId,
        ?int $page = null
    ): string {
        $params = array_filter([
            'page' => $page,
        ], static fn ($value): bool => $value !== null);

        if ($energyId !== null && $categoryId === $energyId) {
            return $this->generateUrl('backend_emission_new_energy_v1', $params);
        }

        if ($transportId !== null && $categoryId === $transportId) {
            return $this->generateUrl('backend_emission_new_transport_v20', $params);
        }

        if ($waterId !== null && $categoryId === $waterId) {
            return $this->generateUrl('backend_emission_new_water_v1', $params);
        }

        if ($accommodationId !== null && $categoryId === $accommodationId) {
            return $this->generateUrl('backend_emission_new_accommodation_v1', $params);
        }

        if ($cateringId !== null && $categoryId === $cateringId) {
            return $this->generateUrl('backend_emission_new_catering_v1', $params);
        }

        return $this->generateUrl('backend_emission_new', $params + ['category' => $categoryId]);
    }

    private function findCategoryIdByNameEs(EntityManagerInterface $em, string $nameEs): ?int
    {
        $row = $em->createQuery('SELECT c.id FROM ' . Category::class . ' c WHERE c.name = :n')
                ->setParameter('n', $nameEs)
                ->setMaxResults(1)
                ->getOneOrNullResult(); // ['id'=>X] | null
        return $row['id'] ?? null;
    }


    #[Route('/new/{category}', name: 'backend_emission_new', methods: ['GET','POST'])]
    public function new(
        string $category,
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $em,
        ProjectRepository $projectRepository,
        CategoryRepository $categoryRepository,
        EmissionActivityRepository $activityRepository,
        WoodCatalog $woodCatalog,
        WoodEmissionCalculator $calculator,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        // Resolver categoría por ID, nombre ES o traducción EN
        $categoryEntity = $this->resolveCategoryFromRouteParam($category, $categoryRepository, $em);
        if (!$categoryEntity) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }
        if (!$categoryEntity->isEnabledInEmissionCalculator()) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }
        if ('Agua' === $categoryEntity->getName()) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $record = new EmissionRecord();
        $record->setProject($project);
        $record->setCategory($categoryEntity);
        $record->setRegisteredAt(new \DateTimeImmutable());

        $isMaterials = $categoryEntity->getId() === $this->findCategoryIdByNameEs($em, 'Materiales');
        $materialActivities = $isMaterials
            ? $this->buildMaterialActivities($activityRepository, $categoryEntity, $project)
            : [];
        $form = $isMaterials
            ? $this->createForm(WoodEmissionType::class, $record, [
                'material_activities' => $materialActivities,
            ])
            : $this->createForm(EmissionRecordType::class, $record, [
                'category' => $categoryEntity,
            ]);
        $form->handleRequest($request);

        if (!$isMaterials) {
            $calculationDetails = $request->request->get('calculationDetails');
            if ($calculationDetails !== null) {
                $record->setCalculationDetails($calculationDetails);
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);
            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                try {
                    if ($isMaterials) {
                        $this->applyMaterialCalculation($record, $form, $categoryEntity, $activityRepository, $calculator);
                    } else {
                        $activity = $record->getActivity();
                        $amount = $record->getAmount();
                        $record->setCategory($activity->getCategory());
                        $record->setEmission($amount * $activity->getEmissionFactor());
                    }

                    $record->setPhase($phase);
                    $em->persist($record);
                    $em->flush();

                    $this->addFlash('success', $t->trans('backend.emission.flash.created'));
                    return $this->redirectToRoute(
                        'backend_emission_index',
                        $this->buildEmissionIndexQuery($request, $categoryEntity->getId()),
                    );
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('danger', $t->trans(
                        'backend.emission.wood.errors.' . $exception->getMessage(),
                    ));
                }
            }
        }

        return $this->render('backend/emission/form.html.twig', [
            'form'     => $form->createView(),
            'project'  => $project,
            'category' => $categoryEntity,
            'edit'     => false,
            'isMaterials' => $isMaterials,
            'materialActivities' => $materialActivities,
            'densities' => $isMaterials ? $woodCatalog->getDefaultDensities() : [],
            'woodScenarios' => $isMaterials ? $woodCatalog->getScenarioCatalog() : [],
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_emission_edit', methods: ['GET','POST'])]
    public function edit(
        EmissionRecord $record,
        Request $request,
        EntityManagerInterface $em,
        ProjectRepository $projectRepository,
        EmissionActivityRepository $activityRepository,
        WoodCatalog $woodCatalog,
        WoodEmissionCalculator $calculator,
        TranslatorInterface $t,
    ): Response {
        $project  = $record->getProject();
        $category = $record->getEffectiveCategory();

        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.invalid_project_or_ownership'));
        }
        if (!$category || 'Agua' === $category->getName() || !$record->getActivity()) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $isMaterials = $category->getId() === $this->findCategoryIdByNameEs($em, 'Materiales');
        $materialActivities = $isMaterials
            ? $this->buildMaterialActivities($activityRepository, $category, $project, $record->getActivity())
            : [];
        $details = $isMaterials ? $this->decodeCalculationDetails($record) : [];
        $form = $isMaterials
            ? $this->createForm(WoodEmissionType::class, $record, [
                'wood_details' => $details,
                'material_activities' => $materialActivities,
                'initial_subcategory' => $record->getActivity()->getSubcategory() === 'madera' ? 'madera' : 'generic',
                'initial_activity_id' => $record->getActivity()->getId(),
                'generic_amount' => $record->getAmount(),
            ])
            : $this->createForm(EmissionRecordType::class, $record, [
                'category' => $category,
            ]);
        $form->handleRequest($request);

        if (!$isMaterials) {
            $calculationDetails = $request->request->get('calculationDetails');
            if ($calculationDetails !== null) {
                $record->setCalculationDetails($calculationDetails);
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);
            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                try {
                    if ($isMaterials) {
                        $this->applyMaterialCalculation($record, $form, $category, $activityRepository, $calculator);
                    } else {
                        $activity = $record->getActivity();
                        $amount = $record->getAmount();
                        $record->setCategory($activity->getCategory());
                        $record->setEmission($amount * $activity->getEmissionFactor());
                    }

                    $record->setPhase($phase);
                    $em->flush();

                    $this->addFlash('success', $t->trans('backend.emission.flash.updated'));
                    return $this->redirectToRoute(
                        'backend_emission_index',
                        $this->buildEmissionIndexQuery($request, $category->getId()),
                    );
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('danger', $t->trans(
                        'backend.emission.wood.errors.' . $exception->getMessage(),
                    ));
                }
            }
        }

        return $this->render('backend/emission/form.html.twig', [
            'form'    => $form->createView(),
            'project' => $project,
            'record'  => $record,
            'category'=> $category,
            'edit'    => true,
            'isMaterials' => $isMaterials,
            'materialActivities' => $materialActivities,
            'densities' => $isMaterials ? $woodCatalog->getDefaultDensities() : [],
            'woodScenarios' => $isMaterials ? $woodCatalog->getScenarioCatalog() : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getWoodFormInput(FormInterface $form): array
    {
        return [
            'method' => $form->get('method')->getData(),
            'certification' => $form->get('certification')->getData(),
            'quantity' => $form->get('quantity')->getData(),
            'inputWeightKg' => $form->get('inputWeightKg')->getData(),
            'woodClassification' => $form->get('woodClassification')->getData(),
            'thicknessM' => $form->get('thicknessM')->getData(),
            'lengthM' => $form->get('lengthM')->getData(),
            'widthM' => $form->get('widthM')->getData(),
            'speciesKey' => $form->get('speciesKey')->getData(),
            'boardFamily' => $form->get('boardFamily')->getData(),
            'boardOption' => $form->get('boardOption')->getData(),
            'manualBoardThicknessMm' => $form->get('manualBoardThicknessMm')->getData(),
        ];
    }

    /**
     * @return array<string, array<int, array{id:int,name:string,unit:string}>>
     */
    private function buildMaterialActivities(
        EmissionActivityRepository $repository,
        Category $category,
        object $project,
        ?EmissionActivity $currentActivity = null,
    ): array {
        $activities = ['generic' => [], 'madera' => []];
        $sourceName = $project->getEmissionSourceName() ?: 'MITECO';

        foreach ($repository->getActivitiesForLatestYearByCategoryId($sourceName, $category->getId()) as $activity) {
            if ($activity->getSubcategory() === null) {
                $activities['generic'][$activity->getId()] = [
                    'id' => $activity->getId(),
                    'name' => $activity->getName(),
                    'unit' => $activity->getUnit(),
                ];
            }
        }

        foreach (['purchased', 'recycled', 'reused'] as $origin) {
            $activity = $repository->findWoodFactorForOrigin($origin);
            if ($activity && $activity->getCategory()?->getId() === $category->getId()) {
                $activities['madera'][$activity->getId()] = [
                    'id' => $activity->getId(),
                    'name' => $activity->getName(),
                    'unit' => $activity->getUnit(),
                ];
            }
        }

        if ($currentActivity && $currentActivity->getCategory()?->getId() === $category->getId()) {
            $group = match ($currentActivity->getSubcategory()) {
                'madera' => 'madera',
                null => 'generic',
                default => null,
            };
            if ($group !== null) {
                $activities[$group][$currentActivity->getId()] = [
                    'id' => $currentActivity->getId(),
                    'name' => $currentActivity->getName(),
                    'unit' => $currentActivity->getUnit(),
                ];
            }
        }

        return $activities;
    }

    private function applyMaterialCalculation(
        EmissionRecord $record,
        FormInterface $form,
        Category $category,
        EmissionActivityRepository $repository,
        WoodEmissionCalculator $calculator,
    ): void {
        $activityId = $form->get('activityId')->getData();
        $selectedActivity = $activityId ? $repository->find((int) $activityId) : null;
        if (!$selectedActivity || $selectedActivity->getCategory()?->getId() !== $category->getId()) {
            throw new \InvalidArgumentException('invalid_activity');
        }
        $selectedGroup = $form->get('subCategory')->getData();
        if (
            ($selectedGroup === 'madera' && $selectedActivity->getSubcategory() !== 'madera')
            || ($selectedGroup === 'generic' && $selectedActivity->getSubcategory() !== null)
        ) {
            throw new \InvalidArgumentException('invalid_activity');
        }

        if ($selectedActivity->getSubcategory() === null) {
            $amount = $form->get('amount')->getData();
            if (!is_numeric($amount) || (float) $amount < 0) {
                throw new \InvalidArgumentException('invalid_generic_amount');
            }

            $record
                ->setActivity($selectedActivity)
                ->setCategory($selectedActivity->getCategory())
                ->setAmount((float) $amount)
                ->setEmission((float) $amount * $selectedActivity->getEmissionFactor())
                ->setCalculationDetails(null);
            return;
        }

        $origin = match ($selectedActivity->getCalculationCode()) {
            'wood_purchased' => 'purchased',
            'wood_recycled' => 'recycled',
            'wood_reused' => 'reused',
            default => throw new \InvalidArgumentException('invalid_activity'),
        };
        $activity = $repository->findWoodFactorForOrigin($origin);
        if (!$activity || $activity->getCategory()?->getId() !== $category->getId()) {
            throw new \InvalidArgumentException('invalid_activity');
        }

        $result = $calculator->calculate($activity, ['origin' => $origin] + $this->getWoodFormInput($form));
        $record
            ->setActivity($activity)
            ->setCategory($activity->getCategory())
            ->setAmount($result->amount)
            ->setEmission($result->emission)
            ->setCalculationDetails(json_encode(
                $result->details,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCalculationDetails(EmissionRecord $record): array
    {
        if (!$record->getCalculationDetails()) {
            return [];
        }

        try {
            return json_decode($record->getCalculationDetails(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }

    // =======================
    // Helper para resolver categoría por ID / ES / EN
    // =======================
    private function resolveCategoryFromRouteParam(
        string $param,
        CategoryRepository $categoryRepository,
        EntityManagerInterface $em
    ): ?Category {
        $param = trim($param);

        // 1) ID numérico
        if ($param !== '' && ctype_digit($param)) {
            return $categoryRepository->find((int)$param);
        }

        // 2) Nombre base ES
        if ($param !== '') {
            $cat = $categoryRepository->findOneBy(['name' => $param]);
            if ($cat) {
                return $cat;
            }
        }

        // 3) Traducción EN (Gedmo ext_translations)
        if ($param !== '') {
            /** @var \Gedmo\Translatable\Entity\Translation|null $tr */
            $tr = $em->getRepository(\Gedmo\Translatable\Entity\Translation::class)->findOneBy([
                'objectClass' => Category::class,
                'field'       => 'name',
                'locale'      => 'en',
                'content'     => $param,
            ]);
            if ($tr) {
                return $categoryRepository->find($tr->getForeignKey());
            }
        }

        return null;
    }


    #[Route('/{id}/delete', name: 'backend_emission_delete', methods: ['POST'])]
    public function delete(
        EmissionRecord $record,
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepository,
        ActiveProjectService $activeProjectService,
        EmissionRecordAttachmentStorage $attachmentStorage,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project || $record->getProject() !== $project) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.invalid_project_or_ownership'));
            return $this->redirectToRoute('backend_emission_index');
        }

        $this->denyAccessUnlessGranted(EmissionRecordVoter::EDIT, $record);

        if (!$this->isCsrfTokenValid('delete' . $record->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.csrf_invalid'));
            return $this->redirectToRoute('backend_project_edit', ['id' => $project->getId()]);
        }

        $categoryName = (string) ($request->request->get('category') ?: ($record->getEffectiveCategory()?->getName() ?? ''));
        $category = $categoryRepository->findOneBy(['name' => $categoryName]);

        try {
            $attachmentStorage->deleteAllForRecord($record);
            $em->remove($record);
            $em->flush();

            $this->addFlash('success', $t->trans('backend.emission.flash.deleted'));
        } catch (\Throwable $e) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.delete_failed', [
                '%error%' => $e->getMessage()
            ]));
        }

        return $this->redirectToRoute('backend_emission_index', $this->buildEmissionIndexQuery($request, $category?->getId()));
    }

    #[Route('/by-subcategory', name: 'backend_emission_by_subcategory', methods: ['GET'])]
    public function bySubcategory(Request $request, EmissionActivityRepository $repo): JsonResponse
    {
        $subcategory = $request->query->get('subcategory');        // código canónico: 'carretera','aereo',...
        $sourceName  = $request->query->get('sourceName', 'MITECO');
        $categoryId  = $request->query->getInt('categoryId', 0);   // <-- ID

        if ($categoryId <= 0) {
            return new JsonResponse(['error' => 'Missing or invalid categoryId'], 400);
        }

        // Nuevo método por ID (ver repo abajo)
        $activities = $repo->getActivitiesForLatestYearByCategoryId($sourceName, $categoryId, $subcategory ?: null);

        $result = [];
        foreach ($activities as $activity) {
            $result[] = [
                'id'   => $activity->getId(),
                'name' => $activity->getName(),
                'unit' => $activity->getUnit(),
            ];
        }

        return new JsonResponse($result);
    }

    #[Route('/calculate-distance', name: 'backend_emission_calculate_distance', methods: ['POST'])]
    public function calculateDistance(
        Request $request,
        OpenRouteService $openRouteService,
        TranslatorInterface $t,
    ): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];
        $lat1 = $this->validCoordinate($data['lat1'] ?? null, -90, 90);
        $lon1 = $this->validCoordinate($data['lon1'] ?? null, -180, 180);
        $lat2 = $this->validCoordinate($data['lat2'] ?? null, -90, 90);
        $lon2 = $this->validCoordinate($data['lon2'] ?? null, -180, 180);

        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return $this->json(['error' => $t->trans('backend.emission.errors.invalid_coordinates')], 400);
        }

        try {
            $kilometers = $openRouteService->drivingDistanceKilometers($lat1, $lon1, $lat2, $lon2);
        } catch (OpenRouteServiceException $exception) {
            if ($exception->reason === OpenRouteServiceException::NOT_ROUTABLE) {
                return $this->json([
                    'error' => $t->trans('backend.emission.transport_js.no_road_nearby'),
                ], 422);
            }

            return $this->json([
                'error' => $t->trans('backend.emission.errors.ors_unavailable'),
            ], 502);
        }

        return $this->json(['kilometers' => $kilometers]);
    }

    #[Route('/location-autocomplete', name: 'backend_emission_location_autocomplete', methods: ['GET'])]
    public function locationAutocomplete(
        Request $request,
        OpenRouteService $openRouteService,
        TranslatorInterface $t,
    ): JsonResponse {
        $text = trim((string) $request->query->get('text', ''));
        if (mb_strlen($text) < 3 || mb_strlen($text) > 200) {
            return $this->json(['results' => []]);
        }

        try {
            return $this->json([
                'results' => $openRouteService->autocomplete($text),
            ]);
        } catch (OpenRouteServiceException) {
            return $this->json([
                'error' => $t->trans('backend.emission.errors.ors_unavailable'),
            ], 502);
        }
    }

    private function validCoordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) && $coordinate >= $minimum && $coordinate <= $maximum
            ? $coordinate
            : null;
    }
}
