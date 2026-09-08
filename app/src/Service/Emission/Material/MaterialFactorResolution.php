<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorResolution;

final readonly class MaterialFactorResolution
{
    public function __construct(
        private bool $factorFound,
        public string $activity,
        public ?string $subproduct,
        public string $origin,
        public string $temporalType,
        public int $activityYear,
        public ?int $factorYear,
        public bool $isFallback,
        public ?string $fallbackReason,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $functionalKey,
        public array $criteria,
        public array $metadata,
    ) {
    }

    /** @param array<string, string> $route */
    public static function fromResolution(EmissionFactorResolution $resolution, array $route): self
    {
        $factor = $resolution->factor;

        return new self(
            $resolution->hasFactor(),
            $route['activity'],
            '' === $route['subproduct'] ? null : $route['subproduct'],
            $route['origin'],
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorYear,
            $resolution->isFallback,
            $resolution->fallbackReason,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            $factor?->getFunctionalKey(),
            $factor?->getCriteria() ?? [],
            $factor?->getMetadata() ?? [],
        );
    }

    public static function unavailable(
        string $activity,
        ?string $subproduct,
        string $origin,
        int $activityYear,
    ): self {
        return new self(
            false,
            $activity,
            $subproduct,
            $origin,
            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            $activityYear,
            null,
            false,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
            [],
        );
    }

    public function hasFactor(): bool
    {
        return $this->factorFound;
    }

    public function isCalculable(): bool
    {
        return $this->factorFound && null !== $this->factorValue;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'activity' => $this->activity,
            'subproduct' => $this->subproduct,
            'origin' => $this->origin,
            'temporalType' => $this->temporalType,
            'activityYear' => $this->activityYear,
            'factorYear' => $this->factorYear,
            'isFallback' => $this->isFallback,
            'fallbackReason' => $this->fallbackReason,
            'factorValue' => $this->factorValue,
            'factorUnit' => $this->factorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'functionalKey' => $this->functionalKey,
            'criteria' => $this->criteria,
            'metadata' => $this->metadata,
        ];
    }
}
