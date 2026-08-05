<?php

namespace App\Service\Ai;

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
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\Ai\Dto\AiReportResult;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnthropicReportProvider implements AiReportProviderInterface
{
    private const PROVIDER = 'anthropic';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly AiReportConfiguration $configuration,
        private readonly AnthropicReportConfiguration $anthropicConfiguration,
        private readonly AiReportPromptBuilder $promptBuilder,
        private readonly AiReportOutputSchema $outputSchema,
        private readonly AiReportResultValidator $resultValidator,
        private readonly AiQuotaAlertNotifier $quotaAlertNotifier,
    ) {
    }

    public function generate(AiReportRequest $request): AiReportResult
    {
        $this->assertConfigured();

        try {
            $context = $this->promptBuilder->buildContext($request);
        } catch (\JsonException) {
            $this->logFailure('request_encoding');

            throw new AiProviderException('The AI report request could not be prepared safely.');
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint(), [
                'headers' => [
                    'x-api-key' => $this->anthropicConfiguration->apiKey,
                    'anthropic-version' => $this->anthropicConfiguration->apiVersion,
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->anthropicConfiguration->model,
                    'max_tokens' => $this->anthropicConfiguration->maxTokens,
                    'system' => $this->promptBuilder->buildInstructions($request->phase),
                    'messages' => [[
                        'role' => 'user',
                        'content' => $context,
                    ]],
                    'output_config' => [
                        'format' => [
                            'type' => 'json_schema',
                            'schema' => $this->outputSchema->get(),
                        ],
                    ],
                ],
                'timeout' => $this->configuration->timeoutSeconds,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TimeoutExceptionInterface) {
            $this->logFailure('timeout');

            throw new AiTimeoutException('The AI provider request timed out.');
        } catch (TransportExceptionInterface) {
            $this->logFailure('connection');

            throw new AiConnectionException('The AI provider could not be reached.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->throwHttpException($statusCode, $body);
        }

        if (trim($body) === '') {
            $this->logFailure('empty_response', $statusCode);

            throw new AiEmptyResponseException('The AI provider returned an empty response.');
        }

        try {
            $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logFailure('invalid_json', $statusCode);

            throw new AiInvalidJsonResponseException('The AI provider returned invalid JSON.');
        }

        if (!is_array($envelope)) {
            $this->logFailure('invalid_structure', $statusCode);

            throw new AiInvalidStructureException('The AI provider response has an invalid structure.');
        }

        $requestId = $this->normalizeRequestId($envelope['request_id'] ?? null);
        if (($envelope['stop_reason'] ?? null) === 'refusal') {
            $this->logFailure('refusal', $statusCode, 'refusal', $requestId);

            throw new AiProviderException('The AI provider refused the response.');
        }

        if (($envelope['stop_reason'] ?? null) === 'max_tokens') {
            $this->logFailure('incomplete_response', $statusCode, 'max_tokens', $requestId);

            throw new AiProviderException('The AI provider did not complete the response.');
        }

        $outputText = $this->extractOutputText($envelope);
        if ($outputText === '') {
            $this->logFailure('empty_response', $statusCode, '', $requestId);

            throw new AiEmptyResponseException('The AI provider returned no generated content.');
        }

        try {
            $data = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logFailure('invalid_output_json', $statusCode, '', $requestId);

            throw new AiInvalidJsonResponseException('The AI provider generated invalid JSON.');
        }

        try {
            return $this->resultValidator->validate($data);
        } catch (AiInvalidStructureException $exception) {
            $this->logFailure('invalid_structure', $statusCode, '', $requestId);

            throw $exception;
        }
    }

    private function assertConfigured(): void
    {
        if (
            trim($this->anthropicConfiguration->apiKey) === ''
            || trim($this->anthropicConfiguration->model) === ''
            || trim($this->anthropicConfiguration->baseUrl) === ''
            || trim($this->anthropicConfiguration->apiVersion) === ''
            || $this->anthropicConfiguration->maxTokens <= 0
            || $this->configuration->timeoutSeconds <= 0
        ) {
            $this->logFailure('provider_not_configured');

            throw new AiProviderNotConfiguredException('The AI report provider is not configured.');
        }
    }

    private function endpoint(): string
    {
        return rtrim($this->anthropicConfiguration->baseUrl, '/').'/messages';
    }

    /** @param array<string, mixed> $envelope */
    private function extractOutputText(array $envelope): string
    {
        $content = $envelope['content'] ?? null;
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'text'
                && is_string($block['text'] ?? null)
            ) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode('', $parts));
    }

    private function throwHttpException(int $statusCode, string $body): never
    {
        [$errorCode, $requestId] = $this->extractSafeErrorMetadata($body);

        if ($statusCode === 401) {
            $this->logFailure('authentication', $statusCode, $errorCode, $requestId);

            throw new AiAuthenticationException('The AI provider rejected authentication.');
        }

        if ($statusCode === 402) {
            $normalizedCode = $errorCode !== '' ? $errorCode : 'billing_error';
            $this->logFailure('quota', $statusCode, $normalizedCode, $requestId);
            $this->quotaAlertNotifier->notify(self::PROVIDER, $this->anthropicConfiguration->model, $normalizedCode);

            throw new AiQuotaExceededException('The AI provider has no available credit or quota.');
        }

        if ($statusCode === 429) {
            $this->logFailure('rate_limit', $statusCode, $errorCode, $requestId);

            throw new AiRateLimitException('The AI provider rate limit was reached.');
        }

        if ($statusCode === 504) {
            $this->logFailure('timeout', $statusCode, $errorCode, $requestId);

            throw new AiTimeoutException('The AI provider request timed out.');
        }

        $this->logFailure('provider_error', $statusCode, $errorCode, $requestId);

        throw new AiProviderException('The AI provider returned an error.');
    }

    /** @return array{string, string|null} */
    private function extractSafeErrorMetadata(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['', null];
        }

        $errorCode = is_array($data['error'] ?? null) && is_string($data['error']['type'] ?? null)
            ? strtolower(trim($data['error']['type']))
            : '';

        return [$errorCode, $this->normalizeRequestId($data['request_id'] ?? null)];
    }

    private function normalizeRequestId(mixed $requestId): ?string
    {
        if (!is_string($requestId) || strlen($requestId) > 128 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $requestId)) {
            return null;
        }

        return $requestId;
    }

    private function logFailure(
        string $type,
        ?int $statusCode = null,
        string $errorCode = '',
        ?string $requestId = null,
    ): void {
        $this->logger->error('AI report provider failure.', [
            'provider' => self::PROVIDER,
            'model' => $this->anthropicConfiguration->model,
            'error_type' => $type,
            'error_code' => $errorCode !== '' ? $errorCode : null,
            'status_code' => $statusCode,
            'request_id' => $requestId,
        ]);
    }
}
