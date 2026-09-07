<?php

namespace App\Service\Emission\Energy;

final readonly class StationaryCombustionFactorInput
{
    public function __construct(
        public string $country,
        public int $activityYear,
        public string $fuel,
        public string $unit,
    ) {
    }
}
