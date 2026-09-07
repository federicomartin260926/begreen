<?php

namespace App\Service\Emission;

use App\Entity\EmissionFactor;

final readonly class EmissionFactorResolution
{
    public const FALLBACK_REASON_EXACT_YEAR_MISSING = 'exact_year_missing';

    public function __construct(
        public ?EmissionFactor $factor,
        public int $activityYear,
        public ?int $factorYear,
        public bool $isFallback,
        public ?string $fallbackReason,
        public string $temporalType = EmissionFactor::TEMPORAL_TYPE_ANNUAL,
    ) {
    }

    public function hasFactor(): bool
    {
        return null !== $this->factor;
    }

    public function isCalculable(): bool
    {
        return null !== $this->factor?->getValue();
    }
}
