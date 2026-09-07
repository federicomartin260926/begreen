<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

final readonly class WaterEmissionInput
{
    public const USE_SANITARY = 'sanitarios';
    public const USE_SHOWERS = 'duchas';
    public const USE_CLEANING = 'limpieza';
    public const USE_FX = 'FX';
    public const USE_POOL_TANK = 'piscina/tanque';
    public const USE_IRRIGATION = 'riego';
    public const USE_PROCESS = 'proceso';
    public const USE_OTHER = 'otros';

    public const DESTINATION_SEWER = 'sewer';
    public const DESTINATION_IRRIGATION = 'irrigation';
    public const DESTINATION_UNKNOWN = 'unknown';

    public const UNIT_LITRES = 'L';
    public const UNIT_CUBIC_METRES = 'm3';

    public function __construct(
        public ?\DateTimeInterface $startDate,
        public ?\DateTimeInterface $endDate,
        public ?string $country,
        public ?string $waterUseType,
        public ?string $volumeInput,
        public ?string $volumeInputUnit,
        public ?string $destination,
    ) {
    }

    /** @return list<string> */
    public static function waterUseTypes(): array
    {
        return [
            self::USE_SANITARY,
            self::USE_SHOWERS,
            self::USE_CLEANING,
            self::USE_FX,
            self::USE_POOL_TANK,
            self::USE_IRRIGATION,
            self::USE_PROCESS,
            self::USE_OTHER,
        ];
    }

    /** @return list<string> */
    public static function destinations(): array
    {
        return [
            self::DESTINATION_SEWER,
            self::DESTINATION_IRRIGATION,
            self::DESTINATION_UNKNOWN,
        ];
    }
}
