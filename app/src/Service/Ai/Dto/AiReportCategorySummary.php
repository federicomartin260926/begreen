<?php

namespace App\Service\Ai\Dto;

final readonly class AiReportCategorySummary
{
    public function __construct(
        public string $categoryKey,
        public string $summary,
    ) {
    }
}
