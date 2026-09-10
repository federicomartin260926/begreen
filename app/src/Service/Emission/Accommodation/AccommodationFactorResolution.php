<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

use App\Service\Emission\EmissionFactorResolution;

final readonly class AccommodationFactorResolution
{
    private function __construct(
        private bool $factorFound,
        public ?string $factorId,
        public string $accommodationType,
        public string $temporalType,
        public int $activityYear,
        public ?int $factorActivityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public bool $isFallback,
        public ?string $fallbackReason,
        public bool $isGeographicProxy,
        public ?string $proxyReason,
        public ?string $proxyGeography,
        public ?string $factorVersion,
        public ?string $qualityStatus,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromResolution(
        EmissionFactorResolution $resolution,
        string $accommodationType,
        ?string $proxyReason = null,
        ?int $requestedActivityYear = null,
    ): self {
        $factor = $resolution->factor;
        $metadata = $factor?->getMetadata() ?? [];

        return new self(
            $resolution->hasFactor(),
            $factor?->getFactorId(),
            $accommodationType,
            $resolution->temporalType,
            $requestedActivityYear ?? $resolution->activityYear,
            $factor?->getActivityYear(),
            $resolution->factorYear,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            $resolution->isFallback,
            $resolution->fallbackReason,
            true === ($metadata['isGeographicProxy'] ?? false),
            $proxyReason,
            is_string($metadata['proxyGeography'] ?? null) ? $metadata['proxyGeography'] : null,
            is_string($metadata['factorVersion'] ?? null) ? $metadata['factorVersion'] : null,
            is_string($metadata['qualityStatus'] ?? null) ? $metadata['qualityStatus'] : null,
            $factor?->getCriteria() ?? [],
            $metadata,
        );
    }

    public function hasFactor(): bool
    {
        return $this->factorFound;
    }

    public function isCalculable(): bool
    {
        return $this->factorFound && null !== $this->factorValue;
    }
}
