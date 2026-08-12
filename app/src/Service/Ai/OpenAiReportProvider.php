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

final class OpenAiReportProvider implements AiReportProviderInterface
{
    private const PROVIDER = 'openai';

    /** @var list<string> */
    private const QUOTA_ERROR_CODES = [
        'billing_hard_limit_reached',
        'billing_not_active',
        'insufficient_quota',
        'quota_exceeded',
        'usage_limit_reached',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly AiReportConfiguration $configuration,
        private readonly OpenAiReportConfiguration $openAiConfiguration,
        private readonly AiReportPromptBuilder $promptBuilder,
        private readonly AiReportOutputSchema $outputSchema,
        private readonly AiReportResultValidator $resultValidator,
        private readonly AiQuotaAlertNotifier $quotaAlertNotifier,
        private readonly ?AiReportSettingResolver $settingResolver = null,
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
                    'Authorization' => 'Bearer '.$this->openAiConfiguration->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->buildPayload($context, $this->categoryKeys($request)),
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

        if (($envelope['status'] ?? null) === 'incomplete') {
            $this->logFailure('incomplete_response', $statusCode);

            throw new AiProviderException('The AI provider did not complete the response.');
        }

        if ($this->hasRefusal($envelope)) {
            $this->logFailure('refusal', $statusCode);

            throw new AiProviderException('The AI provider refused the response.');
        }

        $outputText = $this->extractOutputText($envelope);
        if ($outputText === '') {
            $this->logFailure('empty_response', $statusCode);

            throw new AiEmptyResponseException('The AI provider returned no generated content.');
        }

        try {
            $data = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logFailure('invalid_output_json', $statusCode);

            throw new AiInvalidJsonResponseException('The AI provider generated invalid JSON.');
        }

        try {
            return $this->resultValidator->validate(
                $this->outputSchema->toValidatorData($data),
                $this->categoryKeys($request),
            );
        } catch (AiInvalidStructureException $exception) {
            $this->logFailure('invalid_structure', $statusCode);

            throw $exception;
        }
    }

    private function assertConfigured(): void
    {
        if (
            trim($this->openAiConfiguration->apiKey) === ''
            || $this->model() === ''
            || trim($this->openAiConfiguration->baseUrl) === ''
            || $this->configuration->timeoutSeconds <= 0
        ) {
            $this->logFailure('provider_not_configured');

            throw new AiProviderNotConfiguredException('The AI report provider is not configured.');
        }
    }

    /** @return array<string, mixed> */
    /** @param list<string> $expectedCategoryKeys */
    private function buildPayload(string $context, array $expectedCategoryKeys): array
    {
        return [
            'model' => $this->model(),
            'store' => false,
            'instructions' => $this->promptBuilder->buildInstructions(),
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $context,
                ]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'sustainability_plan_report',
                    'strict' => true,
                    'schema' => $this->outputSchema->get($expectedCategoryKeys),
                ],
            ],
        ];
    }

    private function endpoint(): string
    {
        return rtrim($this->openAiConfiguration->baseUrl, '/').'/responses';
    }

    /** @return list<string> */
    private function categoryKeys(AiReportRequest $request): array
    {
        return array_map(static fn ($category): string => $category->key, $request->categories);
    }

    /** @param array<string, mixed> $envelope */
    private function extractOutputText(array $envelope): string
    {
        $output = $envelope['output'] ?? null;
        if (!is_array($output)) {
            return '';
        }

        $parts = [];
        foreach ($output as $outputItem) {
            if (!is_array($outputItem) || ($outputItem['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $outputItem['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (
                    is_array($contentItem)
                    && ($contentItem['type'] ?? null) === 'output_text'
                    && is_string($contentItem['text'] ?? null)
                ) {
                    $parts[] = $contentItem['text'];
                }
            }
        }

        return trim(implode('', $parts));
    }

    /** @param array<string, mixed> $envelope */
    private function hasRefusal(array $envelope): bool
    {
        if (($envelope['type'] ?? null) === 'refusal') {
            return true;
        }

        $output = $envelope['output'] ?? [];
        if (!is_array($output)) {
            return false;
        }

        foreach ($output as $outputItem) {
            if (!is_array($outputItem)) {
                continue;
            }

            if (($outputItem['type'] ?? null) === 'refusal') {
                return true;
            }

            $content = $outputItem['content'] ?? [];
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (is_array($contentItem) && ($contentItem['type'] ?? null) === 'refusal') {
                    return true;
                }
            }
        }

        return false;
    }

    private function throwHttpException(int $statusCode, string $body): never
    {
        $errorCode = $this->extractErrorCode($body);

        if ($statusCode === 401) {
            $this->logFailure('authentication', $statusCode, $errorCode);

            throw new AiAuthenticationException('The AI provider rejected authentication.');
        }

        if (in_array($errorCode, self::QUOTA_ERROR_CODES, true)) {
            $normalizedCode = $errorCode !== '' ? $errorCode : 'quota_exceeded';
            $this->logFailure('quota', $statusCode, $normalizedCode);
            $this->quotaAlertNotifier->notify(self::PROVIDER, $this->model(), $normalizedCode);

            throw new AiQuotaExceededException('The AI provider has no available credit or quota.');
        }

        if ($statusCode === 429) {
            $this->logFailure('rate_limit', $statusCode, $errorCode);

            throw new AiRateLimitException('The AI provider rate limit was reached.');
        }

        $this->logFailure('provider_error', $statusCode, $errorCode);

        throw new AiProviderException('The AI provider returned an error.');
    }

    private function extractErrorCode(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['error'] ?? null)) {
            return '';
        }

        $code = $data['error']['code'] ?? $data['error']['type'] ?? '';

        return is_string($code) ? strtolower(trim($code)) : '';
    }

    private function logFailure(string $type, ?int $statusCode = null, string $errorCode = ''): void
    {
        $this->logger->error('AI report provider failure.', $this->logContext($type, $statusCode, $errorCode));
    }

    /** @return array<string, int|string|null> */
    private function logContext(string $type, ?int $statusCode, string $errorCode): array
    {
        return [
            'provider' => self::PROVIDER,
            'model' => $this->model(),
            'error_type' => $type,
            'error_code' => $errorCode !== '' ? $errorCode : null,
            'status_code' => $statusCode,
        ];
    }

    private function model(): string
    {
        return $this->settingResolver?->resolve()->openAiModel
            ?? trim($this->openAiConfiguration->model);
    }
}
