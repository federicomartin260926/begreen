<?php

namespace App\Service\Ai\Dto;

final readonly class AiReportRequest
{
    /** @param list<AiReportCategory> $categories */
    public function __construct(
        public string $locale,
        public array $categories,
    ) {
    }
}
