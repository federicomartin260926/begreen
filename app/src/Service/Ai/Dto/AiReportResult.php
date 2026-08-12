<?php

namespace App\Service\Ai\Dto;

final readonly class AiReportResult
{
    /** @param list<AiReportCategorySummary> $categorySummaries */
    public function __construct(
        public string $generalConclusion,
        public array $categorySummaries,
        public string $finalConclusion,
    ) {
    }
}
