<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

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
        $resolution = $this->factorResolver->resolveByApplicability(
            self::CATEGORY_KEY,
            $criteria,
            $activityYear,
        );

        return MaterialFactorResolution::fromResolution($resolution, $route);
    }
}
