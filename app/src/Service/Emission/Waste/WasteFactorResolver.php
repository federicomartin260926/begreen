<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorResolution;
use App\Service\Emission\EmissionFactorResolver;

final readonly class WasteFactorResolver
{
    private const CATEGORY_KEY = 'waste';
    private const SCALE = 18;

    public function __construct(
        private EmissionFactorResolver $factorResolver,
        private WasteUiCatalog $catalog,
    ) {
    }

    public function resolve(
        string $country,
        string $wasteType,
        string $wasteActivity,
        string $treatment,
        int $activityYear,
    ): WasteFactorResolution {
        $country = $this->catalog->normalizeCountry($country);
        $route = $this->catalog->resolveRoute($country, $wasteType, $wasteActivity, $treatment);
        if (null === $route) {
            throw new \InvalidArgumentException('Unsupported waste type/activity/treatment combination.');
        }

        return match ($route['route_type']) {
            WasteUiCatalog::ROUTE_FACTOR => $this->resolveFactorRoute($country, $route, $activityYear),
            WasteUiCatalog::ROUTE_NON_WASTE_ZERO => $this->resolveZeroRule($country, $route, $activityYear),
            WasteUiCatalog::ROUTE_UNKNOWN => $this->resolveUnknown($country, $route, $activityYear),
            default => throw new \UnexpectedValueException(sprintf('Unsupported waste route type "%s".', $route['route_type'])),
        };
    }

    /** @param array<string, string> $route */
    private function resolveFactorRoute(string $country, array $route, int $activityYear): WasteFactorResolution
    {
        $criteria = [
            'regionScope' => $route['region_scope'],
            'wasteType' => $route['waste_type'],
            'wasteActivity' => $route['waste_activity'],
            'treatment' => $route['treatment'],
            'unit' => 'kg',
            'sourceFamily' => $route['source_family'],
        ];

        if (!in_array($route['temporal_type'], [
            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
        ], true)) {
            throw new \UnexpectedValueException(sprintf(
                'Unsupported waste factor temporal type "%s".',
                $route['temporal_type'],
            ));
        }

        $resolution = $this->factorResolver->resolveByApplicability(
            self::CATEGORY_KEY,
            $criteria,
            $activityYear,
        );

        return WasteFactorResolution::fromResolution($resolution, $country, $route);
    }

    /** @param array<string, string> $route */
    private function resolveZeroRule(string $country, array $route, int $activityYear): WasteFactorResolution
    {
        return new WasteFactorResolution(
            factorFound: true,
            requestedCountry: $country,
            regionScope: $route['region_scope'],
            wasteType: $route['waste_type'],
            wasteActivity: $route['waste_activity'],
            requestedTreatment: $route['treatment'],
            resolvedTreatment: $route['treatment'],
            ruleType: WasteUiCatalog::ROUTE_NON_WASTE_ZERO,
            temporalType: EmissionFactor::TEMPORAL_TYPE_RULE,
            activityYear: $activityYear,
            factorYear: null,
            isFallback: false,
            fallbackReason: null,
            factorValue: '0',
            factorUnit: 'kgCO2e/kg',
            source: 'BGMF Residuos specification',
            sourceDetail: 'Regla metodológica; no FE anual',
            functionalKey: null,
            sourceGeography: null,
            isGeographicProxy: false,
            geographicProxyReason: null,
            criteria: [
                'regionScope' => $route['region_scope'],
                'wasteType' => $route['waste_type'],
                'wasteActivity' => $route['waste_activity'],
                'treatment' => $route['treatment'],
                'unit' => 'kg',
                'ruleType' => WasteUiCatalog::ROUTE_NON_WASTE_ZERO,
            ],
            metadata: [
                'ruleType' => WasteUiCatalog::ROUTE_NON_WASTE_ZERO,
                'activityUnit' => 'kg',
            ],
        );
    }

    /** @param array<string, string> $route */
    private function resolveUnknown(string $country, array $route, int $activityYear): WasteFactorResolution
    {
        $candidateEvaluations = [];
        $best = null;
        foreach ($this->catalog->factorRoutesFor($country, $route['waste_type'], $route['waste_activity']) as $candidateRoute) {
            $candidate = $this->resolveFactorRoute($country, $candidateRoute, $activityYear);
            $candidateEvaluations[] = $candidate->toArray(false);
            if (!$candidate->isCalculable()) {
                continue;
            }
            if (null === $best || bccomp((string) $candidate->factorValue, (string) $best->factorValue, self::SCALE) > 0) {
                $best = $candidate;
            }
        }

        if (null === $best) {
            return WasteFactorResolution::unavailableDerived(
                $country,
                $route['region_scope'],
                $route['waste_type'],
                $route['waste_activity'],
                $activityYear,
                $candidateEvaluations,
            );
        }

        return $best->asDerivedUnknown($candidateEvaluations);
    }

}
