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

    /** @var list<string> */
    private const TAXI_SPAIN_VEHICLE_TYPES = [
        'petrol',
        'diesel',
        'hev',
        'lpg',
        'cng',
    ];

    /** @var array<string, list<string>> */
    private const UNITS_BY_METHOD = [
        'distance' => ['km', 'mi'],
        'fuel' => ['L', 'us_gal', 'imp_gal', 'kg'],
        'electricity' => ['kWh', 'MWh'],
        'route' => ['km'],
        'route_stops' => ['km'],
        'passenger_distance' => ['passenger-km', 'passenger-mi'],
        'weight_distance' => ['km', 'mi'],
        'tonne_km' => ['t-km', 't-mi'],
        'route_weight' => ['km'],
        'operator' => ['kg_co2e', 't_co2e'],
    ];

    /** @var list<string> */
    private const WEIGHT_UNITS = ['kg', 't', 'lb', 'short_ton', 'long_ton'];

    /** @var list<string> */
    private const VEHICLE_TYPES = ['petrol', 'diesel', 'hev', 'phev', 'bev', 'lpg', 'cng', 'unknown'];

    /** @var list<string> */
    private const CAR_SIZES = ['small', 'medium', 'large', 'average'];

    /** @var array<string, list<string>> */
    private const FUELS_BY_MODE = [
        'car' => ['petrol', 'diesel', 'lpg', 'cng'],
        'passenger_van' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng'],
        'minibus' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'],
        'motorcycle' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol'],
        'freight_van' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'],
        'rigid_truck' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'],
        'articulated_truck' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'],
    ];

    /** @var list<string> */
    private const PASSENGERS_BY_DISTANCE_MODES = [
        'urban_bus', 'metro', 'tram', 'commuter_train', 'plane', 'long_distance_train', 'coach', 'passenger_ferry',
    ];

    /** @var list<string> */
    private const ORS_ROAD_MODES = ['taxi', 'urban_bus', 'coach'];

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

    /** @return array<string, list<string>> */
    public function carTypeMethods(): array
    {
        return self::CAR_TYPE_METHODS;
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return [
            'categories' => self::CATEGORY_MODES,
            'methodsByMode' => self::MODE_METHODS,
            'carTypeMethods' => self::CAR_TYPE_METHODS,
            'unitsByMethod' => self::UNITS_BY_METHOD,
            'weightUnits' => self::WEIGHT_UNITS,
            'vehicleTypes' => self::VEHICLE_TYPES,
            'taxiSpainVehicleTypes' => self::TAXI_SPAIN_VEHICLE_TYPES,
            'carSizes' => self::CAR_SIZES,
            'fuelsByMode' => self::FUELS_BY_MODE,
            'thermalFuels' => ['petrol', 'diesel'],
            'travelClasses' => ['average', 'economy', 'premium_economy', 'business', 'first'],
            'routeClassifications' => ['domestic', 'international'],
            'tripTypes' => ['one_way', 'round_trip', 'multiple'],
            'passengersByDistanceModes' => self::PASSENGERS_BY_DISTANCE_MODES,
            'orsRoadModes' => self::ORS_ROAD_MODES,
            'unavailableMethods' => [
                'electricity' => 'external_factor_required',
                'fuel_and_electricity' => 'external_factor_required',
                'distance_consumption' => 'unsupported',
            ],
            'secondaryUnits' => [
                'fuel_and_electricity' => ['kWh', 'MWh'],
                'distance_consumption' => ['L/100_km', 'mpg_us', 'mpg_imp', 'kWh/100_km', 'kWh/100_mi'],
            ],
        ];
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

        if ('taxi' === $input->mode
            && 'ES' === strtoupper(trim($input->country))
            && in_array($input->method, ['distance', 'route'], true)
        ) {
            return in_array($input->vehicleType, self::TAXI_SPAIN_VEHICLE_TYPES, true);
        }

        return true;
    }
}
