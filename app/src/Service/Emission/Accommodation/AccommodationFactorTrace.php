<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

final readonly class AccommodationFactorTrace
{
    public function __construct(
        public string $accommodationType,
        public string $temporalType,
        public int $activityYear,
        public ?int $factorYear,
        public ?string $baseFactorValue,
        public ?string $effectiveFactorValue,
        public ?string $baseFactorUnit,
        public ?string $effectiveFactorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public bool $isFallback,
        public ?string $fallbackReason,
        public bool $isGeographicProxy,
        public ?string $proxyReason,
        public ?string $averageOccupancy,
        public ?string $hostelReductionFactor,
        public string $normalizedAmount,
        public string $normalizedUnit,
        public ?string $emissionKgCo2e,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromResolution(
        AccommodationFactorResolution $resolution,
        string $accommodationType,
        string $normalizedAmount,
        string $normalizedUnit,
        ?string $effectiveFactorValue,
        ?string $effectiveFactorUnit,
        ?string $emissionKgCo2e,
        ?string $averageOccupancy = null,
        ?string $hostelReductionFactor = null,
        ?string $proxyReason = null,
    ): self {
        return new self(
            $accommodationType,
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorYear,
            $resolution->factorValue,
            $effectiveFactorValue,
            $resolution->factorUnit,
            $effectiveFactorUnit,
            $resolution->source,
            $resolution->sourceDetail,
            $resolution->isFallback,
            $resolution->fallbackReason,
            $resolution->isGeographicProxy,
            $proxyReason ?? $resolution->proxyReason,
            $averageOccupancy,
            $hostelReductionFactor,
            $normalizedAmount,
            $normalizedUnit,
            $emissionKgCo2e,
            $resolution->criteria,
            $resolution->metadata,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accommodationType' => $this->accommodationType,
            'temporalType' => $this->temporalType,
            'activityYear' => $this->activityYear,
            'factorYear' => $this->factorYear,
            'baseFactorValue' => $this->baseFactorValue,
            'effectiveFactorValue' => $this->effectiveFactorValue,
            'baseFactorUnit' => $this->baseFactorUnit,
            'effectiveFactorUnit' => $this->effectiveFactorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'fallback' => $this->isFallback,
            'fallbackReason' => $this->fallbackReason,
            'geographicProxy' => $this->isGeographicProxy,
            'proxyReason' => $this->proxyReason,
            'averageOccupancy' => $this->averageOccupancy,
            'hostelReductionFactor' => $this->hostelReductionFactor,
            'normalizedAmount' => $this->normalizedAmount,
            'normalizedUnit' => $this->normalizedUnit,
            'emissionKgCo2e' => $this->emissionKgCo2e,
            'criteria' => $this->criteria,
            'metadata' => $this->metadata,
        ];
    }
}
