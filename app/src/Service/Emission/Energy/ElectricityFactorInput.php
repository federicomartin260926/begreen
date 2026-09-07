<?php

namespace App\Service\Emission\Energy;

final readonly class ElectricityFactorInput
{
    public const ORIGIN_GRID = 'grid';
    public const ORIGIN_SOLAR = 'solar';
    public const ORIGIN_MIXED = 'mixed';
    public const ORIGIN_UNKNOWN = 'unknown';

    public function __construct(
        public string $country,
        public int $activityYear,
        public string $origin = self::ORIGIN_GRID,
        public ?string $supplier = null,
        public ?string $labeling = null,
    ) {
    }
}
