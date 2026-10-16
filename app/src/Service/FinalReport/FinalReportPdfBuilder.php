<?php

declare(strict_types=1);

namespace App\Service\FinalReport;

use App\Entity\FinalReportContent;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Service\PdfService;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use App\Service\ReportDesign\ReportDesignCatalog;
use App\Service\ReportDesign\ReportDesignRenderer;

final readonly class FinalReportPdfBuilder
{
    public function __construct(
        private PdfService $pdfService,
        private ProjectDocumentStorage $documentStorage,
        private FinalReportContentEditor $contentEditor,
        private ReportDesignCatalog $designCatalog,
        private ReportDesignRenderer $designRenderer,
    ) {
    }

    /** @param array<string, mixed> $report */
    public function generate(Project $project, FinalReportContent $content, array $report, bool $draftDesign = false): string
    {
        return $this->pdfService->generatePdf(
            'backend/final_report/pdf.html.twig',
            $this->buildData($project, $content, $report, $draftDesign),
        );
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    public function buildData(Project $project, FinalReportContent $content, array $report, bool $draftDesign = false): array
    {
        if (!$this->sameProject($project, $content->getProject())
            || ($report['project']['id'] ?? null) !== $project->getId()) {
            throw new \InvalidArgumentException('The final report content does not belong to the requested project.');
        }

        $documents = [];
        foreach ($project->getProjectDocuments() as $document) {
            if ($document instanceof ProjectDocument
                && $document->getId() !== null
                && $this->contentEditor->isSupportedImageDocument($document)) {
                $documents[$document->getId()] = $document;
            }
        }
        $photos = [];
        foreach ($content->getPhotos() as $photo) {
            $id = $photo['documentId'] ?? null;
            if (!is_int($id) || !isset($documents[$id])) {
                continue;
            }
            $path = $this->documentStorage->absolutePath($documents[$id]);
            $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            if (!is_string($bytes)) {
                continue;
            }
            $photos[] = array_merge($photo, [
                'dataUri' => 'data:'.$documents[$id]->getMimeType().';base64,'.base64_encode($bytes),
                'isCover' => $content->getCoverDocumentId() === $id,
            ]);
        }

        $implementationById = [];
        foreach ($report['implementation']['measures'] ?? [] as $measure) {
            $implementationById[$measure['planMeasureId'] ?? 0] = $measure;
        }
        $planById = [];
        foreach ($report['plan']['measures'] ?? [] as $measure) {
            $planById[$measure['planMeasureId'] ?? 0] = $measure;
        }
        $featured = [];
        foreach ($content->getFeaturedMeasures() as $item) {
            $id = $item['planMeasureId'] ?? null;
            if (is_int($id) && isset($planById[$id])) {
                $featured[] = array_merge($item, [
                    'plan' => $planById[$id],
                    'implementation' => $implementationById[$id] ?? null,
                ]);
            }
        }

        $data = [
            'project' => $project,
            'report' => $report,
            'content' => $content,
            'texts' => $content->getManualTexts(),
            'featuredMeasures' => $featured,
            'photos' => $photos,
            'coverPhoto' => current(array_filter($photos, static fn (array $photo): bool => $photo['isCover'])) ?: null,
            'reportDesignHtml' => null,
            'reportDesignCss' => null,
        ];
        $definition = $this->designCatalog->finalSustainabilityReportGeneral();
        try {
            $design = $draftDesign
                ? $this->designRenderer->renderDraft($definition, $data)
                : $this->designRenderer->renderApproved($definition, $data);
        } catch (\Throwable $exception) {
            if ($draftDesign) {
                throw $exception;
            }
            $design = null;
        }
        if ($draftDesign && $design === null) {
            throw new \RuntimeException('There is no draft final report design to preview.');
        }
        $data['reportDesignHtml'] = $design['html'] ?? null;
        $data['reportDesignCss'] = $design['css'] ?? null;

        return $data;
    }

    private function sameProject(Project $expected, ?Project $actual): bool
    {
        return $actual === $expected || ($actual instanceof Project
            && $expected->getId() !== null
            && $actual->getId() === $expected->getId());
    }
}
