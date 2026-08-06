<?php

namespace App\Service\Ai\Dto;

use App\Service\Ai\AiReportMeasureDecision;

final readonly class AiReportMeasure
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public AiReportMeasureDecision $decision,
        public ?bool $critical,
        public string $observations,
        public int $score,
    ) {
    }
}
