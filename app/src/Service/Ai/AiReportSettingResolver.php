<?php

namespace App\Service\Ai;

use App\Entity\AiReportSetting;
use App\Service\Ai\Dto\AiReportSettings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AiReportSettingResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AiReportConfiguration $configuration,
        private AiReportPromptConfiguration $promptConfiguration,
    ) {
    }

    public function resolve(): AiReportSettings
    {
        $setting = $this->entityManager->find(AiReportSetting::class, AiReportSetting::SINGLETON_ID);

        return $setting instanceof AiReportSetting ? $this->fromEntity($setting) : $this->defaults();
    }

    public function editableSetting(): AiReportSetting
    {
        $setting = $this->entityManager->find(AiReportSetting::class, AiReportSetting::SINGLETON_ID);
        if ($setting instanceof AiReportSetting) {
            return $setting;
        }

        $defaults = $this->defaults();

        return (new AiReportSetting())
            ->setProvider($defaults->provider)
            ->setOpenAiModel($defaults->openAiModel)
            ->setAnthropicModel($defaults->anthropicModel)
            ->setGeneralInstructions($defaults->generalInstructions)
            ->setExecutiveSummaryInstructions($defaults->executiveSummaryInstructions)
            ->setCategoryInstructions($defaults->categoryInstructions)
            ->setFutureCategoryInstructions($defaults->futureCategoryInstructions)
            ->setAvoidInstructions($defaults->avoidInstructions)
            ->setFinalConclusionInstructions($defaults->finalConclusionInstructions);
    }

    public function restoreEditorialDefaults(AiReportSetting $setting): void
    {
        $defaults = $this->promptConfiguration->editorialDefaults();

        $setting
            ->setGeneralInstructions($defaults['general'])
            ->setExecutiveSummaryInstructions($defaults['executive_summary'])
            ->setCategoryInstructions($defaults['category'])
            ->setFutureCategoryInstructions($defaults['future_category'])
            ->setAvoidInstructions($defaults['avoid'])
            ->setFinalConclusionInstructions($defaults['final_conclusion']);
    }

    private function defaults(): AiReportSettings
    {
        $defaults = $this->promptConfiguration->editorialDefaults();

        return new AiReportSettings(
            strtolower(trim($this->configuration->provider)),
            $this->configuration->openAiModel(),
            $this->configuration->anthropicModel(),
            $defaults['general'],
            $defaults['executive_summary'],
            $defaults['category'],
            $defaults['future_category'],
            $defaults['avoid'],
            $defaults['final_conclusion'],
        );
    }

    private function fromEntity(AiReportSetting $setting): AiReportSettings
    {
        return new AiReportSettings(
            $setting->getProvider(),
            $setting->getOpenAiModel(),
            $setting->getAnthropicModel(),
            $setting->getGeneralInstructions(),
            $setting->getExecutiveSummaryInstructions(),
            $setting->getCategoryInstructions(),
            $setting->getFutureCategoryInstructions(),
            $setting->getAvoidInstructions(),
            $setting->getFinalConclusionInstructions(),
        );
    }
}
