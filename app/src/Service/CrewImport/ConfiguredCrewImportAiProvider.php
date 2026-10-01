<?php

namespace App\Service\CrewImport;

use App\Exception\Ai\AiConnectionException;
use App\Exception\Ai\AiInvalidJsonResponseException;
use App\Exception\Ai\AiInvalidStructureException;
use App\Exception\Ai\AiProviderException;
use App\Exception\Ai\AiProviderNotConfiguredException;
use App\Exception\Ai\AiTimeoutException;
use App\Service\Ai\AiReportConfiguration;
use App\Service\Ai\AnthropicReportConfiguration;
use App\Service\Ai\OpenAiReportConfiguration;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ConfiguredCrewImportAiProvider implements CrewImportAiProviderInterface
{
    private const MAX_RESPONSE_BYTES = 1_000_000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private AiReportConfiguration $configuration,
        private OpenAiReportConfiguration $openAi,
        private AnthropicReportConfiguration $anthropic,
        private CrewImportAiConfigurationResolver $configurationResolver,
    ) {
    }

    public function request(string $instructions, string $context, array $schema): array
    {
        $settings = $this->configurationResolver->resolve();
        [$url, $headers, $payload] = match ($settings['provider']) {
            'openai' => $this->openAiRequest($settings['model'], $instructions, $context, $schema),
            'anthropic' => $this->anthropicRequest($settings['model'], $instructions, $context, $schema),
            default => throw new AiProviderNotConfiguredException('The Crew AI provider is not configured.'),
        };

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $this->configuration->timeoutSeconds,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TimeoutExceptionInterface) {
            throw new AiTimeoutException('The Crew AI request timed out.');
        } catch (TransportExceptionInterface) {
            throw new AiConnectionException('The Crew AI provider could not be reached.');
        }

        if ($status < 200 || $status >= 300) {
            throw new AiProviderException('The Crew AI provider returned an error.');
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new AiInvalidStructureException('The Crew AI provider response exceeds its size limit.');
        }
        try {
            $envelope = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AiInvalidJsonResponseException('The Crew AI provider returned invalid JSON.', previous: $exception);
        }
        if (!is_array($envelope)) {
            throw new AiInvalidStructureException('The Crew AI provider returned an invalid response.');
        }

        $text = $settings['provider'] === 'openai'
            ? $this->openAiText($envelope)
            : $this->anthropicText($envelope);
        if ($text === '') {
            throw new AiInvalidStructureException('The Crew AI provider returned no structured content.');
        }
        try {
            $result = json_decode($text, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AiInvalidJsonResponseException('The Crew AI provider generated invalid JSON.', previous: $exception);
        }
        if (!is_array($result)) {
            throw new AiInvalidStructureException('The Crew AI output is invalid.');
        }

        return $result;
    }

    /** @return array{string, array<string, string>, array<string, mixed>} */
    private function openAiRequest(string $model, string $instructions, string $context, array $schema): array
    {
        if (trim($this->openAi->apiKey) === '' || trim($model) === '') {
            throw new AiProviderNotConfiguredException('OpenAI is not configured for Crew import.');
        }

        return [
            rtrim($this->openAi->baseUrl, '/').'/responses',
            ['Authorization' => 'Bearer '.$this->openAi->apiKey, 'Content-Type' => 'application/json'],
            [
                'model' => trim($model),
                'store' => false,
                'instructions' => $instructions,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $context]]]],
                'text' => ['format' => [
                    'type' => 'json_schema',
                    'name' => 'crew_import',
                    'strict' => true,
                    'schema' => $schema,
                ]],
            ],
        ];
    }

    /** @return array{string, array<string, string>, array<string, mixed>} */
    private function anthropicRequest(string $model, string $instructions, string $context, array $schema): array
    {
        if (trim($this->anthropic->apiKey) === '' || trim($model) === '') {
            throw new AiProviderNotConfiguredException('Anthropic is not configured for Crew import.');
        }

        return [
            rtrim($this->anthropic->baseUrl, '/').'/messages',
            [
                'x-api-key' => $this->anthropic->apiKey,
                'anthropic-version' => $this->anthropic->apiVersion,
                'content-type' => 'application/json',
            ],
            [
                'model' => trim($model),
                'max_tokens' => $this->anthropic->maxTokens,
                'system' => $instructions,
                'messages' => [['role' => 'user', 'content' => $context]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            ],
        ];
    }

    /** @param array<string, mixed> $envelope */
    private function openAiText(array $envelope): string
    {
        $parts = [];
        foreach (($envelope['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $parts[] = $content['text'];
                }
            }
        }

        return trim(implode('', $parts));
    }

    /** @param array<string, mixed> $envelope */
    private function anthropicText(array $envelope): string
    {
        $parts = [];
        foreach (($envelope['content'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? null) === 'text' && is_string($content['text'] ?? null)) {
                $parts[] = $content['text'];
            }
        }

        return trim(implode('', $parts));
    }
}
