<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

final readonly class CateringFactorTrace
{
    public function __construct(
        public string $component,
        public ?string $factorId,
        public ?string $menuVariant,
        public ?string $tablewareType,
        public string $temporalType,
        public ?int $activityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $factorVersion,
        public bool $isTemporalFallback,
        public ?string $fallbackReason,
        public bool $isGeographicProxy,
        public ?string $proxyGeography,
        public ?string $qualityStatus,
        public array $criteria,
        public array $metadata,
        public string $normalizedAmount,
        public string $normalizedUnit,
        public ?string $emissionKgCo2e,
    ) {
    }

    public static function fromResolution(
        CateringFactorResolution $resolution,
        string $normalizedAmount,
        string $normalizedUnit,
        ?string $emissionKgCo2e,
    ): self {
        $factorVersion = $resolution->metadata['factorVersion'] ?? null;
        $isTemporalFallback = true === ($resolution->metadata['isTemporalFallback'] ?? false);
        $isGeographicProxy = true === ($resolution->metadata['isGeographicProxy'] ?? false);

        return new self(
            $resolution->component,
            $resolution->factorId,
            'food' === $resolution->component ? $resolution->variant : null,
            'tableware' === $resolution->component ? $resolution->variant : null,
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorYear,
            $resolution->factorValue,
            $resolution->factorUnit,
            $resolution->source,
            $resolution->sourceDetail,
            is_string($factorVersion) ? $factorVersion : null,
            $isTemporalFallback,
            $isTemporalFallback ? 'source_marked_fallback' : null,
            $isGeographicProxy,
            is_string($resolution->metadata['proxyGeography'] ?? null) ? $resolution->metadata['proxyGeography'] : null,
            is_string($resolution->metadata['qualityStatus'] ?? null) ? $resolution->metadata['qualityStatus'] : null,
            $resolution->criteria,
            $resolution->metadata,
            $normalizedAmount,
            $normalizedUnit,
            $emissionKgCo2e,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'factorId' => $this->factorId,
            'menuVariant' => $this->menuVariant,
            'tablewareType' => $this->tablewareType,
            'temporalType' => $this->temporalType,
            'activityYear' => $this->activityYear,
            'factorYear' => $this->factorYear,
            'factorValue' => $this->factorValue,
            'factorUnit' => $this->factorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'factorVersion' => $this->factorVersion,
            'isTemporalFallback' => $this->isTemporalFallback,
            'fallbackReason' => $this->fallbackReason,
            'isGeographicProxy' => $this->isGeographicProxy,
            'proxyGeography' => $this->proxyGeography,
            'qualityStatus' => $this->qualityStatus,
            'criteria' => $this->criteria,
            'metadata' => $this->metadata,
            'normalizedAmount' => $this->normalizedAmount,
            'normalizedUnit' => $this->normalizedUnit,
            'emissionKgCo2e' => $this->emissionKgCo2e,
        ];
    }
}
