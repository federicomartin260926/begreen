<?php

namespace App\Controller\Backend;

// App
use App\Entity\{Category, EmissionActivity, EmissionRecord};
use App\Form\{EmissionRecordType, EnergyEmissionType, TransportEmissionType, WoodEmissionType};
use App\Repository\{CategoryRepository, EmissionActivityRepository, EmissionRecordRepository, ProjectRepository};
use App\Security\{EmissionRecordVoter, ProjectVoter};
use App\Service\{ActiveProjectService};
use App\Service\Emission\{WoodCatalog, WoodEmissionCalculator};

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
use Symfony\Contracts\{HttpClient\HttpClientInterface, Translation\TranslatorInterface};

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
                    $chartData[$label] += $record->getEmission();
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
        Request $request
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $records = $recordRepository->findByProjectOrderByPhaseAndDate($project);
        $allCategories = $categoryRepository->findEnabledInEmissionCalculator();
        $categoryData = $this->buildEmissionCategoryData($records, $allCategories, $em);
        $categoriesVM = $categoryData['categoriesVM'];
        $allChart = $categoryData['allChart'];
        $categoriesNavigation = $categoryData['categoriesNavigation'];
        $hasAnyEmissionRecords = $records !== [];

        // IDs canónicos por nombre ES base (no depende del listener)
        $energyId    = $categoryData['energyId'];
        $transportId = $categoryData['transportId'];
        $tripsId     = $categoryData['tripsId'];

        if ($categoriesNavigation === []) {
            return $this->render('backend/emission/index.html.twig', [
                'project'             => $project,
                'categoriesNavigation' => [],
                'chartDataByCategory' => ['all' => $allChart],
                'energyId'            => $energyId,
                'transportId'         => $transportId,
                'tripsId'             => $tripsId,
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
        $paginationQuery = ['categoryId' => $selectedCategoryId];

        foreach ($categoriesNavigation as &$category) {
            $category['active'] = $category['id'] === $selectedCategoryId;
        }
        unset($category);

        $newRecordUrl = $this->buildEmissionCreateUrl($selectedCategoryId, $energyId, $transportId, $tripsId, $currentPage > 1 ? $currentPage : null);

        return $this->render('backend/emission/index.html.twig', [
            'project'             => $project,
            'categoriesNavigation' => $categoriesNavigation,
            'chartDataByCategory' => array_merge(
                array_column($categoriesVM, 'chart', 'name'),
                ['all' => $allChart]
            ),
            'energyId'            => $energyId,
            'transportId'         => $transportId,
            'tripsId'             => $tripsId,
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
     *     tripsId: ?int
     * }
     */
    private function buildEmissionCategoryData(array $records, array $allCategories, EntityManagerInterface $em): array
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
            if (!$activity || !$activity->getCategory()) {
                continue;
            }

            $cat      = $activity->getCategory();
            $catId    = $cat->getId();
            $actName  = $activity->getName();

            if (!isset($categoriesVM[$catId])) {
                continue;
            }

            $categoriesVM[$catId]['records'][] = $record;
            $categoriesVM[$catId]['chart'][$actName] = ($categoriesVM[$catId]['chart'][$actName] ?? 0) + $record->getEmission();
            $allChart[$actName] = ($allChart[$actName] ?? 0) + $record->getEmission();
        }

        $energyId    = $this->findCategoryIdByNameEs($em, 'Energía');
        $transportId = $this->findCategoryIdByNameEs($em, 'Transporte');
        $tripsId     = $this->findCategoryIdByNameEs($em, 'Viajes');

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
                'createUrl' => $this->buildEmissionCreateUrl($category['id'], $energyId, $transportId, $tripsId),
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
            'tripsId' => $tripsId,
        ];
    }

    private function buildEmissionCreateUrl(
        int $categoryId,
        ?int $energyId,
        ?int $transportId,
        ?int $tripsId,
        ?int $page = null
    ): string {
        $params = array_filter([
            'page' => $page,
        ], static fn ($value): bool => $value !== null);

        if ($energyId !== null && $categoryId === $energyId) {
            return $this->generateUrl('backend_emission_new_energy', $params);
        }

        if (($transportId !== null && $categoryId === $transportId) || ($tripsId !== null && $categoryId === $tripsId)) {
            return $this->generateUrl('backend_emission_new_transport', $params + ['category' => $categoryId]);
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

        $record = new EmissionRecord();
        $record->setProject($project);
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
        $category = $record->getActivity()->getCategory();

        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.invalid_project_or_ownership'));
        }
        if (!$category) {
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
    // NEW ENERGY
    // =======================
    #[Route('/new-energy', name: 'backend_emission_new_energy')]
    public function newEnergy(
        Request $request,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        TranslatorInterface $t
    ): Response {
        $project  = $activeProjectService->getActiveProject();
        // OJO: aquí seguimos usando el nombre base ES "Energía"
        $category = $categoryRepository->findOneBy(['name' => 'Energía']);

        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        if (!$category) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }
        if (!$category->isEnabledInEmissionCalculator()) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $record = new EmissionRecord();
        $record->setProject($project);
        $record->setRegisteredAt(new \DateTimeImmutable());

        $form = $this->createForm(EnergyEmissionType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $year     = $date ? (int)$date->format('Y') : (int)date('Y');
                $subcat   = $form->get('subCategory')->getData(); // código canónico
                $activity = $activityRepository->findOneBySubcatetoryForLatestYear(
                    $subcat,
                    $year,
                    $project->getEmissionSourceName()
                );

                if (!$activity) {
                    $this->addFlash('danger', $t->trans('backend.emission.errors.activity_not_found'));
                } else {
                    $record->setPhase($phase);
                    $record->setActivity($activity);
                    $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                    $em->persist($record);
                    $em->flush();

                    $this->addFlash('success', $t->trans('backend.emission.flash.created'));

                    $categoryId = $activity->getCategory()->getId();
                    return $this->redirectToRoute('backend_emission_index', $this->buildEmissionIndexQuery($request, $categoryId));
                }
            }
        }

        return $this->render('backend/emission/energy_form.html.twig', [
            'form'     => $form->createView(),
            'project'  => $project,
            'record'   => $record,
            'category' => $category,
            'edit'     => false,
        ]);
    }


    // =======================
    // EDIT ENERGY
    // =======================
    #[Route('/{id}/edit-energy', name: 'backend_emission_edit_energy')]
    public function editEnergy(
        Request $request,
        EmissionRecord $record,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        ProjectRepository $projectRepository,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        $project  = $activeProjectService->getActiveProject();
        $category = $record->getActivity()->getCategory();

        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.invalid_project_or_ownership'));
        }
        if (!$category) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $form = $this->createForm(EnergyEmissionType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $year     = $date ? (int)$date->format('Y') : (int)date('Y');
                $subcat   = $form->get('subCategory')->getData(); // código canónico
                $activity = $activityRepository->findOneBySubcatetoryForLatestYear(
                    $subcat,
                    $year,
                    $project->getEmissionSourceName()
                );

                if (!$activity) {
                    $this->addFlash('danger', $t->trans('backend.emission.errors.activity_not_found'));
                } else {
                    $record->setPhase($phase);
                    $record->setActivity($activity);
                    $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                    $em->flush();

                    $this->addFlash('success', $t->trans('backend.emission.flash.updated'));

                    $categoryId = $activity->getCategory()->getId();
                    return $this->redirectToRoute('backend_emission_index', $this->buildEmissionIndexQuery($request, $categoryId));
                }
            }
        }

        return $this->render('backend/emission/energy_form.html.twig', [
            'form'     => $form->createView(),
            'project'  => $project,
            'record'   => $record,
            'category' => $category,
            'edit'     => true,
        ]);
    }


    // =======================
    // NEW TRANSPORT / TRAVEL
    // =======================
    #[Route('/new-transport-travel/{category}', name: 'backend_emission_new_transport')]
    public function newTransport(
        string $category,
        Request $request,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        // Resolver categoría (ID / ES / EN)
        $categoryEntity = $this->resolveCategoryFromRouteParam($category, $categoryRepository, $em);
        if (!$categoryEntity) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }
        if (!$categoryEntity->isEnabledInEmissionCalculator()) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $record = new EmissionRecord();
        $record->setProject($project);
        $record->setRegisteredAt(new \DateTimeImmutable());

        // Si tu Form usa el nombre, puedes pasar el de la entidad (ojo: saldrá traducido según listener)
        // Idealmente, pasa el ID y ajusta el Form para trabajar por ID.
        $form = $this->createForm(TransportEmissionType::class, $record, [
            'categoryId' => $categoryEntity->getId(),
        ]);
        $form->handleRequest($request);

        // Campo NO mapeado: activityId
        $activityId = $request->request->get('activityId');
        $activity   = $activityId ? $activityRepository->find($activityId) : null;

        if ($activity && $activity->getCategory()?->getId() !== $categoryEntity->getId()) {
            $activity = null;
        }

        if ($form->isSubmitted() && $form->isValid() && $activity) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $record->setPhase($phase);
                $record->setActivity($activity);
                $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                $em->persist($record);
                $em->flush();

                $this->addFlash('success', $t->trans('backend.emission.flash.created'));

                $categoryId = $activity->getCategory()->getId();
                return $this->redirectToRoute('backend_emission_index', $this->buildEmissionIndexQuery($request, $categoryId));
            }
        } elseif ($form->isSubmitted() && !$activity) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.activity_required'));
        }

        return $this->render('backend/emission/transport_form.html.twig', [
            'form'      => $form->createView(),
            'project'   => $project,
            'record'    => $record,
            'category'  => $categoryEntity,
            'edit'      => false,
            'activityId'=> ''
        ]);
    }


    // =======================
    // EDIT TRANSPORT / TRAVEL
    // =======================
    #[Route('/{id}/edit-transport-travel', name: 'backend_emission_edit_transport')]
    public function editTransport(
        Request $request,
        EmissionRecord $record,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        ProjectRepository $projectRepository,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        $project  = $activeProjectService->getActiveProject();
        $category = $record->getActivity() ? $record->getActivity()->getCategory() : null;

        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.invalid_project_or_ownership'));
        }
        if (!$category) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $form = $this->createForm(TransportEmissionType::class, $record, [
            'categoryId' => $category->getId(),
        ]);
        $form->handleRequest($request);

        // Campo NO mapeado: activityId
        $activityId = $request->request->get('activityId');
        $activity   = $activityId ? $activityRepository->find($activityId) : $record->getActivity();

        if ($form->isSubmitted() && $form->isValid() && $activity) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $record->setPhase($phase);
                $record->setActivity($activity);
                $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                $em->flush();

                $this->addFlash('success', $t->trans('backend.emission.flash.updated'));

                $categoryId = $activity->getCategory()->getId();
                return $this->redirectToRoute('backend_emission_index', $this->buildEmissionIndexQuery($request, $categoryId));
            }
        } elseif ($form->isSubmitted() && !$activity) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.activity_required'));
        }

        return $this->render('backend/emission/transport_form.html.twig', [
            'form'       => $form->createView(),
            'project'    => $project,
            'record'     => $record,
            'category'   => $category,
            'edit'       => true,
            'activityId' => $record->getActivity() ? $record->getActivity()->getId() : ''
        ]);
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

        $categoryName = (string) ($request->request->get('category') ?: ($record->getActivity()?->getCategory()?->getName() ?? 'Alojamientos'));
        $category = $categoryRepository->findOneBy(['name' => $categoryName]);

        try {
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
    public function calculateDistance(Request $request, HttpClientInterface $http, TranslatorInterface $t): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $lat1 = $data['lat1'] ?? null;
        $lon1 = $data['lon1'] ?? null;
        $lat2 = $data['lat2'] ?? null;
        $lon2 = $data['lon2'] ?? null;

        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            return $this->json(['error' => $t->trans('backend.emission.errors.invalid_coordinates')], 400);
        }

        $apiKey = $_ENV['ORS_API_KEY'] ?? 'eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6IjQ2ODcwZjM3NTQyYjRjOGJhYWVhNjA3MWI0NjBmYzNmIiwiaCI6Im11cm11cjY0In0=';

        try {
            $response = $http->request('POST', 'https://api.openrouteservice.org/v2/directions/driving-car', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'coordinates' => [
                        [(float)$lon1, (float)$lat1],
                        [(float)$lon2, (float)$lat2],
                    ],
                    'radiuses' => [1000, 1000]
                ],
            ]);
            $result = $response->toArray(false);
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            $errorResponse = $e->getResponse()->toArray(false);
            $errorMsg = $errorResponse['error']['message'] ?? $t->trans('backend.emission.errors.no_route_found');
            return $this->json(['error' => $errorMsg], 422);
        }

        if (
            !isset($result['routes'][0]['segments'][0]['distance']) ||
            empty($result['routes'][0]['segments'][0]['distance'])
        ) {
            $msg = $result['error']['message'] ?? $t->trans('backend.emission.errors.no_route_hint');
            return $this->json(['error' => $msg], 422);
        }

        $meters = $result['routes'][0]['segments'][0]['distance'];
        $kilometers = round($meters / 1000, 2);

        return $this->json(['kilometers' => $kilometers]);
    }
}
