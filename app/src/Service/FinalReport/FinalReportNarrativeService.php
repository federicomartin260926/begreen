<?php

declare(strict_types=1);

namespace App\Service\FinalReport;

use App\Entity\FinalReportContent;
use App\Service\Ai\AiReportProviderInterface;
use App\Service\Ai\Dto\AiReportRequest;

final readonly class FinalReportNarrativeService
{
    public const GENERATED_SECTIONS = [
        'executive_summary',
        'implementation',
        'featured_storytelling',
        'deviations_learnings',
        'conclusion',
    ];

    public function __construct(private AiReportProviderInterface $provider)
    {
    }

    /**
     * @param array<string, mixed> $report
     * @param list<string> $replaceManualSections
     * @return list<string> sections actually updated
     */
    public function generate(
        FinalReportContent $content,
        array $report,
        string $locale,
        array $replaceManualSections = [],
    ): array {
        $this->assertProject($content, $report);
        $context = $this->factualContext($content, $report);
        $keys = ['implementation'];
        if ($context['featuredMeasures'] !== []) {
            $keys[] = 'featured_storytelling';
        }
        if ($context['deviationsAndLearnings'] !== []) {
            $keys[] = 'deviations_learnings';
        }

        $currentTexts = $content->getManualTexts();
        $generableSections = array_merge(
            ['executive_summary', 'conclusion'],
            $keys,
        );

        $hasWritableSection = false;
        foreach ($generableSections as $section) {
            $current = $currentTexts[$section] ?? [];
            $protected = ($current['origin'] ?? null) === 'manual'
                && trim((string) ($current['text'] ?? '')) !== '';

            if (!$protected || in_array($section, $replaceManualSections, true)) {
                $hasWritableSection = true;
                break;
            }
        }

        if (!$hasWritableSection) {
            return [];
        }

        $result = $this->provider->generate(new AiReportRequest(
            $locale,
            [],
            'final_sustainability_report',
            $context,
            $keys,
        ));

        $generated = [
            'executive_summary' => $result->generalConclusion,
            'conclusion' => $result->finalConclusion,
        ];
        foreach ($result->categorySummaries as $summary) {
            $generated[$summary->categoryKey] = $summary->summary;
        }

        $texts = $content->getManualTexts();
        $updated = [];
        foreach ($generated as $section => $text) {
            $current = $texts[$section] ?? null;
            $isProtectedManual = ($current['origin'] ?? null) === 'manual'
                && trim((string) ($current['text'] ?? '')) !== '';
            if ($isProtectedManual && !in_array($section, $replaceManualSections, true)) {
                continue;
            }
            $texts[$section] = ['text' => trim($text), 'origin' => 'ai'];
            $updated[] = $section;
        }
        $content->setManualTexts($texts);

        return $updated;
    }

    /** @param array<string, mixed> $report */
    private function assertProject(FinalReportContent $content, array $report): void
    {
        $contentProjectId = $content->getProject()?->getId();
        $reportProjectId = $report['project']['id'] ?? null;
        if ($contentProjectId === null || $contentProjectId !== $reportProjectId) {
            throw new \InvalidArgumentException('The final report content does not belong to the requested project.');
        }
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function factualContext(FinalReportContent $content, array $report): array
    {
        $planById = [];
        foreach ($report['plan']['measures'] ?? [] as $measure) {
            $planById[$measure['planMeasureId'] ?? 0] = $measure;
        }
        $featured = [];
        foreach ($content->getFeaturedMeasures() as $item) {
            $id = $item['planMeasureId'] ?? null;
            if (is_int($id) && isset($planById[$id])) {
                $featured[] = [
                    'measure' => $planById[$id]['measure']['name'] ?? null,
                    'editorialText' => $item['text'] ?? null,
                ];
            }
        }
        $deviations = array_values(array_filter(
            $report['implementation']['measures'] ?? [],
            static fn (array $measure): bool => ($measure['incident'] ?? null) !== null
                || ($measure['observations'] ?? null) !== null
                || in_array($measure['state'] ?? null, ['not_implemented', 'discarded'], true),
        ));

        return [
            'project' => $report['project'] ?? [],
            'planSummary' => [
                'status' => $report['plan']['status'] ?? null,
                'protocol' => $report['plan']['protocol'] ?? null,
                'measureCount' => count($report['plan']['measures'] ?? []),
            ],
            'implementation' => $report['implementation'] ?? [],
            'featuredMeasures' => $featured,
            'deviationsAndLearnings' => $deviations,
            'emissions' => $report['emissions'] ?? [],
            'associatedSdgsNotCertification' => $report['ods'] ?? [],
            'commitment' => $report['commitment'] ?? [],
            'savedEditorialTexts' => $content->getManualTexts(),
        ];
    }
}
