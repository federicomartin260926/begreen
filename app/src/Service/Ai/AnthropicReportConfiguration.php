<?php

namespace App\Service\Ai;

final readonly class AnthropicReportConfiguration
{
    public function __construct(
        public string $apiKey,
        public string $model,
        public string $baseUrl,
        public string $apiVersion,
        public int $maxTokens,
    ) {
    }
}
