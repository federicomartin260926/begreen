<?php

namespace App\Service\Ai\Dto;

use App\Service\Ai\AiReportMeasureDecision;

final readonly class AiReportMeasure
{
    public function __construct(
        public string $title,
        public string $description,
        public AiReportMeasureDecision $decision,
        public string $observations,
        public int $score,
    ) {
    }
}
