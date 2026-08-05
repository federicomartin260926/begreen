<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiProviderNotConfiguredException;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiReportResult;

final class ConfiguredAiReportProvider implements AiReportProviderInterface
{
    public function __construct(
        private readonly AiReportConfiguration $configuration,
        private readonly AiReportProviderInterface $openAiProvider,
        private readonly AiReportProviderInterface $anthropicProvider,
    ) {
    }

    public function generate(AiReportRequest $request): AiReportResult
    {
        return match (strtolower(trim($this->configuration->provider))) {
            'openai' => $this->openAiProvider->generate($request),
            'anthropic' => $this->anthropicProvider->generate($request),
            default => throw new AiProviderNotConfiguredException('The AI report provider is not configured.'),
        };
    }
}
