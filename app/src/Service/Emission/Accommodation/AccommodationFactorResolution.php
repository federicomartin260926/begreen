<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

use App\Service\Emission\EmissionFactorResolution;

final readonly class AccommodationFactorResolution
{
    private function __construct(
        private bool $factorFound,
        public string $accommodationType,
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
        public ?string $proxyReason,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromResolution(
        EmissionFactorResolution $resolution,
        string $accommodationType,
        ?string $proxyReason = null,
    ): self {
        $factor = $resolution->factor;
        $metadata = $factor?->getMetadata() ?? [];

        return new self(
            $resolution->hasFactor(),
            $accommodationType,
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorYear,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            $resolution->isFallback,
            $resolution->fallbackReason,
            true === ($metadata['isGeographicProxy'] ?? false),
            $proxyReason,
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
