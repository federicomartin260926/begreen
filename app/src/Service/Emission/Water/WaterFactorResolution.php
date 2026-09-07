<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Service\Emission\EmissionFactorResolution;

final readonly class WaterFactorResolution
{
    public const QUALITY_HIGH = 'ALTA';
    public const QUALITY_MEDIUM = 'MEDIA';
    public const QUALITY_LOW = 'BAJA';

    private function __construct(
        private bool $factorFound,
        public string $component,
        public string $factorType,
        public int $activityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $sourceEdition,
        public string $sourceGeography,
        public string $targetGeography,
        public bool $isFallback,
        public ?string $fallbackReason,
        public bool $isGeographicProxy,
        public string $dataQuality,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromAnnual(
        EmissionFactorResolution $resolution,
        string $component,
        string $factorType,
        string $sourceGeography,
        string $targetGeography,
        bool $isGeographicProxy,
        string $dataQuality,
    ): self {
        $factor = $resolution->factor;
        $metadata = $factor?->getMetadata() ?? [];

        return new self(
            $resolution->hasFactor(),
            $component,
            $factorType,
            $resolution->activityYear,
            $resolution->factorYear,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            is_string($metadata['sourceEdition'] ?? null) ? $metadata['sourceEdition'] : null,
            $sourceGeography,
            $targetGeography,
            $resolution->isFallback,
            $resolution->fallbackReason,
            $isGeographicProxy,
            $dataQuality,
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
