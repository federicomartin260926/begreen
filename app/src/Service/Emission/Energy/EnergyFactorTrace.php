<?php

namespace App\Service\Emission\Energy;

final readonly class EnergyFactorTrace
{
    /** @param array<string, mixed> $criteria
     *  @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $component,
        public ?string $factorId,
        public ?string $activityAmount,
        public ?string $activityUnit,
        public string $temporalType,
        public int $activityYear,
        public ?int $factorActivityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $factorVersion,
        public ?string $qualityStatus,
        public bool $isFallback,
        public ?string $fallbackReason,
        public bool $isGeographicProxy,
        public ?string $proxyGeography,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromElectricity(
        ElectricityFactorResolution $resolution,
        string $component,
        string $activityAmount,
    ): self {
        return new self(
            $component,
            $resolution->factorId,
            $activityAmount,
            'kWh',
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorActivityYear,
            $resolution->factorYear,
            $resolution->value,
            $resolution->unit,
            $resolution->source,
            $resolution->sourceDetail,
            $resolution->factorVersion,
            $resolution->qualityStatus,
            $resolution->isFallback,
            $resolution->fallbackReason,
            $resolution->isGeographicProxy,
            $resolution->proxyGeography,
            $resolution->criteria,
            $resolution->metadata,
        );
    }

    public static function fromStationary(
        StationaryCombustionFactorResolution $resolution,
        string $activityAmount,
        string $activityUnit,
    ): self {
        $factor = $resolution->resolution->factor;

        return new self(
            'stationary_combustion',
            $factor?->getFactorId(),
            $activityAmount,
            $activityUnit,
            $resolution->resolution->temporalType,
            $resolution->resolution->activityYear,
            $factor?->getActivityYear(),
            $resolution->resolution->factorYear,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            is_string($factor?->getMetadata()['factorVersion'] ?? null) ? $factor->getMetadata()['factorVersion'] : null,
            is_string($factor?->getMetadata()['qualityStatus'] ?? null) ? $factor->getMetadata()['qualityStatus'] : null,
            $resolution->resolution->isFallback,
            $resolution->resolution->fallbackReason,
            $resolution->isGeographicProxy,
            $resolution->proxyGeography,
            $factor?->getCriteria() ?? [],
            $factor?->getMetadata() ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'factorId' => $this->factorId,
            'activityAmount' => $this->activityAmount,
            'activityUnit' => $this->activityUnit,
            'temporalType' => $this->temporalType,
            'activityYear' => $this->activityYear,
            'factorActivityYear' => $this->factorActivityYear,
            'factorYear' => $this->factorYear,
            'factorValue' => $this->factorValue,
            'factorUnit' => $this->factorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'factorVersion' => $this->factorVersion,
            'qualityStatus' => $this->qualityStatus,
            'fallback' => $this->isFallback,
            'fallbackReason' => $this->fallbackReason,
            'geographicProxy' => $this->isGeographicProxy,
            'proxyGeography' => $this->proxyGeography,
            'criteria' => $this->criteria,
            'metadata' => $this->metadata,
        ];
    }
}
