<?php

namespace App\Service\Emission\Energy;

use App\Repository\EmissionFactorRepository;

final readonly class EnergyUiCatalog
{
    public function __construct(private EmissionFactorRepository $repository)
    {
    }

    /** @return array{suppliers: list<string>, labelings: list<string>, fuels: array<string, array<string, list<string>>>} */
    public function configuration(): array
    {
        $suppliers = [];
        $labelings = [];
        $fuels = ['ES' => [], 'OUTSIDE' => []];
        foreach ($this->repository->findCriteriaByCategoryKey('energy') as $criteria) {
            $category = $criteria['category'] ?? null;
            if ('ELECTRICIDAD' === $category) {
                $supplier = $criteria['supplier'] ?? null;
                if (is_string($supplier) && '' !== $supplier) {
                    $suppliers[$supplier] = true;
                }

                $labeling = $criteria['labeling'] ?? null;
                if (is_string($labeling) && '' !== $labeling) {
                    $labelings[$labeling] = true;
                }

                continue;
            }
            if ('COMBUSTIÓN ESTACIONARIA' !== $category) {
                continue;
            }

            $geography = 'ESPAÑA' === ($criteria['geography'] ?? null) ? 'ES' : 'OUTSIDE';
            $fuel = $criteria['activity'] ?? null;
            $unit = $criteria['unit'] ?? null;
            if (is_string($fuel) && '' !== $fuel && is_string($unit) && '' !== $unit) {
                $fuels[$geography][$fuel][$unit] = true;
            }
        }

        $supplierNames = array_keys($suppliers);
        sort($supplierNames, SORT_NATURAL | SORT_FLAG_CASE);

        $labelingNames = array_keys($labelings);
        sort($labelingNames, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($fuels as &$fuelGroups) {
            ksort($fuelGroups, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($fuelGroups as &$units) {
                $units = array_keys($units);
                sort($units, SORT_NATURAL | SORT_FLAG_CASE);
            }
            unset($units);
        }
        unset($fuelGroups);

        return ['suppliers' => $supplierNames, 'labelings' => $labelingNames, 'fuels' => $fuels];
    }
}
