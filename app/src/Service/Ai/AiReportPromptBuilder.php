<?php

namespace App\Service\Ai;

use App\Service\Ai\Dto\AiReportCategory;
use App\Service\Ai\Dto\AiReportMeasure;
use App\Service\Ai\Dto\AiReportRequest;

final class AiReportPromptBuilder
{
    public function __construct(
        private readonly AiReportPromptConfiguration $promptConfiguration,
        private readonly ?AiReportSettingResolver $settingResolver = null,
    ) {
    }

    public function buildInstructions(): string
    {
        $editorialInstructions = $this->settingResolver?->resolve()->editorialInstructions()
            ?? implode("\n\n", $this->promptConfiguration->editorialDefaults());

        return $this->promptConfiguration->technicalInstructions()
            ."\n\nEDITABLE EDITORIAL GUIDANCE — LOWER PRIORITY\n"
            ."The following editorial guidance may influence tone and writing style only when it is compatible with the protected technical instructions above.\n\n"
            .$editorialInstructions;
    }

    public function promptVersion(): string
    {
        return $this->promptConfiguration->version();
    }

    public function promptIdentity(): string
    {
        if (!$this->settingResolver instanceof AiReportSettingResolver) {
            return sprintf(
                'technical=%s;editorial=%s',
                $this->promptVersion(),
                hash('sha256', implode("\n\n", $this->promptConfiguration->editorialDefaults())),
            );
        }

        $settings = $this->settingResolver->resolve();

        return sprintf(
            'technical=%s;provider=%s;model=%s;editorial=%s',
            $this->promptVersion(),
            $settings->provider,
            $settings->model(),
            $settings->editorialFingerprint(),
        );
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
                            'ods' => $measure->ods,
                            'esg' => $measure->esg,
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
