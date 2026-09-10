<?php

namespace App\Service\Emission\Energy;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorResolution;

final readonly class ElectricityFactorResolution
{
    /** @param array<string, mixed> $criteria
     *  @param array<string, mixed> $metadata
     */
    private function __construct(
        private bool $factorFound,
        public ?string $factorId,
        public int $activityYear,
        public ?int $factorActivityYear,
        public ?int $factorYear,
        public bool $isFallback,
        public ?string $fallbackReason,
        public string $temporalType,
        public ?string $value,
        public ?string $unit,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $factorVersion,
        public ?string $qualityStatus,
        public array $criteria,
        public array $metadata,
        public bool $isGeographicProxy,
        public ?string $proxyGeography,
    ) {
    }

    /** @param array<string, mixed> $extraMetadata */
    public static function fromAnnual(
        EmissionFactorResolution $resolution,
        bool $isGeographicProxy,
        ?string $proxyGeography,
        array $extraMetadata = [],
    ): self {
        $factor = $resolution->factor;
        $metadata = $factor?->getMetadata() ?? [];
        $isGeographicProxy = $isGeographicProxy || true === ($metadata['isGeographicProxy'] ?? false);
        $proxyGeography ??= is_string($metadata['proxyGeography'] ?? null) ? $metadata['proxyGeography'] : null;

        return new self(
            $resolution->hasFactor(),
            $factor?->getFactorId(),
            $resolution->activityYear,
            $factor?->getActivityYear(),
            $resolution->factorYear,
            $resolution->isFallback,
            $resolution->fallbackReason,
            $resolution->temporalType,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            is_string($metadata['factorVersion'] ?? null) ? $metadata['factorVersion'] : null,
            is_string($metadata['qualityStatus'] ?? null) ? $metadata['qualityStatus'] : null,
            $factor?->getCriteria() ?? [],
            array_replace($metadata, $extraMetadata),
            $isGeographicProxy,
            $proxyGeography,
        );
    }

    /** @param array<string, mixed> $criteria
     *  @param array<string, mixed> $metadata
     */
    public static function rule(
        int $activityYear,
        string $value,
        string $unit,
        string $source,
        string $sourceDetail,
        array $criteria,
        array $metadata,
    ): self {
        return new self(
            true,
            null,
            $activityYear,
            null,
            null,
            false,
            null,
            EmissionFactor::TEMPORAL_TYPE_RULE,
            $value,
            $unit,
            $source,
            $sourceDetail,
            null,
            null,
            $criteria,
            $metadata,
            false,
            null,
        );
    }

    public static function unavailable(int $activityYear, string $temporalType): self
    {
        return new self(false, null, $activityYear, null, null, false, null, $temporalType, null, null, null, null, null, null, [], [], false, null);
    }

    public function hasFactor(): bool
    {
        return $this->factorFound;
    }

    public function isCalculable(): bool
    {
        return $this->factorFound && null !== $this->value;
    }
}
