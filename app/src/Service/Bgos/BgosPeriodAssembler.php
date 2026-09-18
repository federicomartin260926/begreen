<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\Project;

final class BgosPeriodAssembler
{
    public function __construct(
        private readonly BgosCompletionCalculator $completionCalculator,
        private readonly BgosCompletionAggregator $completionAggregator,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $catalogCategories
     * @param array<string, array<string, BgosSubcategoryConfig>> $configs
     * @param list<BgosDailyRecord> $dailyRecords
     * @return array{
     *     periodStart: \DateTimeImmutable,
     *     periodEnd: \DateTimeImmutable,
     *     totalKgCo2e: ?float,
     *     categories: list<array<string, mixed>>
     * }
     */
    public function build(
        Project $project,
        array $catalogCategories,
        array $configs,
        array $dailyRecords,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        \DateTimeInterface $today,
    ): array {
        $periodStart = $this->normalizeDate($periodStart);
        $periodEnd = $this->normalizeDate($periodEnd);
        $today = $this->normalizeDate($today);

        if ($periodEnd < $periodStart) {
            throw new \InvalidArgumentException('BGoS period end cannot be before start.');
        }

        $categories = [];
        $globalEmission = 0.0;
        $hasGlobalEmission = false;

        foreach ($catalogCategories as $categoryDefinition) {
            $subcategoryRows = [];
            $categoryCompletionResults = [];
            $categoryEmission = 0.0;
            $hasCategoryEmission = false;

            foreach ($categoryDefinition['subcategories'] as $definition) {
                $persistedConfig = $configs[$definition['categoryKey']][$definition['subcategoryKey']]
                    ?? null;
                $configured = null !== $persistedConfig;
                $config = $persistedConfig
                    ?? $this->defaultConfig($project, $definition);

                $matchingDailyRecords = array_values(array_filter(
                    $dailyRecords,
                    static fn (BgosDailyRecord $record): bool =>
                        $record->categoryKey === $definition['categoryKey']
                        && $record->subcategoryKey === $definition['subcategoryKey']
                ));

                $completionResults = [];

                foreach ($project->getPhaseDates() as $phase) {
                    $result = $this->completionCalculator->calculate(
                        $config,
                        $phase,
                        $periodStart,
                        $periodEnd,
                        $today,
                        $matchingDailyRecords,
                    );

                    if (null !== $result) {
                        $completionResults[] = $result;
                    }
                }

                $completion = $this->completionAggregator->aggregate($completionResults);
                $trackingStatus = $configured
                    ? $this->trackingStatus($completionResults, $completion)
                    : 'unconfigured';

                $categoryCompletionResults[] = new BgosCompletionResult(
                    $completion->status,
                    $completion->expectedCount,
                    $completion->completedCount,
                    $completion->pendingCount,
                );

                $periodRecords = array_values(array_filter(
                    $matchingDailyRecords,
                    static fn (BgosDailyRecord $record): bool =>
                        $record->date >= $periodStart
                        && $record->date <= $periodEnd
                ));

                $subcategoryEmission = $this->sumEmission($periodRecords);

                if (null !== $subcategoryEmission) {
                    $categoryEmission += $subcategoryEmission;
                    $hasCategoryEmission = true;
                }

                $subcategoryRows[] = [
                    'key' => $definition['subcategoryKey'],
                    'label' => $definition['label'],
                    'labelKey' => $definition['labelKey'],
                    'groupKey' => $definition['groupKey'],
                    'groupLabel' => $definition['groupLabel'],
                    'active' => $config->isActive(),
                    'configured' => $configured,
                    'trackingStatus' => $trackingStatus,
                    'totalKgCo2e' => $subcategoryEmission,
                    'completion' => $completion,
                    'dailyRecords' => $periodRecords,
                ];
            }

            $categoryCompletion = $this->completionAggregator->aggregate(
                $categoryCompletionResults
            );

            $categoryTotal = $hasCategoryEmission ? $categoryEmission : null;

            if (null !== $categoryTotal) {
                $globalEmission += $categoryTotal;
                $hasGlobalEmission = true;
            }

            $categories[] = [
                'key' => $categoryDefinition['key'],
                'labelKey' => $categoryDefinition['labelKey'],
                'totalKgCo2e' => $categoryTotal,
                'completion' => $categoryCompletion,
                'subcategories' => $subcategoryRows,
            ];
        }

        return [
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'totalKgCo2e' => $hasGlobalEmission ? $globalEmission : null,
            'categories' => $categories,
        ];
    }

    /**
     * @param array{
     *     categoryKey:string,
     *     subcategoryKey:string,
     *     label:string
     * } $definition
     */
    private function defaultConfig(
        Project $project,
        array $definition,
    ): BgosSubcategoryConfig {
        return (new BgosSubcategoryConfig())
            ->setProject($project)
            ->setCategoryKey($definition['categoryKey'])
            ->setSubcategoryKey($definition['subcategoryKey'])
            ->setLabel($definition['label'])
            ->setActive(true);
    }

    /**
     * @param list<BgosCompletionResult> $results
     */
    private function trackingStatus(
        array $results,
        BgosCompletionAggregate $aggregate,
    ): string {
        if ($aggregate->expectedCount > 0) {
            return $aggregate->status;
        }

        if ([] === $results) {
            return BgosCompletionResult::STATUS_NOT_APPLICABLE;
        }

        $statuses = array_values(array_unique(array_map(
            static fn (BgosCompletionResult $result): string => $result->status,
            $results,
        )));

        $unexpectedStatuses = array_diff(
            $statuses,
            [
                BgosCompletionResult::STATUS_NOT_APPLICABLE,
                BgosCompletionResult::STATUS_FUTURE,
            ],
        );

        if ([] !== $unexpectedStatuses) {
            return $aggregate->status;
        }

        if (in_array(BgosCompletionResult::STATUS_FUTURE, $statuses, true)) {
            return BgosCompletionResult::STATUS_FUTURE;
        }

        return BgosCompletionResult::STATUS_NOT_APPLICABLE;
    }

    /**
     * @param list<BgosDailyRecord> $records
     */
    private function sumEmission(array $records): ?float
    {
        $total = 0.0;
        $hasEmission = false;

        foreach ($records as $record) {
            if (null === $record->kgCo2e) {
                continue;
            }

            $total += $record->kgCo2e;
            $hasEmission = true;
        }

        return $hasEmission ? $total : null;
    }

    private function normalizeDate(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }
}
