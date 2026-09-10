<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

final readonly class WaterFactorTrace
{
    public function __construct(
        public string $component,
        public ?string $factorId,
        public string $factorType,
        public string $temporalType,
        public int $activityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $sourceEdition,
        public ?string $factorVersion,
        public string $sourceGeography,
        public string $targetGeography,
        public bool $isFallback,
        public ?string $fallbackReason,
        public bool $isGeographicProxy,
        public ?string $proxyGeography,
        public string $dataQuality,
        public ?string $qualityStatus,
        public string $normalizedAmount,
        public string $normalizedUnit,
        public ?string $componentEmissionKgCo2e,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromResolution(
        WaterFactorResolution $resolution,
        string $normalizedAmount,
        ?string $componentEmissionKgCo2e,
    ): self {
        return new self(
            $resolution->component,
            $resolution->factorId,
            $resolution->factorType,
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorYear,
            $resolution->factorValue,
            $resolution->factorUnit,
            $resolution->source,
            $resolution->sourceDetail,
            $resolution->sourceEdition,
            $resolution->factorVersion,
            $resolution->sourceGeography,
            $resolution->targetGeography,
            $resolution->isFallback,
            $resolution->fallbackReason,
            $resolution->isGeographicProxy,
            $resolution->proxyGeography,
            $resolution->dataQuality,
            $resolution->qualityStatus,
            $normalizedAmount,
            'm3',
            $componentEmissionKgCo2e,
            $resolution->criteria,
            $resolution->metadata,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'factorId' => $this->factorId,
            'factorType' => $this->factorType,
            'temporalType' => $this->temporalType,
            'activityYear' => $this->activityYear,
            'factorYear' => $this->factorYear,
            'factorValue' => $this->factorValue,
            'factorUnit' => $this->factorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'sourceEdition' => $this->sourceEdition,
            'factorVersion' => $this->factorVersion,
            'sourceGeography' => $this->sourceGeography,
            'targetGeography' => $this->targetGeography,
            'fallback' => $this->isFallback,
            'isTemporalFallback' => $this->isFallback,
            'fallbackReason' => $this->fallbackReason,
            'geographicProxy' => $this->isGeographicProxy,
            'isGeographicProxy' => $this->isGeographicProxy,
            'proxyGeography' => $this->proxyGeography,
            'dataQuality' => $this->dataQuality,
            'qualityStatus' => $this->qualityStatus,
            'normalizedAmount' => $this->normalizedAmount,
            'normalizedUnit' => $this->normalizedUnit,
            'componentEmissionKgCo2e' => $this->componentEmissionKgCo2e,
            'criteria' => $this->criteria,
            'metadata' => $this->metadata,
        ];
    }
}
