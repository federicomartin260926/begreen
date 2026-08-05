<?php

namespace App\Service\Ai\Dto;

final readonly class AiReportCategory
{
    /** @param list<AiReportMeasure> $measures */
    public function __construct(
        public string $key,
        public string $name,
        public array $measures,
    ) {
    }
}
