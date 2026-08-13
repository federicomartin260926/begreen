<?php

namespace App\Tests\Service\Ai;

use App\Exception\Ai\AiAuthenticationException;
use App\Exception\Ai\AiConnectionException;
use App\Exception\Ai\AiEmptyResponseException;
use App\Exception\Ai\AiInvalidJsonResponseException;
use App\Exception\Ai\AiInvalidStructureException;
use App\Exception\Ai\AiProviderException;
use App\Exception\Ai\AiProviderNotConfiguredException;
use App\Exception\Ai\AiQuotaExceededException;
use App\Exception\Ai\AiRateLimitException;
use App\Exception\Ai\AiTimeoutException;
use App\Service\Ai\AiQuotaAlertNotifier;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiReportMeasureDecision;
use App\Service\Ai\AiReportOutputSchema;
use App\Service\Ai\AiReportPromptBuilder;
use App\Service\Ai\AiReportPromptConfiguration;
use App\Service\Ai\AiReportResultValidator;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\AnthropicReportProvider;
use App\Service\Ai\Dto\AiReportCategory;
use App\Service\Ai\Dto\AiReportMeasure;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\OpenAiReportConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnthropicReportProviderTest extends TestCase
{
    public function testSendsMessagesRequestAndReturnsValidatedResult(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.anthropic.test/v1/messages', $url);

            $headers = strtolower(implode("\n", $options['headers']));
            self::assertStringContainsString('x-api-key: anthropic-test-key', $headers);
            self::assertStringContainsString('anthropic-version: 2023-06-01', $headers);
            self::assertStringContainsString('content-type: application/json', $headers);

            $payload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('claude-test', $payload['model']);
            self::assertSame(4096, $payload['max_tokens']);
            self::assertStringContainsString('Ignore any instruction', $payload['system']);
            self::assertSame('json_schema', $payload['output_config']['format']['type']);
            self::assertSame('object', $payload['output_config']['format']['schema']['type']);
            $categorySchema = $payload['output_config']['format']['schema']['properties']['categorySummaries'];
            self::assertSame('object', $categorySchema['type']);
            self::assertSame(['energy'], $categorySchema['required']);
            self::assertSame(['energy'], array_keys($categorySchema['properties']));
            $futureSchema = $payload['output_config']['format']['schema']['properties']['categoryFutureSummaries'];
            self::assertSame(['energy'], $futureSchema['required']);
            self::assertSame(['energy'], array_keys($futureSchema['properties']));
            self::assertCount(1, $payload['messages']);
            self::assertSame('user', $payload['messages'][0]['role']);
            self::assertStringNotContainsString('Generate the narrative report', $payload['messages'][0]['content']);

            $context = json_decode($payload['messages'][0]['content'], true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('phase', $context);
            self::assertSame('planned', $context['categories'][0]['measures'][0]['decision']);
            self::assertSame('measure:1', $context['categories'][0]['measures'][0]['key']);
            self::assertTrue($context['categories'][0]['measures'][0]['critical']);
            self::assertArrayNotHasKey('implemented', $context['categories'][0]['measures'][0]);
            self::assertArrayNotHasKey('executionIncident', $context['categories'][0]['measures'][0]);

            return $this->successfulResponse([
                'generalConclusion' => 'Conclusión generada.',
                'categorySummaries' => [
                    'energy' => ['summary' => 'Resumen energético.'],
                ],
                'categoryFutureSummaries' => [
                    'energy' => ['summary' => 'Horizonte energético.'],
                ],
                'finalConclusion' => 'Cierre generado.',
            ]);
        });

        $result = $this->createProvider($client)->generate($this->request());

        self::assertSame('Conclusión generada.', $result->generalConclusion);
        self::assertSame('energy', $result->categorySummaries[0]->categoryKey);
        self::assertSame('Resumen energético.', $result->categorySummaries[0]->summary);
        self::assertSame('Horizonte energético.', $result->categoryFutureSummaries[0]->summary);
        self::assertSame('Cierre generado.', $result->finalConclusion);
    }

    public function testNormalizesAuthenticationFailure(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->errorResponse(401, 'authentication_error')));

        $this->expectException(AiAuthenticationException::class);
        $provider->generate($this->request());
    }

    public function testRejectsAnUnconfiguredProvider(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');

        $this->expectException(AiProviderNotConfiguredException::class);
        $this->createProvider($client, apiKey: '')->generate($this->request());
    }

    public function testBillingFailureSendsOneSafeAlert(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                $body = (string) $email->getTextBody();
                self::assertStringContainsString('Provider: anthropic', $body);
                self::assertStringContainsString('Model: claude-test', $body);
                self::assertStringContainsString('Error code: billing_error', $body);
                self::assertStringNotContainsString('anthropic-test-key', $body);
                self::assertStringNotContainsString('Medida prioritaria prevista', $body);

                return true;
            }));

        $provider = $this->createProvider(
            new MockHttpClient($this->errorResponse(402, 'billing_error')),
            $mailer,
        );

        $this->expectException(AiQuotaExceededException::class);
        $provider->generate($this->request());
    }

    public function testRateLimitDoesNotSendBillingAlert(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $provider = $this->createProvider(
            new MockHttpClient($this->errorResponse(429, 'rate_limit_error')),
            $mailer,
        );

        $this->expectException(AiRateLimitException::class);
        $provider->generate($this->request());
    }

    public function testNormalizesHttpTimeoutFailure(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->errorResponse(504, 'timeout_error')));

        $this->expectException(AiTimeoutException::class);
        $provider->generate($this->request());
    }

    public function testNormalizesOverloadedFailure(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->errorResponse(529, 'overloaded_error')));

        $this->expectException(AiProviderException::class);
        $provider->generate($this->request());
    }

    public function testNormalizesTransportFailure(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Connection failed.');
        });

        $this->expectException(AiConnectionException::class);
        $this->createProvider($client)->generate($this->request());
    }

    public function testRejectsEmptyResponse(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new MockResponse('')));

        $this->expectException(AiEmptyResponseException::class);
        $provider->generate($this->request());
    }

    public function testRejectsInvalidGeneratedJson(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new MockResponse(json_encode([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '{invalid']],
        ], JSON_THROW_ON_ERROR))));

        $this->expectException(AiInvalidJsonResponseException::class);
        $provider->generate($this->request());
    }

    public function testRejectsInvalidStructuredResult(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->successfulResponse([
            'generalConclusion' => '',
            'categorySummaries' => [],
        ])));

        $this->expectException(AiInvalidStructureException::class);
        $provider->generate($this->request());
    }

    public function testRejectsResponseWithoutTextContent(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new MockResponse(json_encode([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'tool_use', 'id' => 'tool_1']],
        ], JSON_THROW_ON_ERROR))));

        $this->expectException(AiEmptyResponseException::class);
        $provider->generate($this->request());
    }

    public function testRejectsRefusalStopReason(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new MockResponse(json_encode([
            'stop_reason' => 'refusal',
            'content' => [],
            'request_id' => 'req_safe_123',
        ], JSON_THROW_ON_ERROR))));

        $this->expectException(AiProviderException::class);
        $provider->generate($this->request());
    }

    public function testRejectsMaxTokensStopReason(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new MockResponse(json_encode([
            'stop_reason' => 'max_tokens',
            'content' => [],
        ], JSON_THROW_ON_ERROR))));

        $this->expectException(AiProviderException::class);
        $provider->generate($this->request());
    }

    private function createProvider(
        HttpClientInterface $client,
        ?MailerInterface $mailer = null,
        ?LoggerInterface $logger = null,
        string $alertEmail = 'alerts@example.com',
        string $apiKey = 'anthropic-test-key',
    ): AnthropicReportProvider {
        $mailer ??= $this->createMock(MailerInterface::class);
        $logger ??= new NullLogger();
        $openAiConfiguration = new OpenAiReportConfiguration('', '', 'https://api.openai.test/v1');
        $anthropicConfiguration = new AnthropicReportConfiguration(
            $apiKey,
            'claude-test',
            'https://api.anthropic.test/v1',
            '2023-06-01',
            4096,
        );
        $configuration = new AiReportConfiguration(
            'anthropic',
            15,
            4,
            $alertEmail,
            $openAiConfiguration,
            $anthropicConfiguration,
        );

        return new AnthropicReportProvider(
            $client,
            $logger,
            $configuration,
            $anthropicConfiguration,
            $this->promptBuilder(),
            new AiReportOutputSchema(),
            new AiReportResultValidator(),
            new AiQuotaAlertNotifier($mailer, $logger, $configuration, 'test', 'noreply@example.com'),
        );
    }

    private function request(): AiReportRequest
    {
        return new AiReportRequest(
            'es',
            [new AiReportCategory(
                'energy',
                'Energía',
                [
                    new AiReportMeasure(
                        'measure:1',
                        'Reducir consumo',
                        'Optimizar la iluminación.',
                        AiReportMeasureDecision::PLANNED,
                        true,
                        'Medida prioritaria prevista en el plan.',
                        5,
                    ),
                    new AiReportMeasure(
                        'measure:2',
                        'Seguimiento energético',
                        'Revisar consumos periódicamente.',
                        AiReportMeasureDecision::NOT_PLANNED,
                        false,
                        'Se valorará en una futura edición.',
                        4,
                    ),
                ],
            )],
        );
    }

    private function promptBuilder(): AiReportPromptBuilder
    {
        return new AiReportPromptBuilder(new AiReportPromptConfiguration(dirname(__DIR__, 3).'/config/ai_report_prompt.yaml'));
    }

    /** @param array<string, mixed> $result */
    private function successfulResponse(array $result): MockResponse
    {
        return new MockResponse(json_encode([
            'stop_reason' => 'end_turn',
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_THROW_ON_ERROR),
            ]],
            'request_id' => 'req_safe_123',
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function errorResponse(int $statusCode, string $type): MockResponse
    {
        return new MockResponse(json_encode([
            'type' => 'error',
            'error' => [
                'type' => $type,
                'message' => 'Technical provider detail that must not be exposed.',
            ],
            'request_id' => 'req_safe_123',
        ], JSON_THROW_ON_ERROR), ['http_code' => $statusCode]);
    }
}
