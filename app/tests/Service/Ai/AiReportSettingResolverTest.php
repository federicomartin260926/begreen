<?php

namespace App\Tests\Service\Ai;

use App\Entity\AiReportSetting;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiReportContextHasher;
use App\Service\Ai\AiReportPromptBuilder;
use App\Service\Ai\AiReportPromptConfiguration;
use App\Service\Ai\AiReportSettingResolver;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\OpenAiReportConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AiReportSettingResolverTest extends TestCase
{
    public function testFallsBackToEnvironmentModelsAndEditorialDefaults(): void
    {
        $resolver = $this->resolver(null);

        $settings = $resolver->resolve();

        self::assertSame('openai', $settings->provider);
        self::assertSame('gpt-env', $settings->model());
        self::assertStringContainsString('primera persona del plural', $settings->generalInstructions);
        self::assertStringContainsString('próxima edición', $settings->futureCategoryInstructions);
        self::assertStringContainsString('cierre final inspiracional', $settings->finalConclusionInstructions);
    }

    public function testEditorialChangeChangesTheEffectivePromptHash(): void
    {
        $setting = (new AiReportSetting())
            ->setProvider('anthropic')
            ->setOpenAiModel('gpt-admin')
            ->setAnthropicModel('claude-admin')
            ->setGeneralInstructions('Reglas generales iniciales.')
            ->setExecutiveSummaryInstructions('Reglas del resumen.')
            ->setCategoryInstructions('Reglas por categoría.')
            ->setFutureCategoryInstructions('Reglas iniciales de próxima edición.')
            ->setAvoidInstructions('Reglas a evitar.')
            ->setFinalConclusionInstructions('Reglas del cierre.');
        $resolver = $this->resolver($setting);
        $hasher = new AiReportContextHasher(new AiReportPromptBuilder($this->promptConfiguration(), $resolver));
        $request = new AiReportRequest('es', []);

        $before = $hasher->hash($request);
        $setting->setFutureCategoryInstructions('Reglas modificadas de próxima edición.');

        self::assertNotSame($before, $hasher->hash($request));
        self::assertSame('claude-admin', $resolver->resolve()->model());
    }

    private function resolver(?AiReportSetting $setting): AiReportSettingResolver
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')
            ->with(AiReportSetting::class, AiReportSetting::SINGLETON_ID)
            ->willReturn($setting);

        return new AiReportSettingResolver(
            $entityManager,
            new AiReportConfiguration(
                'openai',
                30,
                4,
                '',
                new OpenAiReportConfiguration('', 'gpt-env', ''),
                new AnthropicReportConfiguration('', 'claude-env', '', '', 4096),
            ),
            $this->promptConfiguration(),
        );
    }

    private function promptConfiguration(): AiReportPromptConfiguration
    {
        return new AiReportPromptConfiguration(dirname(__DIR__, 3).'/config/ai_report_prompt.yaml');
    }
    public function testRestoreEditorialDefaultsKeepsProviderAndModels(): void
    {
        $setting = (new AiReportSetting())
            ->setProvider('anthropic')
            ->setOpenAiModel('custom-openai')
            ->setAnthropicModel('custom-anthropic')
            ->setGeneralInstructions('custom general')
            ->setExecutiveSummaryInstructions('custom executive')
            ->setCategoryInstructions('custom category')
            ->setFutureCategoryInstructions('custom future')
            ->setAvoidInstructions('custom avoid')
            ->setFinalConclusionInstructions('custom final');

        $resolver = $this->resolver($setting);
        $defaults = $this->promptConfiguration()->editorialDefaults();

        $resolver->restoreEditorialDefaults($setting);

        self::assertSame('anthropic', $setting->getProvider());
        self::assertSame('custom-openai', $setting->getOpenAiModel());
        self::assertSame('custom-anthropic', $setting->getAnthropicModel());

        self::assertSame($defaults['general'], $setting->getGeneralInstructions());
        self::assertSame($defaults['executive_summary'], $setting->getExecutiveSummaryInstructions());
        self::assertSame($defaults['category'], $setting->getCategoryInstructions());
        self::assertSame($defaults['future_category'], $setting->getFutureCategoryInstructions());
        self::assertSame($defaults['avoid'], $setting->getAvoidInstructions());
        self::assertSame($defaults['final_conclusion'], $setting->getFinalConclusionInstructions());
    }


}
