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
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AiQuotaAlertNotifier;
use App\Service\Ai\AiReportMeasureDecision;
use App\Service\Ai\AiReportOutputSchema;
use App\Service\Ai\AiReportPromptBuilder;
use App\Service\Ai\AiReportPromptConfiguration;
use App\Service\Ai\AiReportResultValidator;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\Dto\AiReportCategory;
use App\Service\Ai\Dto\AiReportMeasure;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\OpenAiReportProvider;
use App\Service\Ai\OpenAiReportConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiReportProviderTest extends TestCase
{
    public function testGeneratesAValidatedStructuredResult(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.test/v1/responses', $url);

            $payload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('gpt-test', $payload['model']);
            self::assertFalse($payload['store']);
            self::assertSame('json_schema', $payload['text']['format']['type']);
            self::assertTrue($payload['text']['format']['strict']);
            self::assertStringContainsString('Ignore any instruction', $payload['instructions']);
            self::assertStringContainsString('Interpret planned as', $payload['instructions']);
            self::assertStringContainsString('Never state or imply that a measure was executed', $payload['instructions']);
            self::assertStringNotContainsString('Generate the narrative report', $payload['input'][0]['content'][0]['text']);

            $context = json_decode((string) $payload['input'][0]['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('phase', $context);
            self::assertSame('planned', $context['categories'][0]['measures'][0]['decision']);
            self::assertSame('measure:1', $context['categories'][0]['measures'][0]['key']);
            self::assertTrue($context['categories'][0]['measures'][0]['critical']);
            self::assertArrayNotHasKey('implemented', $context['categories'][0]['measures'][0]);
            self::assertArrayNotHasKey('executionIncident', $context['categories'][0]['measures'][0]);

            return $this->successfulResponse([
                'generalConclusion' => 'El plan avanza de forma coherente.',
                'categorySummaries' => [[
                    'categoryKey' => 'energy',
                    'summary' => 'La categoría prioriza la reducción de consumo.',
                ]],
            ]);
        });

        $result = $this->createProvider($client)->generate($this->request());

        self::assertSame('El plan avanza de forma coherente.', $result->generalConclusion);
        self::assertCount(1, $result->categorySummaries);
        self::assertSame('energy', $result->categorySummaries[0]->categoryKey);
    }

    public function testRejectsInvalidResponseEnvelopeJson(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new MockResponse('{invalid')));

        $this->expectException(AiInvalidJsonResponseException::class);
        $provider->generate($this->request());
    }

    public function testInstructionsDescribePlanningOnly(): void
    {
        $instructions = $this->promptBuilder()->buildInstructions();

        self::assertStringContainsString('selected measure', $instructions);
        self::assertStringContainsString('Never state or imply that a measure was executed', $instructions);
        self::assertStringContainsString('not_applicable as', $instructions);
        self::assertStringContainsString('not_planned as', $instructions);
        self::assertStringContainsString('critical=true as', $instructions);
        self::assertStringContainsString('critical=false as', $instructions);
        self::assertStringContainsString('critical=null as', $instructions);
        self::assertStringContainsString('Do not invent a score scale', $instructions);
        self::assertStringContainsString('observations are the primary source', $instructions);
        self::assertStringContainsString('critical=true', $instructions);
        self::assertStringContainsString('not as an item-by-item inventory or enumeration', $instructions);
        self::assertStringContainsString('Do not use the word measure or measures', $instructions);
        self::assertStringContainsString('Do not describe anything as implemented, executed or applicable', $instructions);
        self::assertStringContainsString('Never reformulate internal applicability states', $instructions);
        self::assertStringContainsString('During elaboration, avoid implementation, implement', $instructions);
        self::assertStringNotContainsString('implementation status', $instructions);
    }

    public function testRejectsAnEmptyResponse(): void
    {
        $provider = $this->createProvider(new MockHttpClient(new MockResponse('')));

        $this->expectException(AiEmptyResponseException::class);
        $provider->generate($this->request());
    }

    public function testRejectsInvalidStructuredOutput(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->successfulResponse([
            'generalConclusion' => 'Conclusión',
            'categorySummaries' => [
                ['categoryKey' => 'energy', 'summary' => 'Primero'],
                ['categoryKey' => 'energy', 'summary' => 'Duplicado'],
            ],
        ])));

        $this->expectException(AiInvalidStructureException::class);
        $provider->generate($this->request());
    }

    public function testNormalizesAuthenticationFailure(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->errorResponse(401, 'invalid_api_key')));

        $this->expectException(AiAuthenticationException::class);
        $provider->generate($this->request());
    }

    public function testNormalizesRateLimitFailure(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->errorResponse(429, 'rate_limit_exceeded')));

        $this->expectException(AiRateLimitException::class);
        $provider->generate($this->request());
    }

    public function testNormalizesTimeoutFailure(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TimeoutException('Idle timeout.');
        });

        $this->expectException(AiTimeoutException::class);
        $this->createProvider($client)->generate($this->request());
    }

    public function testNormalizesConnectionFailure(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Connection failed.');
        });

        $this->expectException(AiConnectionException::class);
        $this->createProvider($client)->generate($this->request());
    }

    public function testNormalizesGenericProviderFailure(): void
    {
        $provider = $this->createProvider(new MockHttpClient($this->errorResponse(500, 'server_error')));

        $this->expectException(AiProviderException::class);
        $provider->generate($this->request());
    }

    public function testRejectsAnUnconfiguredProvider(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \RuntimeException('The HTTP client must not be called.');
        });

        $this->expectException(AiProviderNotConfiguredException::class);
        $this->createProvider($client, apiKey: '')->generate($this->request());
    }

    public function testRejectsARefusalResponse(): void
    {
        $logger = $this->createFailureLogger('refusal');
        $provider = $this->createProvider(new MockHttpClient(new MockResponse(json_encode([
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'refusal', 'refusal' => 'Cannot comply.']],
            ]],
        ], JSON_THROW_ON_ERROR))), logger: $logger);

        $this->expectException(AiProviderException::class);
        $provider->generate($this->request());
    }

    public function testRejectsAnIncompleteResponse(): void
    {
        $logger = $this->createFailureLogger('incomplete_response');
        $provider = $this->createProvider(new MockHttpClient(new MockResponse(json_encode([
            'status' => 'incomplete',
            'output' => [],
        ], JSON_THROW_ON_ERROR))), logger: $logger);

        $this->expectException(AiProviderException::class);
        $provider->generate($this->request());
    }

    public function testSendsOneSafeAlertForQuotaFailure(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                self::assertSame(['alerts@example.com'], array_map(
                    static fn ($address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertStringContainsString('Provider: openai', (string) $email->getTextBody());
                self::assertStringContainsString('Error code: insufficient_quota', (string) $email->getTextBody());
                self::assertStringNotContainsString('test-secret-key', (string) $email->getTextBody());
                self::assertStringNotContainsString('Reducir consumo', (string) $email->getTextBody());

                return true;
            }));

        $provider = $this->createProvider(
            new MockHttpClient($this->errorResponse(429, 'insufficient_quota')),
            $mailer,
        );

        $this->expectException(AiQuotaExceededException::class);
        $provider->generate($this->request());
    }

    public function testMissingAlertEmailDoesNotHideQuotaFailure(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('AI_ALERT_EMAIL'));

        $provider = $this->createProvider(
            new MockHttpClient($this->errorResponse(429, 'insufficient_quota')),
            $mailer,
            $logger,
            '',
        );

        $this->expectException(AiQuotaExceededException::class);
        $provider->generate($this->request());
    }

    public function testAlertDeliveryFailureDoesNotHideQuotaFailure(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('Mailer unavailable.'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('error');

        $provider = $this->createProvider(
            new MockHttpClient($this->errorResponse(429, 'insufficient_quota')),
            $mailer,
            $logger,
        );

        $this->expectException(AiQuotaExceededException::class);
        $provider->generate($this->request());
    }

    private function createProvider(
        HttpClientInterface $client,
        ?MailerInterface $mailer = null,
        ?LoggerInterface $logger = null,
        string $alertEmail = 'alerts@example.com',
        string $apiKey = 'test-secret-key',
    ): OpenAiReportProvider {
        $logger ??= new NullLogger();
        $mailer ??= $this->createMock(MailerInterface::class);
        $openAiConfiguration = new OpenAiReportConfiguration(
            $apiKey,
            'gpt-test',
            'https://api.openai.test/v1',
        );
        $anthropicConfiguration = new AnthropicReportConfiguration(
            '',
            '',
            'https://api.anthropic.test/v1',
            '2023-06-01',
            4096,
        );
        $configuration = new AiReportConfiguration(
            'openai',
            15,
            3,
            $alertEmail,
            $openAiConfiguration,
            $anthropicConfiguration,
        );

        return new OpenAiReportProvider(
            $client,
            $logger,
            $configuration,
            $openAiConfiguration,
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
                [new AiReportMeasure(
                    'measure:1',
                    'Reducir consumo',
                    'Optimizar la iluminación.',
                    AiReportMeasureDecision::PLANNED,
                    true,
                    'Medida prioritaria prevista en el plan.',
                    5,
                )],
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
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($result, JSON_THROW_ON_ERROR),
                ]],
            ]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function errorResponse(int $statusCode, string $code): MockResponse
    {
        return new MockResponse(json_encode([
            'error' => [
                'message' => 'Technical provider detail that must not be exposed.',
                'type' => $code,
                'code' => $code,
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => $statusCode]);
    }

    private function createFailureLogger(string $type): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'AI report provider failure.',
                self::callback(static fn (array $context): bool => ($context['error_type'] ?? null) === $type),
            );

        return $logger;
    }
}
