<?php

namespace App\Controller\Backend;

use App\Entity\EmissionRecord;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\EmissionTraceabilityPresenter;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Waste\WasteUiCatalog;
use App\Service\PdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/emission/reports')]
class EmissionReportController extends AbstractController
{
    private readonly EmissionTraceabilityPresenter $traceabilityPresenter;

    public function __construct(?EmissionTraceabilityPresenter $traceabilityPresenter = null)
    {
        $this->traceabilityPresenter = $traceabilityPresenter ?? new EmissionTraceabilityPresenter();
    }

    #[Route('/overview', name: 'report_emission_overview_pdf')]
    public function overview(
        ActiveProjectService $activeProjectService,
        EmissionRecordRepository $recordRepository,
        PdfService $pdfService,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();

        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.common.no_active_project'));
        }

        $records = $recordRepository->findBy(['project' => $project]);

        // Agrupación por fase y categoría
        $reportData = [];
        $noPhase = $t->trans('backend.common.no_phase');
        $noCategory = $t->trans('backend.common.no_category');

        foreach ($records as $record) {
            $phase = $record->getPhase()?->getPhase($project->getType()) ?? $noPhase;
            $category = $record->getEffectiveCategory()?->getName() ?? $noCategory;

            if (!isset($reportData[$phase])) {
                $reportData[$phase] = [];
            }

            if (!isset($reportData[$phase][$category])) {
                $reportData[$phase][$category] = 0;
            }

            $reportData[$phase][$category] += (float) $record->getEmission();
        }

        $filename = $t->trans('backend.emission.reports.filenames.overview');

        return $pdfService->renderPdf('backend/emission/report/overview.html.twig', [
            'project'     => $project,
            'reportData'  => $reportData,
        ], $filename);
    }

    #[Route('/report/emissions/detailed', name: 'report_emission_detailed_pdf')]
    public function downloadDetailedReport(
        ActiveProjectService $activeProjectService,
        EmissionRecordRepository $recordRepository,
        PdfService $pdfService,
        TranslatorInterface $t,
        TransportEmissionSnapshot $transportSnapshot,
        EnergyEmissionSnapshot $energySnapshot,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        WasteEmissionSnapshot $wasteSnapshot,
        WasteUiCatalog $wasteCatalog,
        MaterialEmissionSnapshot $materialSnapshot,
    ): Response {
        $project = $activeProjectService->getActiveProject();

        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.common.no_active_project'));
        }

        $records = $recordRepository->findBy(['project' => $project]);

        $filename = $t->trans('backend.emission.reports.filenames.detailed');

        return $pdfService->renderPdf('backend/emission/report/detailed.html.twig', [
            'project' => $project,
            'records' => $records,
            'recordPresentations' => array_map(
                fn (EmissionRecord $record): array => $this->recordPresentation(
                    $record,
                    $transportSnapshot,
                    $energySnapshot,
                    $waterSnapshot,
                    $accommodationSnapshot,
                    $cateringSnapshot,
                    $wasteSnapshot,
                    $wasteCatalog,
                    $materialSnapshot,
                    $t,
                ),
                $records,
            ),
            'recordTraceabilities' => array_map(
                fn (EmissionRecord $record): array => $this->recordTraceability($record),
                $records,
            ),
        ], $filename);
    }

    #[Route('/report/emissions-by-activity/pdf', name: 'report_emission_by_activity_pdf')]
    public function emissionsByActivityPdf(
        ActiveProjectService $activeProjectService,
        EmissionRecordRepository $recordRepo,
        PdfService $pdfService,
        TranslatorInterface $t,
        TransportEmissionSnapshot $transportSnapshot,
        EnergyEmissionSnapshot $energySnapshot,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        WasteEmissionSnapshot $wasteSnapshot,
        WasteUiCatalog $wasteCatalog,
        MaterialEmissionSnapshot $materialSnapshot,
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.common.no_active_project'));
        }

        $records = $recordRepo->findBy(['project' => $project]);

        $data = [];
        $activityCategories = [];

        $noPhase = $t->trans('backend.common.no_phase');
        $noCategory = $t->trans('backend.common.no_category');
        foreach ($records as $record) {
            $activity = $this->recordPresentation(
                $record,
                $transportSnapshot,
                $energySnapshot,
                $waterSnapshot,
                $accommodationSnapshot,
                $cateringSnapshot,
                $wasteSnapshot,
                $wasteCatalog,
                $materialSnapshot,
                $t,
            )['activity'];
            $phase    = $record->getPhase()?->getPhase($project->getType()) ?? $noPhase;
            $category = $record->getEffectiveCategory()?->getName() ?? $noCategory;

            if (!isset($data[$activity])) {
                $data[$activity] = [];
                $activityCategories[$activity] = $category; // capturar categoría
            }

            if (!isset($data[$activity][$phase])) {
                $data[$activity][$phase] = 0;
            }

            $data[$activity][$phase] += (float) $record->getEmission();
        }

        $filename = $t->trans('backend.emission.reports.filenames.by_activity');

        return $pdfService->renderPdf(
            'backend/emission/report/by_activity.html.twig',
            [
                'project'             => $project,
                'data'                => $data,
                'activityCategories'  => $activityCategories,
            ],
            $filename
        );
    }

    /** @return array{activity: string, unit: string} */
    private function recordPresentation(
        EmissionRecord $record,
        TransportEmissionSnapshot $transportSnapshot,
        EnergyEmissionSnapshot $energySnapshot,
        WaterEmissionSnapshot $waterSnapshot,
        AccommodationEmissionSnapshot $accommodationSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        WasteEmissionSnapshot $wasteSnapshot,
        WasteUiCatalog $wasteCatalog,
        MaterialEmissionSnapshot $materialSnapshot,
        TranslatorInterface $translator,
    ): array {
        $category = $record->getEffectiveCategory();
        $categoryId = (int) $category?->getId();

        if ('Transporte' === $category?->getName() && $transportSnapshot->isTransportV20Record($record, $categoryId)) {
            try {
                $summary = $transportSnapshot->decodeSummary((string) $record->getCalculationDetails());

                return [
                    'activity' => $translator->trans('backend.emission.transport_v20.modes.'.$summary['mode']),
                    'unit' => null === $summary['displayActivityUnit']
                        ? '—'
                        : $translator->trans('backend.emission.transport_v20.units.'.$summary['displayActivityUnit']),
                ];
            } catch (\JsonException|\UnexpectedValueException) {
            }
        }

        if ('Energía' === $category?->getName() && $energySnapshot->isEnergyV1Record($record, $categoryId)) {
            try {
                $summary = $energySnapshot->decodeSummary((string) $record->getCalculationDetails());

                return [
                    'activity' => $translator->trans('backend.emission.energy_v1.families.'.$summary['family']),
                    'unit' => $summary['normalizedUnit'] ?? '—',
                ];
            } catch (\JsonException|\UnexpectedValueException) {
            }
        }

        if ('Agua' === $category?->getName()
            && $waterSnapshot->isWaterV1Record($record, $categoryId)
        ) {
            try {
                $waterUseType = $waterSnapshot->decodeInput((string) $record->getCalculationDetails())->waterUseType;
                if (null !== $waterUseType && '' !== $waterUseType) {
                    return [
                        'activity' => $translator->trans('backend.emission.water_v1.water_use_types.'.$waterUseType),
                        'unit' => null === $record->getAmount()
                            ? '—'
                            : $translator->trans('backend.emission.water_v1.units.m3'),
                    ];
                }
            } catch (\JsonException|\UnexpectedValueException) {
            }
        }

        if ('Alojamientos' === $category?->getName()
            && $accommodationSnapshot->isAccommodationV1Record($record, $categoryId)
        ) {
            try {
                $type = $accommodationSnapshot->decodeInput((string) $record->getCalculationDetails())->accommodationType;
                $unit = match ($type) {
                    'hotel' => 'occupied_room_night',
                    'hostel' => 'guest_night',
                    'apartment' => 'person_night',
                    default => null,
                };
                if (null !== $type && '' !== $type) {
                    return [
                        'activity' => $translator->trans('backend.emission.accommodation_v1.types.'.$type),
                        'unit' => null === $unit ? '—' : $translator->trans('backend.emission.accommodation_v1.units.'.$unit),
                    ];
                }
            } catch (\JsonException|\UnexpectedValueException) {
            }
        }

        if ('Catering' === $category?->getName()
            && $cateringSnapshot->isCateringV1Record($record, (int) $category->getId())
        ) {
            try {
                $activityType = $cateringSnapshot->decodeInput((string) $record->getCalculationDetails())->activityType;
                $calculation = $cateringSnapshot->decodeCalculation((string) $record->getCalculationDetails());
                if (null !== $activityType && '' !== $activityType) {
                    $unit = $calculation['normalizedUnit'] ?? null;

                    return [
                        'activity' => $translator->trans('backend.emission.catering_v1.activities.'.$activityType),
                        'unit' => is_string($unit) && '' !== $unit
                            ? $translator->trans('backend.emission.catering_v1.units.'.match ($unit) {
                                'prepared_menu' => 'prepared_menu',
                                'prepared sandwich' => 'prepared_sandwich',
                                'L' => 'liter',
                                default => $unit,
                            })
                            : '—',
                    ];
                }
            } catch (\JsonException|\UnexpectedValueException) {
            }
        }

        if ('Residuos' === $category?->getName() && $wasteSnapshot->isWasteV1Record($record, $categoryId)) {
            try {
                $input = $wasteSnapshot->decodeInput((string) $record->getCalculationDetails());
                if (null !== $input->country && null !== $input->wasteType) {
                    $activity = null !== $input->wasteActivity && $input->wasteActivity !== $input->wasteType
                        ? $input->wasteActivity
                        : ($wasteCatalog->wasteTypeLabel($input->country, $input->wasteType) ?? $input->wasteType);

                    return ['activity' => $activity, 'unit' => null === $record->getAmount() ? '—' : 'kg'];
                }
            } catch (\JsonException|\UnexpectedValueException) {
            }
        }

        if ('Materiales' === $category?->getName() && $materialSnapshot->isMaterialV1Record($record, $categoryId)) {
            try {
                $input = $materialSnapshot->decodeInput((string) $record->getCalculationDetails());
                $calculation = $materialSnapshot->decodeCalculation((string) $record->getCalculationDetails());
                $activity = $input->subproduct ?: $input->activity;
                $unit = $calculation['normalizedUnit'] ?? null;
                if (null !== $activity && '' !== $activity) {
                    return [
                        'activity' => $activity,
                        'unit' => is_string($unit) && '' !== $unit ? $unit : '—',
                    ];
                }
            } catch (\JsonException|\UnexpectedValueException) {
            }
        }

        return [
            'activity' => $translator->trans('backend.common.no_activity'),
            'unit' => '—',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recordTraceability(EmissionRecord $record): array
    {
        $details = $record->getCalculationDetails();
        if (!is_string($details) || '' === $details) {
            return [];
        }

        try {
            $snapshot = json_decode($details, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($snapshot) ? $this->traceabilityPresenter->extract($snapshot) : [];
    }
}
