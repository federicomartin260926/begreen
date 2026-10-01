<?php

namespace App\Tests\Service\CrewImport;

use App\Exception\Ai\AiInvalidJsonResponseException;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\OpenAiReportConfiguration;
use App\Service\CrewImport\ConfiguredCrewImportAiProvider;
use App\Service\CrewImport\CrewImportAiConfigurationResolver;
use App\Service\CrewImport\CrewImportAiOutputSchema;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConfiguredCrewImportAiProviderTest extends TestCase
{
    public function testCrewSchemaContainsOnlyPortableConstraintsAndNoAiWarnings(): void
    {
        $schema = (new CrewImportAiOutputSchema())->get();
        $json = json_encode($schema, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('maxLength', $json);
        self::assertStringNotContainsString('maxItems', $json);
        self::assertStringNotContainsString('minimum', $json);
        self::assertStringNotContainsString('"warnings"', $json);
        self::assertStringContainsString('additionalProperties', $json);
    }

    public function testOpenAiUsesStrictSchemaAndDisablesStorage(): void
    {
        $result = ['rows' => []];
        $provider = $this->provider('openai', static function (string $method, string $url, array $options) use ($result): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/responses', $url);
            $payload = json_decode($options['body'], true, 64, JSON_THROW_ON_ERROR);
            self::assertFalse($payload['store']);
            self::assertSame('json_schema', $payload['text']['format']['type']);
            self::assertTrue($payload['text']['format']['strict']);

            return new MockResponse(json_encode([
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        self::assertSame($result, $provider->request('instructions', 'synthetic context', $this->schema()));
    }

    public function testAnthropicUsesConfiguredStructuredOutput(): void
    {
        $result = ['rows' => []];
        $provider = $this->provider('anthropic', static function (string $method, string $url, array $options) use ($result): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/messages', $url);
            $payload = json_decode($options['body'], true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('json_schema', $payload['output_config']['format']['type']);
            self::assertSame('crew-anthropic', $payload['model']);

            return new MockResponse(json_encode([
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ], JSON_THROW_ON_ERROR));
        });

        self::assertSame($result, $provider->request('instructions', 'synthetic context', $this->schema()));
    }

    public function testInvalidProviderOutputJsonIsControlled(): void
    {
        $provider = $this->provider('openai', static fn (): MockResponse => new MockResponse(json_encode([
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => '{invalid']],
            ]],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(AiInvalidJsonResponseException::class);
        $provider->request('instructions', 'synthetic context', $this->schema());
    }

    private function provider(string $selectedProvider, \Closure $responseFactory): ConfiguredCrewImportAiProvider
    {
        $openAi = new OpenAiReportConfiguration('openai-test-key', 'crew-openai', 'https://openai.test/v1');
        $anthropic = new AnthropicReportConfiguration(
            'anthropic-test-key',
            'crew-anthropic',
            'https://anthropic.test/v1',
            '2023-06-01',
            2048,
        );
        $configuration = new AiReportConfiguration($selectedProvider, 10, 4, '', $openAi, $anthropic);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);
        $resolver = new CrewImportAiConfigurationResolver($entityManager, $configuration);

        return new ConfiguredCrewImportAiProvider(
            new MockHttpClient($responseFactory),
            $configuration,
            $openAi,
            $anthropic,
            $resolver,
        );
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['rows'],
            'properties' => ['rows' => ['type' => 'array', 'items' => ['type' => 'object']]],
        ];
    }
}
