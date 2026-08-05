<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiInvalidStructureException;
use App\Service\Ai\Dto\AiReportCategorySummary;
use App\Service\Ai\Dto\AiReportResult;

final class AiReportResultValidator
{
    public function validate(mixed $data): AiReportResult
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
            if (isset($seenKeys[$categoryKey])) {
                throw new AiInvalidStructureException('The AI provider response has an invalid structure.');
            }

            $seenKeys[$categoryKey] = true;
            $summaries[] = new AiReportCategorySummary($categoryKey, trim($summary['summary']));
        }

        return new AiReportResult(trim($data['generalConclusion']), $summaries);
    }
}
