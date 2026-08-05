<?php

namespace App\Tests\Service\Ai;

use App\Exception\Ai\AiProviderNotConfiguredException;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiReportPhase;
use App\Service\Ai\AiReportProviderInterface;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\ConfiguredAiReportProvider;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiReportResult;
use App\Service\Ai\OpenAiReportConfiguration;
use PHPUnit\Framework\TestCase;

final class ConfiguredAiReportProviderTest extends TestCase
{
    public function testDelegatesToOpenAi(): void
    {
        $request = $this->request();
        $expected = new AiReportResult('OpenAI', []);
        $openAi = $this->createMock(AiReportProviderInterface::class);
        $openAi->expects(self::once())->method('generate')->with($request)->willReturn($expected);
        $anthropic = $this->createMock(AiReportProviderInterface::class);
        $anthropic->expects(self::never())->method('generate');

        $configuration = $this->configuration('openai');
        $provider = new ConfiguredAiReportProvider($configuration, $openAi, $anthropic);

        self::assertSame('gpt-test', $configuration->model());
        self::assertSame($expected, $provider->generate($request));
    }

    public function testDelegatesToAnthropic(): void
    {
        $request = $this->request();
        $expected = new AiReportResult('Anthropic', []);
        $openAi = $this->createMock(AiReportProviderInterface::class);
        $openAi->expects(self::never())->method('generate');
        $anthropic = $this->createMock(AiReportProviderInterface::class);
        $anthropic->expects(self::once())->method('generate')->with($request)->willReturn($expected);

        $configuration = $this->configuration('anthropic');
        $provider = new ConfiguredAiReportProvider($configuration, $openAi, $anthropic);

        self::assertSame('claude-test', $configuration->model());
        self::assertSame($expected, $provider->generate($request));
    }

    public function testRejectsUnknownProvider(): void
    {
        $openAi = $this->createMock(AiReportProviderInterface::class);
        $openAi->expects(self::never())->method('generate');
        $anthropic = $this->createMock(AiReportProviderInterface::class);
        $anthropic->expects(self::never())->method('generate');
        $provider = new ConfiguredAiReportProvider($this->configuration('unknown'), $openAi, $anthropic);

        $this->expectException(AiProviderNotConfiguredException::class);
        $provider->generate($this->request());
    }

    private function configuration(string $provider): AiReportConfiguration
    {
        return new AiReportConfiguration(
            $provider,
            15,
            4,
            '',
            new OpenAiReportConfiguration('openai-key', 'gpt-test', 'https://api.openai.test/v1'),
            new AnthropicReportConfiguration('anthropic-key', 'claude-test', 'https://api.anthropic.test/v1', '2023-06-01', 4096),
        );
    }

    private function request(): AiReportRequest
    {
        return new AiReportRequest(AiReportPhase::ELABORATION, 'es', []);
    }
}
