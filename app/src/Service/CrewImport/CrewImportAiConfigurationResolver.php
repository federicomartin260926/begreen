<?php

namespace App\Service\CrewImport;

use App\Entity\AiReportSetting;
use App\Service\Ai\AiReportConfiguration;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CrewImportAiConfigurationResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AiReportConfiguration $configuration,
    ) {
    }

    /** @return array{provider: string, model: string} */
    public function resolve(): array
    {
        $setting = $this->entityManager->find(AiReportSetting::class, AiReportSetting::SINGLETON_ID);
        $provider = strtolower(trim(
            $setting instanceof AiReportSetting ? $setting->getProvider() : $this->configuration->provider
        ));
        $model = match ($provider) {
            'openai' => $setting instanceof AiReportSetting
                ? $setting->getOpenAiModel()
                : $this->configuration->openAiModel(),
            'anthropic' => $setting instanceof AiReportSetting
                ? $setting->getAnthropicModel()
                : $this->configuration->anthropicModel(),
            default => '',
        };

        return ['provider' => $provider, 'model' => trim($model)];
    }
}
