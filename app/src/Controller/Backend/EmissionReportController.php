<?php

namespace App\Controller\Backend;

use App\Entity\EmissionRecord;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\PdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/emission/reports')]
class EmissionReportController extends AbstractController
{
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
        WaterEmissionSnapshot $waterSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
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
                fn (EmissionRecord $record): array => $this->recordPresentation($record, $waterSnapshot, $cateringSnapshot, $t),
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
        WaterEmissionSnapshot $waterSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
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
            $activity = $this->recordPresentation($record, $waterSnapshot, $cateringSnapshot, $t)['activity'];
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
        WaterEmissionSnapshot $waterSnapshot,
        CateringEmissionSnapshot $cateringSnapshot,
        TranslatorInterface $translator,
    ): array {
        $activity = $record->getActivity();
        if (null !== $activity) {
            return [
                'activity' => $activity->getName(),
                'unit' => $activity->getUnit(),
            ];
        }

        $category = $record->getEffectiveCategory();
        if ('Agua' === $category?->getName()
            && $waterSnapshot->isWaterV1Record($record, (int) $category->getId())
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

        return [
            'activity' => $translator->trans('backend.common.no_activity'),
            'unit' => '—',
        ];
    }
}
