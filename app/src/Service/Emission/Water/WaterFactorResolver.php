<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Service\Emission\EmissionFactorResolver;

final readonly class WaterFactorResolver
{
    private const CATEGORY_KEY = 'water';
    private const GEOGRAPHY_UK = 'Reino Unido';
    private const GEOGRAPHY_CATALONIA = 'Cataluña';
    private const FACTOR_SUPPLY = 'water_supply';
    private const FACTOR_TREATMENT = 'water_treatment';
    private const FACTOR_URBAN_CYCLE = 'urban_water_cycle';

    public function __construct(private EmissionFactorResolver $factorResolver)
    {
    }

    /** @return list<WaterFactorResolution> */
    public function resolve(string $country, int $activityYear, string $destination): array
    {
        $country = strtoupper(trim($country));
        if (!in_array($destination, WaterEmissionInput::destinations(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported water destination "%s".', $destination));
        }

        if ('ES' === $country
            && $activityYear >= 2024
            && WaterEmissionInput::DESTINATION_IRRIGATION !== $destination
        ) {
            return [$this->resolveComponent(
                self::FACTOR_URBAN_CYCLE,
                self::GEOGRAPHY_CATALONIA,
                'España/ES',
                $activityYear,
                true,
                WaterFactorResolution::QUALITY_MEDIUM,
            )];
        }

        $isUk = 'GB' === $country;
        $targetGeography = match ($country) {
            'GB' => 'Reino Unido/GB',
            'ES' => 'España/ES',
            default => $country,
        };
        $resolutions = [$this->resolveComponent(
            self::FACTOR_SUPPLY,
            self::GEOGRAPHY_UK,
            $targetGeography,
            $activityYear,
            !$isUk,
            $isUk ? WaterFactorResolution::QUALITY_HIGH : WaterFactorResolution::QUALITY_LOW,
        )];

        if (WaterEmissionInput::DESTINATION_IRRIGATION !== $destination) {
            $resolutions[] = $this->resolveComponent(
                self::FACTOR_TREATMENT,
                self::GEOGRAPHY_UK,
                $targetGeography,
                $activityYear,
                !$isUk,
                $isUk ? WaterFactorResolution::QUALITY_HIGH : WaterFactorResolution::QUALITY_LOW,
            );
        }

        return $resolutions;
    }

    private function resolveComponent(
        string $factorType,
        string $sourceGeography,
        string $targetGeography,
        int $activityYear,
        bool $isGeographicProxy,
        string $exactQuality,
    ): WaterFactorResolution {
        $resolution = $this->factorResolver->resolve(
            self::CATEGORY_KEY,
            $this->criteria($sourceGeography, $factorType),
            $activityYear,
        );
        $quality = self::FACTOR_URBAN_CYCLE === $factorType && $resolution->isFallback
            ? WaterFactorResolution::QUALITY_LOW
            : $exactQuality;

        return WaterFactorResolution::fromAnnual(
            $resolution,
            $factorType,
            $factorType,
            $sourceGeography,
            $targetGeography,
            $isGeographicProxy,
            $quality,
        );
    }

    /** @return array<string, string> */
    private function criteria(string $geography, string $factorType): array
    {
        return [
            'geography' => $geography,
            'factorType' => $factorType,
            'unit' => 'm3',
        ];
    }
}
