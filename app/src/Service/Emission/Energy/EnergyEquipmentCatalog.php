<?php

namespace App\Service\Emission\Energy;

final class EnergyEquipmentCatalog
{
    public const FUEL_ELECTRICITY = 'Electricidad';

    private const MATRIX = [
        'generator' => ['diesel', 'gasoil', 'biodiesel', 'hvo', 'petrol'],
        'heating' => ['naturalgas', 'propane', 'butane', 'gasoil', 'electricity'],
        'cooking' => ['naturalgas', 'propane', 'butane', 'electricity'],
        'boiler' => ['naturalgas', 'propane', 'butane', 'gasoil', 'biodiesel', 'hvo', 'electricity'],
        'climate' => ['electricity', 'naturalgas'],
        'other' => ['naturalgas', 'gasoil', 'propane', 'butane', 'diesel', 'biodiesel', 'hvo', 'petrol', 'electricity'],
    ];

    private const LABELS = [
        'diesel' => 'Diésel',
        'gasoil' => 'Gasóleo',
        'biodiesel' => 'Biodiésel',
        'hvo' => 'HVO',
        'petrol' => 'Gasolina',
        'naturalgas' => 'Gas natural',
        'propane' => 'Propano',
        'butane' => 'Butano',
        'electricity' => self::FUEL_ELECTRICITY,
    ];

    private const FACTOR_FUELS = [
        'ES' => [
            'diesel' => 'Diésel',
            'gasoil' => 'Gasóleo B',
            'biodiesel' => 'Biodiésel 100% (B100)',
            'hvo' => 'XTL (Biodiésel HVO)',
            'petrol' => 'Gasolina',
            'naturalgas' => 'Gas natural',
            'propane' => 'Gas propano',
            'butane' => 'Gas butano',
            'electricity' => self::FUEL_ELECTRICITY,
        ],
        'OUTSIDE' => [
            'diesel' => 'Diésel',
            'gasoil' => 'Gasóleo',
            'biodiesel' => 'Biodiésel ME (100% puro)',
            'hvo' => 'XTL (Biodiésel HVO)',
            'petrol' => 'Gasolina',
            'naturalgas' => 'Gas natural',
            'propane' => 'Gas propano',
            'butane' => 'Gas butano',
            'electricity' => self::FUEL_ELECTRICITY,
        ],
    ];

    /**
     * @param array<string, array<string, list<string>>> $availableFuels
     *
     * @return array<string, array<string, list<array{value: string, label: string, units: list<string>}>>>
     */
    public function configuration(array $availableFuels): array
    {
        $configuration = [];
        foreach (self::FACTOR_FUELS as $geography => $factorFuels) {
            foreach (self::MATRIX as $equipment => $fuelCodes) {
                foreach ($fuelCodes as $fuelCode) {
                    $value = $factorFuels[$fuelCode];
                    $units = self::FUEL_ELECTRICITY === $value
                        ? ['kWh']
                        : ($availableFuels[$geography][$value] ?? []);
                    if ([] === $units) {
                        continue;
                    }

                    $configuration[$geography][$equipment][] = [
                        'value' => $value,
                        'label' => self::LABELS[$fuelCode],
                        'units' => $units,
                    ];
                }
            }
        }

        return $configuration;
    }

    public function isAllowed(string $equipment, string $fuel, string $country): bool
    {
        $normalizedCountry = mb_strtoupper(trim($country), 'UTF-8');
        $geography = in_array($normalizedCountry, ['ES', 'ESP', 'ESPAÑA'], true) ? 'ES' : 'OUTSIDE';
        foreach (self::MATRIX[$equipment] ?? [] as $fuelCode) {
            if ($fuel === self::FACTOR_FUELS[$geography][$fuelCode]) {
                return true;
            }
        }

        return false;
    }
}
