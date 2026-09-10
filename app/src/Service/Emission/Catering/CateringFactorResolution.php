<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

use App\Service\Emission\EmissionFactorResolution;

final readonly class CateringFactorResolution
{
    private function __construct(
        private bool $factorFound,
        public ?string $factorId,
        public string $component,
        public string $variant,
        public string $temporalType,
        public ?int $activityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromResolution(EmissionFactorResolution $resolution, string $component, string $variant): self
    {
        $factor = $resolution->factor;

        return new self(
            $resolution->hasFactor(),
            $factor?->getFactorId(),
            $component,
            $variant,
            $resolution->temporalType,
            $factor?->getActivityYear(),
            $resolution->factorYear,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            $factor?->getCriteria() ?? [],
            $factor?->getMetadata() ?? [],
        );
    }

    public function hasFactor(): bool
    {
        return $this->factorFound;
    }
}
