<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorResolver;

final readonly class MaterialFactorResolver
{
    private const CATEGORY_KEY = 'material';

    public function __construct(
        private EmissionFactorResolver $factorResolver,
        private MaterialUiCatalog $catalog,
    ) {
    }

    public function resolve(
        string $activity,
        ?string $subproduct,
        string $origin,
        string $unit,
        int $activityYear,
    ): MaterialFactorResolution {
        $route = $this->catalog->resolveRoute($activity, $subproduct, $origin, $unit);
        if (null === $route) {
            return MaterialFactorResolution::unavailable($activity, $subproduct, $origin, $activityYear);
        }

        $criteria = [
            'activity' => $route['activity'],
            'subproduct' => $route['subproduct'],
            'origin' => $route['origin'],
            'unit' => $route['unit'],
        ];
        $resolution = match ($route['temporal_type']) {
            EmissionFactor::TEMPORAL_TYPE_ANNUAL => $this->factorResolver->resolve(self::CATEGORY_KEY, $criteria, $activityYear),
            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            EmissionFactor::TEMPORAL_TYPE_RULE => $this->factorResolver->resolveMethodological(
                self::CATEGORY_KEY,
                $criteria,
                $activityYear,
                $route['temporal_type'],
            ),
            default => throw new \UnexpectedValueException(sprintf('Unsupported material temporal type "%s".', $route['temporal_type'])),
        };

        return MaterialFactorResolution::fromResolution($resolution, $route);
    }
}
