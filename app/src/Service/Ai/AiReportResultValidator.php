<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiInvalidStructureException;
use App\Service\Ai\Dto\AiReportCategorySummary;
use App\Service\Ai\Dto\AiReportResult;

final class AiReportResultValidator
{
    /**
     * @param list<string> $expectedCategoryKeys
     * @param list<string> $expectedFutureCategoryKeys
     */
    public function validate(mixed $data, array $expectedCategoryKeys, array $expectedFutureCategoryKeys): AiReportResult
    {
        if (
            !is_array($data)
            || !$this->hasExactKeys($data, ['generalConclusion', 'categorySummaries', 'categoryFutureSummaries', 'finalConclusion'])
            || !is_string($data['generalConclusion'] ?? null)
            || trim($data['generalConclusion']) === ''
            || !is_array($data['categorySummaries'] ?? null)
            || !is_array($data['categoryFutureSummaries'] ?? null)
            || !is_string($data['finalConclusion'] ?? null)
            || trim($data['finalConclusion']) === ''
        ) {
            throw new AiInvalidStructureException(
                'The AI provider response must contain non-empty conclusions and both category summary arrays.'
            );
        }

        $summaries = $this->validateSummaries($data['categorySummaries'], $expectedCategoryKeys, 'category');
        $futureSummaries = $this->validateSummaries(
            $data['categoryFutureSummaries'],
            $expectedFutureCategoryKeys,
            'future category',
        );

        return new AiReportResult(
            trim($data['generalConclusion']),
            $summaries,
            $futureSummaries,
            trim($data['finalConclusion']),
        );
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $expectedCategoryKeys
     *
     * @return list<AiReportCategorySummary>
     */
    private function validateSummaries(array $data, array $expectedCategoryKeys, string $label): array
    {
        $summaries = [];
        $seenKeys = [];
        $expectedKeys = array_fill_keys($expectedCategoryKeys, true);
        if (
            count($expectedCategoryKeys) !== count($expectedKeys)
            || count($data) !== count($expectedCategoryKeys)
        ) {
            throw new AiInvalidStructureException(sprintf(
                'The AI provider returned %d %s summaries; exactly %d were expected.',
                count($data),
                $label,
                count($expectedCategoryKeys),
            ));
        }

        foreach ($data as $index => $summary) {
            if (
                !is_array($summary)
                || !$this->hasExactKeys($summary, ['categoryKey', 'summary'])
                || !is_string($summary['categoryKey'] ?? null)
                || trim($summary['categoryKey']) === ''
                || !is_string($summary['summary'] ?? null)
                || trim($summary['summary']) === ''
            ) {
                throw new AiInvalidStructureException(sprintf(
                    'The AI provider returned an invalid %s summary at index %d.',
                    $label,
                    $index,
                ));
            }

            $categoryKey = trim($summary['categoryKey']);
            if (!isset($expectedKeys[$categoryKey])) {
                throw new AiInvalidStructureException(sprintf(
                    'The AI provider returned an unknown %s key: %s.',
                    $label,
                    $categoryKey,
                ));
            }

            if (isset($seenKeys[$categoryKey])) {
                throw new AiInvalidStructureException(sprintf(
                    'The AI provider returned a duplicate %s key: %s.',
                    $label,
                    $categoryKey,
                ));
            }

            $seenKeys[$categoryKey] = true;
            $summaries[] = new AiReportCategorySummary($categoryKey, trim($summary['summary']));
        }

        if (count($seenKeys) !== count($expectedKeys)) {
            $missingKeys = array_keys(array_diff_key($expectedKeys, $seenKeys));

            throw new AiInvalidStructureException(sprintf(
                'The AI provider omitted %s summaries for: %s.',
                $label,
                implode(', ', $missingKeys),
            ));
        }

        return $summaries;
    }

    /** @param list<string> $expectedKeys */
    private function hasExactKeys(array $data, array $expectedKeys): bool
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }
}
