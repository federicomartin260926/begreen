<?php

declare(strict_types=1);

namespace App\Service\Emission;

final class EmissionTraceabilityPresenter
{
    /**
     * @param array<string, mixed> $snapshot
     * @return list<array<string, mixed>>
     */
    public function extract(array $snapshot): array
    {
        $calculation = is_array($snapshot['calculation'] ?? null) ? $snapshot['calculation'] : [];
        $rawTraces = [];
        foreach (['factorTraces', 'avoidedFactorTraces'] as $key) {
            if (is_array($calculation[$key] ?? null)) {
                $rawTraces = [...$rawTraces, ...$calculation[$key]];
            }
        }
        if ([] === $rawTraces && is_array($snapshot['factor'] ?? null)) {
            $rawTraces[] = $snapshot['factor'];
        } elseif ([] === $rawTraces && $this->hasFactorEvidence($calculation)) {
            $rawTraces[] = $calculation;
        }

        $technicalVersions = array_filter([
            is_string($snapshot['version'] ?? null) ? $snapshot['version'] : null,
            is_string($snapshot['calculatorVersion'] ?? null) ? $snapshot['calculatorVersion'] : null,
        ]);
        $result = [];
        foreach ($rawTraces as $trace) {
            if (!is_array($trace) || !$this->hasFactorEvidence($trace)) {
                continue;
            }
            $metadata = is_array($trace['metadata'] ?? null) ? $trace['metadata'] : [];
            $factorVersion = $this->string($trace, $metadata, ['factorVersion']);
            if (null !== $factorVersion && in_array($factorVersion, $technicalVersions, true)) {
                $factorVersion = null;
            }

            $result[] = [
                'component' => $this->string($trace, $metadata, ['component']),
                'factorId' => $this->string($trace, $metadata, ['factorId', 'factor_id']),
                'factorActivityYear' => $this->integer($trace, $metadata, ['factorActivityYear', 'activityYear']),
                'factorYear' => $this->integer($trace, $metadata, ['factorYear']),
                'factorVersion' => $factorVersion,
                'sourceEdition' => $this->string($trace, $metadata, ['sourceEdition']),
                'temporalType' => $this->string($trace, $metadata, ['temporalType']),
                'factorValue' => $this->scalar($trace, $metadata, ['factorValue', 'effectiveFactorValue', 'value']),
                'factorUnit' => $this->string($trace, $metadata, ['factorUnit', 'effectiveFactorUnit', 'unit']),
                'source' => $this->string($trace, $metadata, ['source']),
                'sourceDetail' => $this->string($trace, $metadata, ['sourceDetail']),
                'isFallback' => $this->boolean($trace, $metadata, ['fallback', 'isFallback', 'isTemporalFallback']),
                'fallbackReason' => $this->string($trace, $metadata, ['fallbackReason']),
                'isGeographicProxy' => $this->boolean($trace, $metadata, ['geographicProxy', 'isGeographicProxy']),
                'geographicProxyReason' => $this->string($trace, $metadata, ['geographicProxyReason', 'proxyReason']),
                'sourceGeography' => $this->string($trace, $metadata, ['sourceGeography', 'proxyGeography']),
                'targetGeography' => $this->string($trace, $metadata, ['targetGeography', 'requestedCountry']),
                'dataQuality' => $this->string($trace, $metadata, ['dataQuality', 'qualityStatus']),
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $trace */
    private function hasFactorEvidence(array $trace): bool
    {
        foreach (['factorId', 'factor_id', 'factorValue', 'effectiveFactorValue', 'value'] as $key) {
            if (array_key_exists($key, $trace) && null !== $trace[$key] && '' !== $trace[$key]) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $trace
     *  @param array<string, mixed> $metadata
     *  @param list<string> $keys
     */
    private function scalar(array $trace, array $metadata, array $keys): string|int|float|null
    {
        foreach ([$trace, $metadata] as $source) {
            foreach ($keys as $key) {
                $value = $source[$key] ?? null;
                if ((is_string($value) && '' !== trim($value)) || is_int($value) || is_float($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $trace
     *  @param array<string, mixed> $metadata
     *  @param list<string> $keys
     */
    private function string(array $trace, array $metadata, array $keys): ?string
    {
        $value = $this->scalar($trace, $metadata, $keys);

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $trace
     *  @param array<string, mixed> $metadata
     *  @param list<string> $keys
     */
    private function integer(array $trace, array $metadata, array $keys): ?int
    {
        $value = $this->scalar($trace, $metadata, $keys);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /** @param array<string, mixed> $trace
     *  @param array<string, mixed> $metadata
     *  @param list<string> $keys
     */
    private function boolean(array $trace, array $metadata, array $keys): bool
    {
        foreach ([$trace, $metadata] as $source) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && is_bool($source[$key])) {
                    return $source[$key];
                }
            }
        }

        return false;
    }
}
