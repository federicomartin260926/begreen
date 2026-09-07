<?php

namespace App\Service\Emission\Energy;

use App\Service\Emission\EmissionFactorResolution;

final readonly class StationaryCombustionFactorResolution
{
    public function __construct(
        public EmissionFactorResolution $resolution,
        public bool $isGeographicProxy,
        public ?string $proxyGeography,
    ) {
    }

    public function hasFactor(): bool
    {
        return $this->resolution->hasFactor();
    }

    public function isCalculable(): bool
    {
        return $this->resolution->isCalculable();
    }
}
