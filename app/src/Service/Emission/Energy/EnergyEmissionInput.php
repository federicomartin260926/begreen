<?php

namespace App\Service\Emission\Energy;

final readonly class EnergyEmissionInput
{
    public const FAMILY_ELECTRICITY = 'electricity';
    public const FAMILY_EQUIPMENT = 'equipment';
    public const FAMILY_BATTERY = 'battery';
    public const FAMILY_DIGITAL = 'digital';
    public const ORIGIN_GRID = ElectricityFactorInput::ORIGIN_GRID;
    public const ORIGIN_SOLAR = ElectricityFactorInput::ORIGIN_SOLAR;
    public const ORIGIN_MIXED = ElectricityFactorInput::ORIGIN_MIXED;
    public const ORIGIN_UNKNOWN = ElectricityFactorInput::ORIGIN_UNKNOWN;
    public const EQUIPMENT_MODE_DIRECT = 'direct';
    public const EQUIPMENT_MODE_CYLINDERS = 'cylinders';

    public function __construct(
        public string $family,
        public ?\DateTimeInterface $startDate,
        public ?\DateTimeInterface $endDate,
        public ?string $country,
        public ?string $origin = null,
        public ?string $amount = null,
        public ?string $unit = null,
        public ?string $initialReading = null,
        public ?string $finalReading = null,
        public ?string $gridKwh = null,
        public ?string $solarKwh = null,
        public ?string $supplier = null,
        public ?string $labeling = null,
        public ?string $equipmentType = null,
        public ?string $fuel = null,
        public string $mode = 'direct',
        public ?string $bottleSizeKg = null,
        public ?string $bottleCount = null,
        public ?string $batteryType = null,
        public ?string $chargeSource = null,
        public ?string $chargedKwh = null,
        public ?string $digitalType = null,
        public ?string $digitalLocation = null,
        public ?string $digitalCountry = null,
        public ?string $knownKwh = null,
        public ?string $hours = null,
        public ?string $units = null,
        public ?string $gpu = null,
        public ?string $service = null,
        public ?string $model = null,
        public ?string $provider = null,
        public ?string $ownership = null,
    ) {
    }
}
