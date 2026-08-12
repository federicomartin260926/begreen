<?php

namespace App\Service\Ai;

final readonly class AiProviderAvailability
{
    public function __construct(
        private OpenAiReportConfiguration $openAiConfiguration,
        private AnthropicReportConfiguration $anthropicConfiguration,
    ) {
    }

    public function isAvailable(string $provider): bool
    {
        return match (strtolower(trim($provider))) {
            'openai' => trim($this->openAiConfiguration->apiKey) !== '',
            'anthropic' => trim($this->anthropicConfiguration->apiKey) !== '',
            default => false,
        };
    }

    /** @return array{openai: bool, anthropic: bool} */
    public function all(): array
    {
        return [
            'openai' => $this->isAvailable('openai'),
            'anthropic' => $this->isAvailable('anthropic'),
        ];
    }
}
