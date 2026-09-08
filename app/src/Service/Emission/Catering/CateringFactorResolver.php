<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorResolver;

final readonly class CateringFactorResolver
{
    private const CATEGORY_KEY = 'catering';

    public function __construct(private EmissionFactorResolver $factorResolver)
    {
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
