<?php

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiProviderAvailability;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\OpenAiReportConfiguration;
use PHPUnit\Framework\TestCase;

final class AiProviderAvailabilityTest extends TestCase
{
    public function testMissingFallbackOrBlankApiKeysAreUnavailable(): void
    {
        $availability = new AiProviderAvailability(
            new OpenAiReportConfiguration('', 'gpt-test', 'https://openai.test'),
            new AnthropicReportConfiguration('  ', 'claude-test', 'https://anthropic.test', '2023-06-01', 4096),
        );

        self::assertFalse($availability->isAvailable('openai'));
        self::assertFalse($availability->isAvailable('anthropic'));
        self::assertFalse($availability->isAvailable('unsupported'));
    }

    public function testProvidersWithApiKeysAreAvailable(): void
    {
        $availability = new AiProviderAvailability(
            new OpenAiReportConfiguration('openai-key', 'gpt-test', 'https://openai.test'),
            new AnthropicReportConfiguration('anthropic-key', 'claude-test', 'https://anthropic.test', '2023-06-01', 4096),
        );

        self::assertSame(['openai' => true, 'anthropic' => true], $availability->all());
    }

    public function testMissingKeyForOneProviderDoesNotAffectTheOther(): void
    {
        $availability = new AiProviderAvailability(
            new OpenAiReportConfiguration('', 'gpt-test', 'https://openai.test'),
            new AnthropicReportConfiguration('anthropic-key', 'claude-test', 'https://anthropic.test', '2023-06-01', 4096),
        );

        self::assertSame(['openai' => false, 'anthropic' => true], $availability->all());
    }
}
