<?php

declare(strict_types=1);

namespace App\Service\FinalReport;

use App\Entity\FinalReportContent;
use App\Service\Ai\AiReportProviderInterface;
use App\Service\Ai\Dto\AiReportCategorySummary;
use App\Service\Ai\Dto\AiReportRequest;

final readonly class FinalReportNarrativeService
{
    private const FEATURED_MEASURE_SECTION = 'featured_measure';
    private const FEATURED_MEASURE_TEXT_MAX_LENGTH = 2000;

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
        $context = $this->factualContext($content, $report, $locale);
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

    /**
     * @param array<string, mixed> $report
     * @return array{section:string, text:string, origin:string}
     */
    public function generateSection(
        FinalReportContent $content,
        array $report,
        string $section,
        string $locale,
        bool $replaceManual = false,
    ): array {
        if (!in_array($section, ['executive_summary', 'implementation', 'featured_storytelling'], true)) {
            throw new \InvalidArgumentException('Unknown final report narrative section.');
        }
        $this->assertProject($content, $report);
        $context = $this->factualContext($content, $report, $locale);
        if ($section === 'featured_storytelling' && $context['featuredMeasures'] === []) {
            throw new \InvalidArgumentException('No featured measures selected.');
        }
        $texts = $content->getManualTexts();
        $current = $texts[$section] ?? [];
        $isManual = ($current['origin'] ?? 'manual') !== 'ai'
            && trim((string) ($current['text'] ?? '')) !== '';
        if ($isManual && !$replaceManual) {
            throw new \InvalidArgumentException('Manual text requires explicit replacement.');
        }

        $context['scope'] = 'single_section';
        $context['targetSection'] = $section;
        $request = new AiReportRequest(
            $locale,
            [],
            'final_sustainability_report',
            $context,
            $section === 'executive_summary' ? [] : [$section],
        );
        $result = $this->provider->generate($request);
        if ($section === 'executive_summary') {
            if ($result->categorySummaries !== []) {
                throw new \InvalidArgumentException('Unexpected sections in the AI result.');
            }
            $generated = $result->generalConclusion;
        } else {
            if (count($result->categorySummaries) !== 1
                || ($result->categorySummaries[0]->categoryKey ?? null) !== $section) {
                throw new \InvalidArgumentException('Unexpected sections in the AI result.');
            }
            $generated = $result->categorySummaries[0]->summary;
        }
        $text = trim($generated);
        if ($text === '' || mb_strlen($text) > 10000) {
            throw new \InvalidArgumentException('Invalid generated section narrative.');
        }
        $texts[$section] = ['text' => $text, 'origin' => 'ai'];
        $content->setManualTexts($texts);

        return ['section' => $section, 'text' => $text, 'origin' => 'ai'];
    }

    /**
     * @param array<string, mixed> $report
     * @return array{planMeasureId:int, text:string, origin:string}
     */
    public function generateFeaturedMeasure(
        FinalReportContent $content,
        array $report,
        int $planMeasureId,
        string $locale,
        bool $replaceManual = false,
    ): array {
        $this->assertProject($content, $report);

        $featuredMeasures = $content->getFeaturedMeasures();
        $targetIndex = null;
        $target = null;
        foreach ($featuredMeasures as $index => $featuredMeasure) {
            if (($featuredMeasure['planMeasureId'] ?? null) === $planMeasureId) {
                $targetIndex = $index;
                $target = $featuredMeasure;
                break;
            }
        }
        if (!is_int($targetIndex) || !is_array($target)) {
            throw new \InvalidArgumentException('The measure is not selected as featured.');
        }

        $planMeasure = $this->findReportMeasure($report['plan']['measures'] ?? [], $planMeasureId);
        $implementation = $this->findReportMeasure($report['implementation']['measures'] ?? [], $planMeasureId);
        if ($planMeasure === null || $implementation === null) {
            throw new \InvalidArgumentException('The measure does not belong to the active sustainability plan.');
        }

        $currentText = trim((string) ($target['text'] ?? ''));
        $currentOrigin = in_array($target['origin'] ?? null, ['manual', 'ai'], true)
            ? $target['origin']
            : 'manual';
        if ($currentText !== '' && $currentOrigin === 'manual' && !$replaceManual) {
            throw new \InvalidArgumentException('Manual featured measure text requires explicit replacement.');
        }

        $request = new AiReportRequest(
            $locale,
            [],
            'final_sustainability_report',
            [
                'scope' => self::FEATURED_MEASURE_SECTION,
                'project' => $this->featuredMeasureProjectContext($report['project'] ?? []),
                'measure' => [
                    'planMeasureId' => $planMeasureId,
                    'definition' => [
                        'name' => $planMeasure['measure']['name'] ?? null,
                        'description' => $planMeasure['measure']['description'] ?? null,
                        'implementationGuidance' => $planMeasure['measure']['implementationGuidance'] ?? null,
                    ],
                    'decision' => [
                        'applicable' => $planMeasure['decision']['applicable'] ?? null,
                        'willImplement' => $planMeasure['decision']['willImplement'] ?? null,
                        'primary' => $planMeasure['decision']['primary'] ?? null,
                        'critical' => $planMeasure['decision']['critical'] ?? null,
                    ],
                    'implementation' => [
                        'state' => $implementation['state'] ?? null,
                        'actionTaken' => $implementation['actionTaken'] ?? null,
                        'incident' => $implementation['incident'] ?? null,
                        'observations' => $implementation['observations'] ?? null,
                        'verification' => $implementation['verification'] ?? false,
                    ],
                    'existingEditorialText' => $currentText !== '' ? $currentText : null,
                ],
            ],
            [self::FEATURED_MEASURE_SECTION],
        );

        $result = $this->provider->generate($request);
        if (count($result->categorySummaries) !== 1) {
            throw new \InvalidArgumentException('The AI result does not contain exactly one featured measure summary.');
        }
        $summary = $result->categorySummaries[0] ?? null;
        if (!$summary instanceof AiReportCategorySummary
            || $summary->categoryKey !== self::FEATURED_MEASURE_SECTION) {
            throw new \InvalidArgumentException('The AI result does not contain the featured measure summary.');
        }
        $generatedText = trim($summary->summary);
        if ($generatedText === '' || mb_strlen($generatedText) > self::FEATURED_MEASURE_TEXT_MAX_LENGTH) {
            throw new \InvalidArgumentException('The generated featured measure text is invalid.');
        }

        $featuredMeasures[$targetIndex]['text'] = $generatedText;
        $featuredMeasures[$targetIndex]['origin'] = 'ai';
        $content->setFeaturedMeasures($featuredMeasures);

        return [
            'planMeasureId' => $planMeasureId,
            'text' => $generatedText,
            'origin' => 'ai',
        ];
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

    /**
     * @param mixed $measures
     * @return array<string, mixed>|null
     */
    private function findReportMeasure(mixed $measures, int $planMeasureId): ?array
    {
        if (!is_array($measures)) {
            return null;
        }
        foreach ($measures as $measure) {
            if (is_array($measure) && ($measure['planMeasureId'] ?? null) === $planMeasureId) {
                return $measure;
            }
        }

        return null;
    }

    /** @param mixed $project @return array{name:mixed, type:mixed, country:mixed, mainLocation:mixed} */
    private function featuredMeasureProjectContext(mixed $project): array
    {
        $project = is_array($project) ? $project : [];

        return [
            'name' => $project['name'] ?? null,
            'type' => $project['type'] ?? null,
            'country' => $project['country'] ?? null,
            'mainLocation' => $project['mainLocation'] ?? null,
        ];
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function factualContext(FinalReportContent $content, array $report, string $locale): array
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
            'emissions' => $this->formatEmissionsForNarrative(
                $report['emissions'] ?? [],
                $locale,
            ),
            'associatedSdgsNotCertification' => $report['ods'] ?? [],
            'commitment' => $report['commitment'] ?? [],
            'savedEditorialTexts' => $content->getManualTexts(),
        ];
    }

    /** @param array<string, mixed> $emissions @return array<string, mixed> */
    private function formatEmissionsForNarrative(array $emissions, string $locale): array
    {
        $language = strtolower(explode('-', str_replace('_', '-', $locale), 2)[0]);
        [$decimalSeparator, $thousandsSeparator] = $language === 'en'
            ? ['.', ',']
            : [',', '.'];

        if (is_numeric($emissions['totalKgCo2e'] ?? null)) {
            $emissions['totalKgCo2e'] = number_format(
                (float) $emissions['totalKgCo2e'],
                2,
                $decimalSeparator,
                $thousandsSeparator,
            );
        }

        foreach (['byCategory', 'byPhase'] as $breakdown) {
            foreach ($emissions[$breakdown] ?? [] as $index => $item) {
                if (is_array($item) && is_numeric($item['kgCo2e'] ?? null)) {
                    $emissions[$breakdown][$index]['kgCo2e'] = number_format(
                        (float) $item['kgCo2e'],
                        2,
                        $decimalSeparator,
                        $thousandsSeparator,
                    );
                }
            }
        }

        return $emissions;
    }
}
