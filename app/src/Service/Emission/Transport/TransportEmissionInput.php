<?php

namespace App\Service\Emission\Transport;

final readonly class TransportEmissionInput
{
    public function __construct(
        public string $category,
        public string $mode,
        public string $method,
        public string $country,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public string $activityValue,
        public string $activityUnit,
        public string $repetitions = '1',
        public ?string $passengers = null,
        public ?string $weightValue = null,
        public ?string $weightUnit = null,
        public ?string $vehicleType = null,
        public ?string $carSize = null,
        public ?string $fuel = null,
        public ?string $thermalFuel = null,
        public ?string $routeClassification = null,
        public ?string $travelClass = null,
    ) {
    }
}
