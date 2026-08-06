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
            throw new AiInvalidStructureException('The AI provider response has an invalid structure.');
        }

        $summaries = [];
        $seenKeys = [];
        $expectedKeys = array_fill_keys($expectedCategoryKeys, true);
        if (
            count($expectedCategoryKeys) !== count($expectedKeys)
            || count($data['categorySummaries']) !== count($expectedCategoryKeys)
        ) {
            throw new AiInvalidStructureException('The AI provider response has an invalid structure.');
        }

        foreach ($data['categorySummaries'] as $summary) {
            if (
                !is_array($summary)
                || !is_string($summary['categoryKey'] ?? null)
                || trim($summary['categoryKey']) === ''
                || !is_string($summary['summary'] ?? null)
                || trim($summary['summary']) === ''
            ) {
                throw new AiInvalidStructureException('The AI provider response has an invalid structure.');
            }

            $categoryKey = trim($summary['categoryKey']);
            if (!isset($expectedKeys[$categoryKey]) || isset($seenKeys[$categoryKey])) {
                throw new AiInvalidStructureException('The AI provider response has an invalid structure.');
            }

            $seenKeys[$categoryKey] = true;
            $summaries[] = new AiReportCategorySummary($categoryKey, trim($summary['summary']));
        }

        if (count($seenKeys) !== count($expectedKeys)) {
            throw new AiInvalidStructureException('The AI provider response has an invalid structure.');
        }

        return new AiReportResult(trim($data['generalConclusion']), $summaries);
    }
}
