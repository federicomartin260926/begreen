<?php

namespace App\Service\Ai;

final readonly class OpenAiReportConfiguration
{
    public function __construct(
        public string $apiKey,
        public string $model,
        public string $baseUrl,
    ) {
    }
}
