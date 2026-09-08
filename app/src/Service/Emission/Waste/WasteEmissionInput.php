<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

final readonly class WasteEmissionInput
{
    public const UNIT_KG = 'kg';
    public const UNIT_TONNE = 't';

    public function __construct(
        public ?\DateTimeInterface $startDate,
        public ?\DateTimeInterface $endDate,
        public ?string $country,
        public ?string $wasteType,
        public ?string $wasteActivity,
        public ?string $treatment,
        public ?string $weight,
        public ?string $weightUnit,
    ) {
    }

    /** @return list<string> */
    public static function weightUnits(): array
    {
        return [self::UNIT_KG, self::UNIT_TONNE];
    }
}
