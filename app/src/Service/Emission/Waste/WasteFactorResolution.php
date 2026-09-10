<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use App\Service\Emission\EmissionFactorResolution;

final readonly class WasteFactorResolution
{
    public function __construct(
        private bool $factorFound,
        public string $requestedCountry,
        public string $regionScope,
        public string $wasteType,
        public string $wasteActivity,
        public string $requestedTreatment,
        public ?string $resolvedTreatment,
        public ?string $ruleType,
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
        public ?string $sourceGeography,
        public bool $isGeographicProxy,
        public ?string $geographicProxyReason,
        public array $criteria,
        public array $metadata,
        public array $candidateEvaluations = [],
        public ?string $factorId = null,
        public ?int $factorActivityYear = null,
        public ?string $factorVersion = null,
        public ?string $qualityStatus = null,
    ) {
    }

    /** @param array<string, string> $route */
    public static function fromResolution(
        EmissionFactorResolution $resolution,
        string $requestedCountry,
        array $route,
        ?string $ruleType = null,
        ?string $requestedTreatment = null,
        array $candidateEvaluations = [],
    ): self {
        $factor = $resolution->factor;
        $sourceFamily = $route['source_family'] ?? null;
        $sourceGeography = $factor?->getMetadata()['sourceGeography'] ?? match ($sourceFamily) {
            'OCCC' => 'ESP',
            'DEFRA' => 'GBR',
            default => null,
        };
        [$isProxy, $proxyReason] = self::proxyTrace($requestedCountry, $sourceGeography);

        return new self(
            $resolution->hasFactor(),
            $requestedCountry,
            $route['region_scope'],
            $route['waste_type'],
            $route['waste_activity'],
            $requestedTreatment ?? $route['treatment'],
            $route['treatment'],
            $ruleType,
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
            is_string($sourceGeography) ? $sourceGeography : null,
            $isProxy,
            $proxyReason,
            $factor?->getCriteria() ?? [],
            $factor?->getMetadata() ?? [],
            $candidateEvaluations,
            $factor?->getFactorId(),
            $factor?->getActivityYear(),
            is_string($factor?->getMetadata()['factorVersion'] ?? null) ? $factor->getMetadata()['factorVersion'] : null,
            is_string($factor?->getMetadata()['qualityStatus'] ?? null) ? $factor->getMetadata()['qualityStatus'] : null,
        );
    }

    /** @param list<array<string, mixed>> $candidateEvaluations */
    public static function unavailableDerived(
        string $requestedCountry,
        string $regionScope,
        string $wasteType,
        string $wasteActivity,
        int $activityYear,
        array $candidateEvaluations,
    ): self {
        return new self(
            false,
            $requestedCountry,
            $regionScope,
            $wasteType,
            $wasteActivity,
            'Desconocido',
            null,
            WasteUiCatalog::ROUTE_UNKNOWN,
            \App\Entity\EmissionFactor::TEMPORAL_TYPE_RULE,
            $activityYear,
            null,
            false,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            null,
            [],
            [],
            $candidateEvaluations,
        );
    }

    /** @param list<array<string, mixed>> $candidateEvaluations */
    public function asDerivedUnknown(array $candidateEvaluations): self
    {
        return new self(
            $this->factorFound,
            $this->requestedCountry,
            $this->regionScope,
            $this->wasteType,
            $this->wasteActivity,
            'Desconocido',
            $this->resolvedTreatment,
            WasteUiCatalog::ROUTE_UNKNOWN,
            $this->temporalType,
            $this->activityYear,
            $this->factorYear,
            $this->isFallback,
            $this->fallbackReason,
            $this->factorValue,
            $this->factorUnit,
            $this->source,
            $this->sourceDetail,
            $this->functionalKey,
            $this->sourceGeography,
            $this->isGeographicProxy,
            $this->geographicProxyReason,
            $this->criteria,
            $this->metadata,
            $candidateEvaluations,
            $this->factorId,
            $this->factorActivityYear,
            $this->factorVersion,
            $this->qualityStatus,
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
    public function toArray(bool $includeCandidates = true): array
    {
        return [
            'requestedCountry' => $this->requestedCountry,
            'regionScope' => $this->regionScope,
            'wasteType' => $this->wasteType,
            'wasteActivity' => $this->wasteActivity,
            'requestedTreatment' => $this->requestedTreatment,
            'resolvedTreatment' => $this->resolvedTreatment,
            'ruleType' => $this->ruleType,
            'factorId' => $this->factorId,
            'temporalType' => $this->temporalType,
            'activityYear' => $this->activityYear,
            'factorActivityYear' => $this->factorActivityYear,
            'factorYear' => $this->factorYear,
            'factorVersion' => $this->factorVersion,
            'qualityStatus' => $this->qualityStatus,
            'isFallback' => $this->isFallback,
            'fallbackReason' => $this->fallbackReason,
            'factorValue' => $this->factorValue,
            'factorUnit' => $this->factorUnit,
            'source' => $this->source,
            'sourceDetail' => $this->sourceDetail,
            'functionalKey' => $this->functionalKey,
            'sourceGeography' => $this->sourceGeography,
            'isGeographicProxy' => $this->isGeographicProxy,
            'geographicProxyReason' => $this->geographicProxyReason,
            'criteria' => $this->criteria,
            'metadata' => $this->metadata,
            'candidateEvaluations' => $includeCandidates ? $this->candidateEvaluations : [],
        ];
    }

    /** @return array{bool, ?string} */
    private static function proxyTrace(string $requestedCountry, ?string $sourceGeography): array
    {
        if (null === $sourceGeography || $requestedCountry === $sourceGeography) {
            return [false, null];
        }
        if ('GBR' === $sourceGeography && 'ESP' === $requestedCountry) {
            return [true, 'spain_factor_unavailable_defra_uk_proxy'];
        }
        if ('GBR' === $sourceGeography) {
            return [true, 'defra_uk_geographic_proxy'];
        }

        return [true, 'source_geography_differs_from_requested_country'];
    }
}
