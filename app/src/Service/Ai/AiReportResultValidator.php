<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiInvalidStructureException;
use App\Service\Ai\Dto\AiReportCategorySummary;
use App\Service\Ai\Dto\AiReportResult;

final class AiReportResultValidator
{
    /** @param list<string> $expectedCategoryKeys */
    public function validate(mixed $data, array $expectedCategoryKeys): AiReportResult
    {
        if (
            !is_array($data)
            || !is_string($data['generalConclusion'] ?? null)
            || trim($data['generalConclusion']) === ''
            || !is_array($data['categorySummaries'] ?? null)
        ) {
            throw new AiInvalidStructureException(
                'The AI provider response must contain a non-empty generalConclusion and a categorySummaries array.'
            );
        }

        $summaries = [];
        $seenKeys = [];
        $expectedKeys = array_fill_keys($expectedCategoryKeys, true);
        if (
            count($expectedCategoryKeys) !== count($expectedKeys)
            || count($data['categorySummaries']) !== count($expectedCategoryKeys)
        ) {
            throw new AiInvalidStructureException(sprintf(
                'The AI provider returned %d category summaries; exactly %d were expected.',
                count($data['categorySummaries']),
                count($expectedCategoryKeys),
            ));
        }

        foreach ($data['categorySummaries'] as $index => $summary) {
            if (
                !is_array($summary)
                || !is_string($summary['categoryKey'] ?? null)
                || trim($summary['categoryKey']) === ''
                || !is_string($summary['summary'] ?? null)
                || trim($summary['summary']) === ''
            ) {
                throw new AiInvalidStructureException(sprintf(
                    'The AI provider returned an invalid category summary at index %d.',
                    $index,
                ));
            }

            $categoryKey = trim($summary['categoryKey']);
            if (!isset($expectedKeys[$categoryKey])) {
                throw new AiInvalidStructureException(sprintf(
                    'The AI provider returned an unknown categoryKey: %s.',
                    $categoryKey,
                ));
            }

            if (isset($seenKeys[$categoryKey])) {
                throw new AiInvalidStructureException(sprintf(
                    'The AI provider returned a duplicate categoryKey: %s.',
                    $categoryKey,
                ));
            }

            $seenKeys[$categoryKey] = true;
            $summaries[] = new AiReportCategorySummary($categoryKey, trim($summary['summary']));
        }

        if (count($seenKeys) !== count($expectedKeys)) {
            $missingKeys = array_keys(array_diff_key($expectedKeys, $seenKeys));

            throw new AiInvalidStructureException(sprintf(
                'The AI provider omitted category summaries for: %s.',
                implode(', ', $missingKeys),
            ));
        }

        return new AiReportResult(trim($data['generalConclusion']), $summaries);
    }
}
