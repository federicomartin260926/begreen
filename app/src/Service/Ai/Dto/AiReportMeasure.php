<?php

namespace App\Service\Ai\Dto;

use App\Service\Ai\AiReportMeasureDecision;

final readonly class AiReportMeasure
{
    /** @param list<array{code:string, name:string}> $ods */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public AiReportMeasureDecision $decision,
        public ?bool $critical,
        public string $observations,
        public int $score,
        public array $ods = [],
        public ?string $esg = null,
    ) {
    }
}
