<?php

namespace App\Service\Ai;

final readonly class AiReportConfiguration
{
    public function __construct(
        public string $provider,
        public int $timeoutSeconds,
        public int $minMeasureScore,
        public string $alertEmail,
        private OpenAiReportConfiguration $openAi,
        private AnthropicReportConfiguration $anthropic,
    ) {
    }

    public function model(): string
    {
        return match (strtolower(trim($this->provider))) {
            'openai' => $this->openAi->model,
            'anthropic' => $this->anthropic->model,
            default => '',
        };
    }

    public function openAiModel(): string
    {
        return trim($this->openAi->model);
    }

    public function anthropicModel(): string
    {
        return trim($this->anthropic->model);
    }
}
