<?php

namespace App\Service\Ai\Dto;

final readonly class AiStoredReport
{
    public const VERSION = 1;

    /** @param list<array{categoryKey:string, summary:string}> $categorySummaries */
    public function __construct(
        public int $version,
        public int $planId,
        public string $locale,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public string $contextHash,
        public string $generatedAt,
        public string $generalConclusion,
        public array $categorySummaries,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'planId' => $this->planId,
            'locale' => $this->locale,
            'provider' => $this->provider,
            'model' => $this->model,
            'promptVersion' => $this->promptVersion,
            'contextHash' => $this->contextHash,
            'generatedAt' => $this->generatedAt,
            'generalConclusion' => $this->generalConclusion,
            'categorySummaries' => $this->categorySummaries,
        ];
    }

    /** @return array{generalConclusion:string, categorySummaries:list<array{categoryKey:string, summary:string}>} */
    public function resultData(): array
    {
        return [
            'generalConclusion' => $this->generalConclusion,
            'categorySummaries' => $this->categorySummaries,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $expectedKeys = [
            'version',
            'planId',
            'locale',
            'provider',
            'model',
            'promptVersion',
            'contextHash',
            'generatedAt',
            'generalConclusion',
            'categorySummaries',
        ];
        if (!self::hasExactKeys($data, $expectedKeys)) {
            return null;
        }

        if (
            !is_int($data['version'])
            || !is_int($data['planId'])
            || $data['planId'] <= 0
            || !is_string($data['locale'])
            || !in_array($data['locale'], ['es', 'en'], true)
            || !self::isNonEmptyString($data['provider'])
            || !self::isNonEmptyString($data['model'])
            || !self::isNonEmptyString($data['promptVersion'])
            || !is_string($data['contextHash'])
            || preg_match('/^[a-f0-9]{64}$/D', $data['contextHash']) !== 1
            || !self::isValidDate($data['generatedAt'])
            || !self::isNonEmptyString($data['generalConclusion'])
            || !is_array($data['categorySummaries'])
        ) {
            return null;
        }

        $summaries = [];
        foreach ($data['categorySummaries'] as $summary) {
            if (
                !is_array($summary)
                || !self::hasExactKeys($summary, ['categoryKey', 'summary'])
                || !self::isNonEmptyString($summary['categoryKey'] ?? null)
                || !self::isNonEmptyString($summary['summary'] ?? null)
            ) {
                return null;
            }

            $summaries[] = [
                'categoryKey' => trim($summary['categoryKey']),
                'summary' => trim($summary['summary']),
            ];
        }

        return new self(
            $data['version'],
            $data['planId'],
            $data['locale'],
            trim($data['provider']),
            trim($data['model']),
            trim($data['promptVersion']),
            $data['contextHash'],
            $data['generatedAt'],
            trim($data['generalConclusion']),
            $summaries,
        );
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $expectedKeys
     */
    private static function hasExactKeys(array $data, array $expectedKeys): bool
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }

    private static function isValidDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);

        return $date instanceof \DateTimeImmutable && $date->format(\DateTimeInterface::ATOM) === $value;
    }
}
