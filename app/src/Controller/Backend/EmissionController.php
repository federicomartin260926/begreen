<?php

namespace App\Controller\Backend;

// App
use App\Entity\{Category, EmissionRecord};
use App\Exception\OpenRouteServiceException;
use App\Repository\{CategoryRepository, EmissionRecordRepository, ProjectRepository};
use App\Security\{EmissionRecordVoter, ProjectVoter};
use App\Service\{ActiveProjectService, OpenRouteService};
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Waste\WasteUiCatalog;
use App\Service\Emission\Material\MaterialEmissionSnapshot;

// Doctrine / Gedmo
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Translation;

// Symfony
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        WasteEmissionSnapshot $wasteSnapshot,
        WasteUiCatalog $wasteCatalog,
        MaterialEmissionSnapshot $materialSnapshot,
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
        $categories = $categoryData['categories'];
        $requestedCategoryId = $request->query->getInt('categoryId');
        $validCategoryIds = array_column($categories, 'id');
        $activeCategoryId = in_array($requestedCategoryId, $validCategoryIds, true)
            ? $requestedCategoryId
            : 0;
        $showAll = '1' === $request->query->get('showAll');
        $visibleRecords = [];
        $totalEmissionKg = null;

        foreach ($categories as &$category) {
            $category['active'] = $category['id'] === $activeCategoryId;
            $showAllCategory = $showAll && $category['active'];
            $category['records'] = $showAllCategory
                ? $category['allRecords']
                : array_slice($category['allRecords'], 0, 5);
            $category['hasMore'] = !$showAllCategory && $category['count'] > 5;
            $category['showAllUrl'] = $this->generateUrl('backend_emission_index', [
                'categoryId' => $category['id'],
                'showAll' => 1,
            ]).'#emission-category-'.$category['id'];
            $category['createUrl'] = $this->buildEmissionCreateUrl(
                $category['id'],
                $categoryData['energyId'],
                $categoryData['transportId'],
                $categoryData['waterId'],
                $categoryData['accommodationId'],
                $categoryData['cateringId'],
                $categoryData['wasteId'],
                $categoryData['materialId'],
            );
            array_push($visibleRecords, ...$category['records']);
            if (null !== $category['totalEmissionKg']) {
                $totalEmissionKg = ($totalEmissionKg ?? 0.0) + $category['totalEmissionKg'];
            }
            unset($category['allRecords']);
        }
        unset($category);

        $presentation = $this->buildVisibleRecordPresentation(
            $visibleRecords,
            $categoryData,
            $transportSnapshot,
            $energySnapshot,
            $waterSnapshot,
            $accommodationSnapshot,
            $cateringSnapshot,
            $wasteSnapshot,
            $wasteCatalog,
            $materialSnapshot,
        );

        return $this->render('backend/emission/index.html.twig', array_merge($presentation, [
            'project' => $project,
            'categories' => $categories,
            'hasCategories' => [] !== $categories,
            'totalEmissionKg' => $totalEmissionKg,
        ]));
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
     *     categories: list<array{id:int,name:string,labelKey:string,icon:string,slug:string,count:int,allRecords:list<EmissionRecord>,totalEmissionKg:?float}>,
     *     energyId: ?int,
     *     transportId: ?int,
     *     waterId: ?int,
     *     accommodationId: ?int,
     *     cateringId: ?int,
     *     wasteId: ?int,
     *     materialId: ?int
     * }
     */
    private function buildEmissionCategoryData(
        array $records,
        array $allCategories,
        EntityManagerInterface $em,
    ): array
    {
        $energyId    = $this->findCategoryIdByNameEs($em, 'Energía');
        $transportId = $this->findCategoryIdByNameEs($em, 'Transporte');
        $waterId     = $this->findCategoryIdByNameEs($em, 'Agua');
        $accommodationId = $this->findCategoryIdByNameEs($em, 'Alojamientos');
        $cateringId = $this->findCategoryIdByNameEs($em, 'Catering');
        $wasteId = $this->findCategoryIdByNameEs($em, 'Residuos');
        $materialId = $this->findCategoryIdByNameEs($em, 'Materiales');
        $categoryDefinitions = [
            ['id' => $transportId, 'name' => 'Transporte', 'labelKey' => 'transport', 'icon' => 'bi-truck', 'slug' => 'transport'],
            ['id' => $waterId, 'name' => 'Agua', 'labelKey' => 'water', 'icon' => 'bi-droplet', 'slug' => 'water'],
            ['id' => $accommodationId, 'name' => 'Alojamientos', 'labelKey' => 'accommodation', 'icon' => 'bi-house-door', 'slug' => 'accommodation'],
            ['id' => $cateringId, 'name' => 'Catering', 'labelKey' => 'catering', 'icon' => 'bi-cup-straw', 'slug' => 'catering'],
            ['id' => $energyId, 'name' => 'Energía', 'labelKey' => 'energy', 'icon' => 'bi-lightning-charge', 'slug' => 'energy'],
            ['id' => $materialId, 'name' => 'Materiales', 'labelKey' => 'materials', 'icon' => 'bi-box-seam', 'slug' => 'materials'],
            ['id' => $wasteId, 'name' => 'Residuos', 'labelKey' => 'waste', 'icon' => 'bi-recycle', 'slug' => 'waste'],
        ];
        $enabledIds = array_map(static fn (Category $category): ?int => $category->getId(), $allCategories);
        $recordsByCategory = [];
        foreach ($records as $record) {
            $categoryId = $record->getEffectiveCategory()?->getId();
            if (null !== $categoryId) {
                $recordsByCategory[$categoryId][] = $record;
            }
        }

        $categories = [];
        foreach ($categoryDefinitions as $definition) {
            if (null === $definition['id'] || !in_array($definition['id'], $enabledIds, true)) {
                continue;
            }
            $categoryRecords = $recordsByCategory[$definition['id']] ?? [];
            $totalEmissionKg = null;
            foreach ($categoryRecords as $record) {
                if (null !== $record->getEmission()) {
                    $totalEmissionKg = ($totalEmissionKg ?? 0.0) + $record->getEmission();
                }
            }
            $categories[] = array_merge($definition, [
                'count' => count($categoryRecords),
                'allRecords' => $categoryRecords,
                'totalEmissionKg' => $totalEmissionKg,
            ]);
        }

        return [
            'categories' => $categories,
            'energyId' => $energyId,
            'transportId' => $transportId,
            'waterId' => $waterId,
            'accommodationId' => $accommodationId,
            'cateringId' => $cateringId,
            'wasteId' => $wasteId,
            'materialId' => $materialId,
        ];
    }

    /** @param list<EmissionRecord> $records
     *  @param array<string, mixed> $categoryData
     *  @return array<string, mixed>
     */
    private function buildVisibleRecordPresentation(
        array $records,
        array $categoryData,
        TransportEmissionSnapshot $transportSnapshot,
        EnergyEmissionSnapshot $energySnapshot,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        WasteEmissionSnapshot $wasteSnapshot,
        WasteUiCatalog $wasteCatalog,
        MaterialEmissionSnapshot $materialSnapshot,
    ): array {
        $data = [
            'transportV20Summaries' => [], 'energyV1Summaries' => [], 'waterV1Summaries' => [],
            'accommodationV1Summaries' => [], 'cateringV1Summaries' => [], 'wasteV1Summaries' => [],
            'materialV1Summaries' => [], 'recordActions' => [],
        ];

        foreach ($records as $record) {
            $id = $record->getId();
            $categoryId = $record->getEffectiveCategory()?->getId();
            if (null === $id || null === $categoryId) {
                continue;
            }

            try {
                if ($categoryId === $categoryData['transportId'] && $transportSnapshot->isTransportV20Record($record, $categoryId)) {
                    $data['recordActions'][$id] = ['editRoute' => 'backend_emission_edit_transport_v20', 'duplicateRoute' => 'backend_emission_duplicate_transport_v20'];
                    $data['transportV20Summaries'][$id] = $transportSnapshot->decodeSummary((string) $record->getCalculationDetails());
                } elseif ($categoryId === $categoryData['energyId'] && $energySnapshot->isEnergyV1Record($record, $categoryId)) {
                    $data['recordActions'][$id] = ['editRoute' => 'backend_emission_edit_energy_v1', 'duplicateRoute' => 'backend_emission_duplicate_energy_v1'];
                    $data['energyV1Summaries'][$id] = $energySnapshot->decodeSummary((string) $record->getCalculationDetails());
                } elseif ($categoryId === $categoryData['waterId'] && $waterSnapshot->isWaterV1Record($record, $categoryId)) {
                    $data['recordActions'][$id] = ['editRoute' => 'backend_emission_edit_water_v1', 'duplicateRoute' => 'backend_emission_duplicate_water_v1'];
                    $input = $waterSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $data['waterV1Summaries'][$id] = ['waterUseType' => $input->waterUseType, 'normalizedUnit' => null === $record->getAmount() ? null : 'm3'];
                } elseif ($categoryId === $categoryData['accommodationId'] && $accommodationSnapshot->isAccommodationV1Record($record, $categoryId)) {
                    $data['recordActions'][$id] = ['editRoute' => 'backend_emission_edit_accommodation_v1', 'duplicateRoute' => 'backend_emission_duplicate_accommodation_v1'];
                    $input = $accommodationSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $data['accommodationV1Summaries'][$id] = [
                        'accommodationType' => $input->accommodationType,
                        'normalizedUnitKey' => match ($input->accommodationType) {
                            'hotel' => 'occupied_room_night', 'hostel' => 'guest_night', 'apartment' => 'person_night', default => null,
                        },
                    ];
                } elseif ($categoryId === $categoryData['cateringId'] && $cateringSnapshot->isCateringV1Record($record, $categoryId)) {
                    $data['recordActions'][$id] = ['editRoute' => 'backend_emission_edit_catering_v1', 'duplicateRoute' => 'backend_emission_duplicate_catering_v1'];
                    $input = $cateringSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $calculation = $cateringSnapshot->decodeCalculation((string) $record->getCalculationDetails());
                    $data['cateringV1Summaries'][$id] = [
                        'activityType' => $input->activityType,
                        'normalizedUnitKey' => match ($calculation['normalizedUnit'] ?? null) {
                            'people' => 'people', 'prepared_menu' => 'prepared_menu', 'prepared sandwich' => 'prepared_sandwich',
                            'L' => 'liter', 'service' => 'service', 'kg' => 'kg', default => null,
                        },
                    ];
                } elseif ($categoryId === $categoryData['wasteId'] && $wasteSnapshot->isWasteV1Record($record, $categoryId)) {
                    $data['recordActions'][$id] = ['editRoute' => 'backend_emission_edit_waste_v1', 'duplicateRoute' => 'backend_emission_duplicate_waste_v1'];
                    $input = $wasteSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $displayActivity = null;
                    if (null !== $input->country && null !== $input->wasteType) {
                        $displayActivity = null !== $input->wasteActivity && $input->wasteActivity !== $input->wasteType
                            ? $input->wasteActivity
                            : ($wasteCatalog->wasteTypeLabel($input->country, $input->wasteType) ?? $input->wasteType);
                    }
                    $data['wasteV1Summaries'][$id] = ['displayActivity' => $displayActivity, 'normalizedUnit' => null === $record->getAmount() ? null : 'kg'];
                } elseif ($categoryId === $categoryData['materialId'] && $materialSnapshot->isMaterialV1Record($record, $categoryId)) {
                    $data['recordActions'][$id] = ['editRoute' => 'backend_emission_edit_material_v1', 'duplicateRoute' => 'backend_emission_duplicate_material_v1'];
                    $input = $materialSnapshot->decodeInput((string) $record->getCalculationDetails());
                    $calculation = $materialSnapshot->decodeCalculation((string) $record->getCalculationDetails());
                    $data['materialV1Summaries'][$id] = ['displayActivity' => $input->subproduct ?: $input->activity, 'normalizedUnit' => $calculation['normalizedUnit'] ?? null];
                }
            } catch (\JsonException|\UnexpectedValueException) {
                // Keep corrupt modern records visible without exposing legacy actions.
            }
        }

        return $data;
    }

    private function modernActivityName(
        EmissionRecord $record,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        WasteEmissionSnapshot $wasteSnapshot,
        WasteUiCatalog $wasteCatalog,
        MaterialEmissionSnapshot $materialSnapshot,
        TranslatorInterface $translator,
    ): string {
        return match ($record->getEffectiveCategory()?->getName()) {
            'Agua' => $this->waterActivityName($record, $waterSnapshot, $translator),
            'Alojamientos' => $this->accommodationActivityName($record, $accommodationSnapshot, $translator),
            'Catering' => $this->cateringActivityName($record, $cateringSnapshot, $translator),
            'Residuos' => $this->wasteActivityName($record, $wasteSnapshot, $wasteCatalog),
            'Materiales' => $this->materialActivityName($record, $materialSnapshot),
            default => '—',
        };
    }

    private function materialActivityName(EmissionRecord $record, MaterialEmissionSnapshot $snapshot): string
    {
        $category = $record->getEffectiveCategory();
        if (!$snapshot->isMaterialV1Record($record, (int) $category?->getId())) {
            return '—';
        }

        try {
            $input = $snapshot->decodeInput((string) $record->getCalculationDetails());
        } catch (\JsonException|\UnexpectedValueException) {
            return '—';
        }

        return $input->subproduct ?: ($input->activity ?: '—');
    }

    private function wasteActivityName(EmissionRecord $record, WasteEmissionSnapshot $snapshot, WasteUiCatalog $catalog): string
    {
        $category = $record->getEffectiveCategory();
        if (!$snapshot->isWasteV1Record($record, (int) $category?->getId())) {
            return '—';
        }

        try {
            $input = $snapshot->decodeInput((string) $record->getCalculationDetails());
        } catch (\JsonException|\UnexpectedValueException) {
            return '—';
        }

        if (null === $input->country || null === $input->wasteType) {
            return '—';
        }
        if (null !== $input->wasteActivity && $input->wasteActivity !== $input->wasteType) {
            return $input->wasteActivity;
        }

        return $catalog->wasteTypeLabel($input->country, $input->wasteType) ?? $input->wasteType;
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
        ?int $wasteId,
        ?int $materialId,
    ): string {
        $params = ['categoryId' => $categoryId];

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

        if ($wasteId !== null && $categoryId === $wasteId) {
            return $this->generateUrl('backend_emission_new_waste_v1', $params);
        }

        if ($materialId !== null && $categoryId === $materialId) {
            return $this->generateUrl('backend_emission_new_material_v1', $params);
        }

        throw new \LogicException(sprintf(
            'Unsupported emission calculator category id %d.',
            $categoryId,
        ));
    }

    private function findCategoryIdByNameEs(EntityManagerInterface $em, string $nameEs): ?int
    {
        $row = $em->createQuery('SELECT c.id FROM ' . Category::class . ' c WHERE c.name = :n')
                ->setParameter('n', $nameEs)
                ->setMaxResults(1)
                ->getOneOrNullResult(); // ['id'=>X] | null
        return $row['id'] ?? null;
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
