<?php

namespace App\Service\Emission\Energy;

final readonly class EnergyFactorTrace
{
    /** @param array<string, mixed> $criteria
     *  @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $component,
        public ?string $activityAmount,
        public ?string $activityUnit,
        public string $temporalType,
        public int $activityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
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
            $activityAmount,
            'kWh',
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorYear,
            $resolution->value,
            $resolution->unit,
            $resolution->source,
            $resolution->sourceDetail,
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
            $activityAmount,
            $activityUnit,
            $resolution->resolution->temporalType,
            $resolution->resolution->activityYear,
            $resolution->resolution->factorYear,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
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
            'activityAmount' => $this->activityAmount,
            'activityUnit' => $this->activityUnit,
            'temporalType' => $this->temporalType,
            'activityYear' => $this->activityYear,
            'factorYear' => $this->factorYear,
            'factorValue' => $this->factorValue,
            'factorUnit' => $this->factorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'fallback' => $this->isFallback,
            'fallbackReason' => $this->fallbackReason,
            'geographicProxy' => $this->isGeographicProxy,
            'proxyGeography' => $this->proxyGeography,
            'criteria' => $this->criteria,
            'metadata' => $this->metadata,
        ];
    }
}
