<?php

namespace App\Service\Emission\Transport;

final class TransportUiCatalog
{
    /** @var array<string, list<string>> */
    private const CATEGORY_MODES = [
        'local' => ['car', 'taxi', 'passenger_van', 'minibus', 'urban_bus', 'metro', 'tram', 'commuter_train', 'motorcycle', 'bicycle', 'scooter', 'walk'],
        'travel' => ['plane', 'long_distance_train', 'coach', 'passenger_ferry'],
        'freight' => ['freight_van', 'rigid_truck', 'articulated_truck', 'freight_train', 'air_freight', 'freight_ship', 'courier', 'cargo_bike'],
    ];

    /** @var array<string, list<string>> */
    private const MODE_METHODS = [
        'car' => ['fuel', 'electricity', 'distance', 'distance_consumption', 'fuel_and_electricity'],
        'taxi' => ['distance', 'operator', 'route'],
        'passenger_van' => ['fuel', 'electricity', 'distance', 'distance_consumption'],
        'minibus' => ['fuel', 'electricity', 'distance', 'distance_consumption'],
        'urban_bus' => ['distance', 'route', 'route_stops', 'operator'],
        'metro' => ['route', 'route_stops', 'passenger_distance', 'operator'],
        'tram' => ['route', 'route_stops', 'passenger_distance', 'operator'],
        'commuter_train' => ['route', 'passenger_distance', 'operator'],
        'motorcycle' => ['fuel', 'electricity', 'distance'],
        'bicycle' => ['distance', 'electricity'],
        'scooter' => ['distance', 'electricity'],
        'walk' => ['distance'],
        'plane' => ['route', 'passenger_distance', 'operator'],
        'long_distance_train' => ['route', 'passenger_distance', 'operator'],
        'coach' => ['route', 'distance', 'operator'],
        'passenger_ferry' => ['route', 'passenger_distance', 'operator'],
        'freight_van' => ['fuel', 'electricity', 'distance', 'weight_distance'],
        'rigid_truck' => ['fuel', 'distance', 'weight_distance', 'tonne_km'],
        'articulated_truck' => ['fuel', 'distance', 'weight_distance', 'tonne_km'],
        'freight_train' => ['weight_distance', 'tonne_km', 'route'],
        'air_freight' => ['route_weight', 'weight_distance', 'tonne_km'],
        'freight_ship' => ['weight_distance', 'tonne_km', 'route'],
        'courier' => ['weight_distance', 'operator'],
        'cargo_bike' => ['weight_distance', 'electricity'],
    ];

    /** @var array<string, list<string>> */
    private const CAR_TYPE_METHODS = [
        'petrol' => ['distance', 'fuel', 'distance_consumption'],
        'diesel' => ['distance', 'fuel', 'distance_consumption'],
        'lpg' => ['distance', 'fuel', 'distance_consumption'],
        'cng' => ['distance', 'fuel', 'distance_consumption'],
        'hev' => ['distance', 'fuel', 'distance_consumption'],
        'bev' => ['distance', 'electricity', 'distance_consumption'],
        'phev' => ['distance', 'fuel', 'electricity', 'fuel_and_electricity', 'distance_consumption'],
        'unknown' => ['distance'],
    ];

    /** @return array<string, list<string>> */
    public function categories(): array
    {
        return self::CATEGORY_MODES;
    }

    /** @return array<string, list<string>> */
    public function methodsByMode(): array
    {
        return self::MODE_METHODS;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::MODE_METHODS))));
    }

    public function supports(TransportEmissionInput $input): bool
    {
        if (!in_array($input->mode, self::CATEGORY_MODES[$input->category] ?? [], true)
            || !in_array($input->method, self::MODE_METHODS[$input->mode] ?? [], true)
        ) {
            return false;
        }

        if ('car' === $input->mode) {
            return in_array($input->method, self::CAR_TYPE_METHODS[$input->vehicleType] ?? [], true);
        }

        return true;
    }
}
