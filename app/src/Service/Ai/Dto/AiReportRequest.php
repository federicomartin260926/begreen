<?php

namespace App\Service\Ai\Dto;

use App\Service\Ai\AiReportPhase;

final readonly class AiReportRequest
{
    /** @param list<AiReportCategory> $categories */
    public function __construct(
        public AiReportPhase $phase,
        public string $locale,
        public array $categories,
    ) {
    }
}
