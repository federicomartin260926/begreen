<?php

namespace App\Service\Emission\Energy;

final readonly class EnergyEmissionResult
{
    public ?int $factorYear;
    public ?string $factorValue;
    public ?string $factorUnit;
    public ?string $source;
    public ?string $sourceDetail;
    public bool $isFallback;
    public ?string $fallbackReason;
    public bool $isGeographicProxy;
    public ?string $proxyGeography;

    /** @param list<EnergyFactorTrace> $factorTraces
     *  @param list<string> $messages
     */
    public function __construct(
        public string $status,
        public ?string $emissionKgCo2e,
        public ?string $normalizedAmount,
        public ?string $normalizedUnit,
        public ?int $activityYear,
        public ?string $temporalType,
        public array $factorTraces = [],
        public array $messages = [],
    ) {
        $primary = 1 === count($factorTraces) ? $factorTraces[0] : null;
        $this->factorYear = $primary?->factorYear;
        $this->factorValue = $primary?->factorValue;
        $this->factorUnit = $primary?->factorUnit;
        $this->source = $primary?->source;
        $this->sourceDetail = $primary?->sourceDetail;
        $this->isFallback = $primary?->isFallback ?? false;
        $this->fallbackReason = $primary?->fallbackReason;
        $this->isGeographicProxy = $primary?->isGeographicProxy ?? false;
        $this->proxyGeography = $primary?->proxyGeography;
    }

    public function isCalculated(): bool
    {
        return \App\Entity\EmissionRecord::STATUS_CALCULATED === $this->status;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'emissionKgCo2e' => $this->emissionKgCo2e,
            'normalizedAmount' => $this->normalizedAmount,
            'normalizedUnit' => $this->normalizedUnit,
            'activityYear' => $this->activityYear,
            'temporalType' => $this->temporalType,
            'factorYear' => $this->factorYear,
            'factorValue' => $this->factorValue,
            'factorUnit' => $this->factorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'fallback' => $this->isFallback,
            'fallbackReason' => $this->fallbackReason,
            'geographicProxy' => $this->isGeographicProxy,
            'proxyGeography' => $this->proxyGeography,
            'messages' => $this->messages,
            'factorTraces' => array_map(static fn (EnergyFactorTrace $trace): array => $trace->toArray(), $this->factorTraces),
        ];
    }
}
