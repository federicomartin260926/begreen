<?php

namespace App\Service\Ai;

use App\Service\Ai\Dto\AiReportCategory;
use App\Service\Ai\Dto\AiReportMeasure;
use App\Service\Ai\Dto\AiReportRequest;

final class AiReportPromptBuilder
{
    public function __construct(
        private readonly AiReportPromptConfiguration $promptConfiguration,
    ) {
    }

    public function buildInstructions(): string
    {
        return $this->promptConfiguration->instructions();
    }

    public function promptVersion(): string
    {
        return $this->promptConfiguration->version();
    }

    public function buildContext(AiReportRequest $request): string
    {
        $context = [
            'locale' => $request->locale,
            'categories' => array_map(
                static fn (AiReportCategory $category): array => [
                    'key' => $category->key,
                    'name' => $category->name,
                    'measures' => array_map(
                        static fn (AiReportMeasure $measure): array => [
                            'key' => $measure->key,
                            'title' => $measure->title,
                            'description' => $measure->description,
                            'decision' => $measure->decision->value,
                            'critical' => $measure->critical,
                            'observations' => $measure->observations,
                            'score' => $measure->score,
                        ],
                        $category->measures,
                    ),
                ],
                $request->categories,
            ),
        ];

        return json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
