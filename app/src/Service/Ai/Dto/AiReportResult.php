<?php

namespace App\Service\Ai\Dto;

final readonly class AiReportResult
{
    /**
     * @param list<AiReportCategorySummary> $categorySummaries
     * @param list<AiReportCategorySummary> $categoryFutureSummaries
     */
    public function __construct(
        public string $generalConclusion,
        public array $categorySummaries,
        public array $categoryFutureSummaries,
        public string $finalConclusion,
    ) {
    }
}
