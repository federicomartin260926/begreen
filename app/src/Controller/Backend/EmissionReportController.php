<?php

namespace App\Controller\Backend;

use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
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
            $category = $record->getActivity()->getCategory()?->getName() ?? $noCategory;

            if (!isset($reportData[$phase])) {
                $reportData[$phase] = [];
            }

            if (!isset($reportData[$phase][$category])) {
                $reportData[$phase][$category] = 0;
            }

            $reportData[$phase][$category] += $record->getEmission();
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
        TranslatorInterface $t
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
        ], $filename);
    }

    #[Route('/report/emissions-by-activity/pdf', name: 'report_emission_by_activity_pdf')]
    public function emissionsByActivityPdf(
        ActiveProjectService $activeProjectService,
        EmissionRecordRepository $recordRepo,
        PdfService $pdfService,
        TranslatorInterface $t
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
        $noActivity = $t->trans('backend.common.no_activity');

        foreach ($records as $record) {
            $activity = $record->getActivity()?->getName() ?? $noActivity;
            $phase    = $record->getPhase()?->getPhase($project->getType()) ?? $noPhase;
            $category = $record->getActivity()?->getCategory()?->getName() ?? $noCategory;

            if (!isset($data[$activity])) {
                $data[$activity] = [];
                $activityCategories[$activity] = $category; // capturar categoría
            }

            if (!isset($data[$activity][$phase])) {
                $data[$activity][$phase] = 0;
            }

            $data[$activity][$phase] += $record->getEmission();
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
}
