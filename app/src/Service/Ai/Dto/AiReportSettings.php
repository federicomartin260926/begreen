<?php

namespace App\Service\Ai\Dto;

final readonly class AiReportSettings
{
    public function __construct(
        public string $provider,
        public string $openAiModel,
        public string $anthropicModel,
        public string $generalInstructions,
        public string $executiveSummaryInstructions,
        public string $categoryInstructions,
        public string $avoidInstructions,
        public string $finalConclusionInstructions,
    ) {
    }

    public function model(): string
    {
        return match ($this->provider) {
            'openai' => $this->openAiModel,
            'anthropic' => $this->anthropicModel,
            default => '',
        };
    }

    public function editorialInstructions(): string
    {
        return implode("\n\n", [
            "GENERAL EDITORIAL RULES\n".$this->generalInstructions,
            "EXECUTIVE SUMMARY (generalConclusion)\n".$this->executiveSummaryInstructions,
            "CATEGORY NARRATIVES (categorySummaries)\n".$this->categoryInstructions,
            "TERMS AND APPROACHES TO AVOID\n".$this->avoidInstructions,
            "FINAL CLOSING (finalConclusion)\n".$this->finalConclusionInstructions,
        ]);
    }

    public function editorialFingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->generalInstructions,
            $this->executiveSummaryInstructions,
            $this->categoryInstructions,
            $this->avoidInstructions,
            $this->finalConclusionInstructions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
