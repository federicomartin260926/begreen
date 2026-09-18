<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use Symfony\Component\HttpFoundation\Request;

final class BgosEmissionEntryContextResolver
{
    public const ROUTES = [
        'transport' => 'backend_emission_new_transport_v20',
        'energy' => 'backend_emission_new_energy_v1',
        'water' => 'backend_emission_new_water_v1',
        'accommodation' => 'backend_emission_new_accommodation_v1',
        'catering' => 'backend_emission_new_catering_v1',
        'materials' => 'backend_emission_new_material_v1',
        'waste' => 'backend_emission_new_waste_v1',
    ];

    public function __construct(
        private readonly BgosSubcategoryCatalog $catalog,
    ) {
    }

    public function resolve(Request $request, string $expectedCategoryKey): ?BgosEmissionEntryContext
    {
        $query = $request->query->all();
        foreach (['bgosDate', 'bgosView', 'bgosCategory', 'bgosSubcategory'] as $key) {
            if (!is_string($query[$key] ?? null) || '' === $query[$key]) {
                return null;
            }
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $query['bgosDate']);
        if (false === $date || $date->format('Y-m-d') !== $query['bgosDate']) {
            return null;
        }
        if (!in_array($query['bgosView'], BgosPeriodWindowResolver::VIEWS, true)) {
            return null;
        }
        if ($expectedCategoryKey !== $query['bgosCategory']) {
            return null;
        }

        $definition = $this->catalog->find($query['bgosCategory'], $query['bgosSubcategory']);
        if (null === $definition) {
            return null;
        }

        return new BgosEmissionEntryContext(
            date: $date,
            view: $query['bgosView'],
            categoryKey: $definition['categoryKey'],
            subcategoryKey: $definition['subcategoryKey'],
            formDefaults: $this->formDefaults($definition, $query['bgosDate']),
        );
    }

    /**
     * @param array{
     *     categoryKey:string,
     *     subcategoryKey:string,
     *     groupKey:?string,
     *     sourceKeys:list<string>
     * } $definition
     * @return array<string, string|null>
     */
    private function formDefaults(array $definition, string $date): array
    {
        $defaults = [
            'startDate' => $date,
            'endDate' => $date,
        ];
        $sourceKey = $definition['sourceKeys'][0] ?? null;

        return match ($definition['categoryKey']) {
            'transport' => $defaults + [
                'category' => 'freight' === $definition['subcategoryKey']
                    ? $sourceKey
                    : null,
            ],
            'energy' => $defaults + ['family' => $sourceKey],
            'water' => $defaults + ['waterUseType' => $sourceKey],
            'accommodation' => $defaults + ['accommodationType' => $sourceKey],
            'catering' => $defaults + ['activityType' => $sourceKey],
            'materials' => $defaults + [
                'activity' => $sourceKey,
                'family' => $definition['groupKey'],
            ],
            // Waste types depend on the country-specific UI catalog.
            'waste' => $defaults,
            default => $defaults,
        };
    }
}
