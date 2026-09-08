<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

final readonly class MaterialEmissionInput
{
    public const METHOD_WEIGHT = 'weight';
    public const METHOD_DIMENSIONS = 'dimensions';
    public const METHOD_PACKAGES = 'packages';
    public const METHOD_GRAMMAGE = 'grammage';
    public const METHOD_UNITS = 'units';
    public const METHOD_SURFACE = 'surface';
    public const METHOD_VOLUME = 'volume';

    public function __construct(
        public ?\DateTimeInterface $startDate = null,
        public ?\DateTimeInterface $endDate = null,
        public ?string $country = null,
        public ?string $activity = null,
        public ?string $subproduct = null,
        public ?string $origin = null,
        public ?string $measurementMethod = null,
        public ?string $inputQuantity = null,
        public ?string $inputUnit = null,
        public ?string $woodType = null,
        public ?string $boardFamily = null,
        public ?string $boardThickness = null,
        public ?string $lengthMeters = null,
        public ?string $widthMeters = null,
        public ?string $thicknessMeters = null,
        public ?string $unitCount = null,
        public ?string $pieceWeightKg = null,
        public ?string $grammageGm2 = null,
        public ?string $paperFormat = null,
        public ?string $sheetsPerPackage = null,
        public ?string $cardboardType = null,
        public ?string $batteryChemistry = null,
        public ?string $batterySize = null,
    ) {
    }
}
