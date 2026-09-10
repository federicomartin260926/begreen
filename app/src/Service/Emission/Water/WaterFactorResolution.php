<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Service\Emission\EmissionFactorResolution;

final readonly class WaterFactorResolution
{
    public const QUALITY_HIGH = 'ALTA';
    public const QUALITY_MEDIUM = 'MEDIA';
    public const QUALITY_LOW = 'BAJA';

    private function __construct(
        private bool $factorFound,
        public ?string $factorId,
        public string $component,
        public string $factorType,
        public string $temporalType,
        public int $activityYear,
        public ?int $factorYear,
        public ?string $factorValue,
        public ?string $factorUnit,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $sourceEdition,
        public ?string $factorVersion,
        public string $sourceGeography,
        public string $targetGeography,
        public bool $isFallback,
        public ?string $fallbackReason,
        public bool $isGeographicProxy,
        public ?string $proxyGeography,
        public string $dataQuality,
        public ?string $qualityStatus,
        public array $criteria,
        public array $metadata,
    ) {
    }

    public static function fromAnnual(
        EmissionFactorResolution $resolution,
        string $component,
        string $factorType,
        string $sourceGeography,
        string $targetGeography,
        bool $isGeographicProxy,
        string $dataQuality,
    ): self {
        $factor = $resolution->factor;
        $metadata = $factor?->getMetadata() ?? [];

        return new self(
            $resolution->hasFactor(),
            $factor?->getFactorId(),
            $component,
            $factorType,
            $resolution->temporalType,
            $resolution->activityYear,
            $resolution->factorYear,
            $factor?->getValue(),
            $factor?->getUnit(),
            $factor?->getSource(),
            $factor?->getSourceDetail(),
            self::methodologicalVersion($metadata, 'sourceEdition'),
            self::methodologicalVersion($metadata, 'factorVersion'),
            $sourceGeography,
            $targetGeography,
            $resolution->isFallback,
            $resolution->fallbackReason,
            $isGeographicProxy || true === ($metadata['isGeographicProxy'] ?? false),
            $isGeographicProxy ? $sourceGeography : (is_string($metadata['proxyGeography'] ?? null) ? $metadata['proxyGeography'] : null),
            $dataQuality,
            is_string($metadata['qualityStatus'] ?? null) ? $metadata['qualityStatus'] : null,
            $factor?->getCriteria() ?? [],
            $metadata,
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

    /** @param array<string, mixed> $metadata */
    private static function methodologicalVersion(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;
        if (!is_string($value) || '' === trim($value) || WaterEmissionSnapshot::VERSION === $value) {
            return null;
        }

        return $value;
    }
}
