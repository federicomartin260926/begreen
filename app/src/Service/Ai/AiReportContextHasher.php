<?php

namespace App\Service\Ai;

use App\Exception\Ai\AiReportRequestException;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiStoredReport;

final readonly class AiReportContextHasher
{
    public function __construct(
        private AiReportPromptBuilder $promptBuilder,
    ) {
    }

    public function hash(AiReportRequest $request): string
    {
        try {
            $context = $this->promptBuilder->buildContext($request);
        } catch (\JsonException) {
            throw new AiReportRequestException('The AI report context could not be hashed safely.');
        }

        $canonical = sprintf(
            "contract=%d\nprompt=%s\ncontext=%s",
            AiStoredReport::VERSION,
            $this->promptBuilder->promptIdentity(),
            $context,
        );

        return hash('sha256', $canonical);
    }

    public function promptVersion(): string
    {
        return $this->promptBuilder->promptVersion();
    }
}
