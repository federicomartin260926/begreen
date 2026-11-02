<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Energy\StationaryCombustionFactorInput;
use App\Service\Emission\Energy\StationaryCombustionFactorResolution;
use App\Service\Emission\Energy\StationaryCombustionFactorResolver;

final readonly class CateringFactorResolver
{
    private const CATEGORY_KEY = 'catering';

    public function __construct(private EmissionFactorResolver $factorResolver)
    {
    }

    /** Use the already approved stationary-combustion factor (Energy), without duplicating it in Catering. */
    public function resolveGas(string $gasType, string $country, int $activityYear): StationaryCombustionFactorResolution
    {
        $fuel = match ($gasType) {
            'propane' => 'Gas propano',
            'butane' => 'Gas butano',
            default => throw new \InvalidArgumentException(sprintf('Unsupported catering gas type "%s".', $gasType)),
        };

        return (new StationaryCombustionFactorResolver($this->factorResolver))->resolve(new StationaryCombustionFactorInput(
            country: $country,
            activityYear: $activityYear,
            fuel: $fuel,
            unit: 'kg',
        ));
    }

    public function resolveFood(string $menuVariant, int $activityYear): CateringFactorResolution
    {
        if (!in_array($menuVariant, CateringEmissionInput::menuVariants(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported catering menu variant "%s".', $menuVariant));
        }

        return CateringFactorResolution::fromResolution(
            $this->factorResolver->resolveMethodological(self::CATEGORY_KEY, [
                'component' => 'food',
                'menuVariant' => $menuVariant,
                'unit' => 'prepared_menu',
            ], $activityYear, EmissionFactor::TEMPORAL_TYPE_VERSIONED),
            'food',
            $menuVariant,
        );
    }

    public function resolveTableware(string $tablewareType, int $activityYear): ?CateringFactorResolution
    {
        $temporalType = match ($tablewareType) {
            'compostable' => EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
            'reusable' => EmissionFactor::TEMPORAL_TYPE_PROXY_LCA,
            'other', 'unknown' => null,
            default => throw new \InvalidArgumentException(sprintf('Unsupported catering tableware type "%s".', $tablewareType)),
        };
        if (null === $temporalType) {
            return null;
        }

        return CateringFactorResolution::fromResolution(
            $this->factorResolver->resolveMethodological(self::CATEGORY_KEY, [
                'component' => 'tableware',
                'tablewareType' => $tablewareType,
                'unit' => 'consumed_menu',
            ], $activityYear, $temporalType),
            'tableware',
            $tablewareType,
        );
    }
}
