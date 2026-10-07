<?php

namespace App\Service\Emission\Transport;

final class TransportUiCatalog
{
    /** @var array<string, list<string>> */
    private const CATEGORY_MODES = [
        'local' => ['car', 'taxi', 'passenger_van', 'urban_bus', 'metro', 'tram', 'commuter_train', 'motorcycle', 'bicycle', 'scooter', 'walk'],
        'travel' => ['plane', 'long_distance_train', 'coach', 'passenger_ferry'],
        'freight' => ['freight_van', 'rigid_truck', 'articulated_truck', 'freight_train', 'air_freight', 'freight_ship', 'courier'],
    ];

    /** @var array<string, list<string>> */
    private const MODE_METHODS = [
        'car' => ['fuel', 'distance'],
        'taxi' => ['distance', 'operator', 'route'],
        'passenger_van' => ['fuel', 'distance'],
        'urban_bus' => ['distance', 'route', 'route_stops', 'operator'],
        'metro' => ['passenger_distance', 'operator'],
        'tram' => ['passenger_distance', 'operator'],
        'commuter_train' => ['passenger_distance', 'operator'],
        'motorcycle' => ['fuel', 'distance'],
        'bicycle' => ['distance'],
        'scooter' => ['distance'],
        'walk' => ['distance'],
        'plane' => ['passenger_distance', 'operator'],
        'long_distance_train' => ['passenger_distance', 'operator'],
        'coach' => ['distance', 'route', 'operator'],
        'passenger_ferry' => ['passenger_distance', 'operator'],
        'freight_van' => ['fuel', 'distance', 'weight_distance', 'tonne_km'],
        'rigid_truck' => ['fuel', 'distance', 'weight_distance', 'tonne_km'],
        'articulated_truck' => ['fuel', 'distance', 'weight_distance', 'tonne_km'],
        'freight_train' => ['weight_distance', 'tonne_km'],
        'air_freight' => ['weight_distance', 'tonne_km'],
        'freight_ship' => ['weight_distance', 'tonne_km'],
        'courier' => ['operator'],
    ];

    /** @var array<string, list<string>> */
    private const CAR_TYPE_METHODS = [
        'petrol' => ['distance', 'fuel'],
        'diesel' => ['distance', 'fuel'],
        'lpg' => ['distance', 'fuel'],
        'cng' => ['distance', 'fuel'],
        'hev' => ['distance', 'fuel'],
        'bev' => ['distance'],
        'phev' => ['distance'],
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

    /** @var list<string> */
    private const CAR_SPAIN_VEHICLE_TYPES = [
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
        'route' => ['km'],
        'route_stops' => ['km'],
        'passenger_distance' => ['passenger-km', 'passenger-mi'],
        'weight_distance' => ['km', 'mi'],
        'tonne_km' => ['t-km', 't-mi'],
        'operator' => ['kg_co2e', 't_co2e'],
    ];

    /** @var list<string> */
    private const WEIGHT_UNITS = ['kg', 't', 'lb', 'short_ton', 'long_ton'];

    /** @var list<string> */
    private const VEHICLE_TYPES = ['petrol', 'diesel', 'hev', 'phev', 'bev', 'lpg', 'cng', 'unknown'];

    /** @var list<string> */
    private const CAR_SIZES = ['small', 'medium', 'large', 'average'];

    /** @var list<string> */
    private const THERMAL_FUELS = ['petrol', 'diesel'];

    /** @var array<string, list<string>> */
    private const FUELS_BY_MODE = [
        'car' => ['petrol', 'diesel', 'lpg', 'cng'],
        'passenger_van' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng'],
        'motorcycle' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol'],
        'freight_van' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'],
        'rigid_truck' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'],
        'articulated_truck' => ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'],
    ];

    /** @var list<string> */
    private const OUTSIDE_SPAIN_ONLY_MODES = ['motorcycle'];

    /** @var array<string, list<string>> */
    private const SPAIN_ONLY_FUELS_BY_MODE = [
        'freight_van' => ['lng'],
        'rigid_truck' => ['cng', 'lng'],
        'articulated_truck' => ['cng', 'lng'],
    ];

    /** @var list<string> */
    private const PASSENGERS_BY_DISTANCE_MODES = [
        'urban_bus',
        'coach',
    ];

    /** @var list<string> */
    private const PASSENGER_MODES = [
        'car',
        'taxi',
        'passenger_van',
        'urban_bus',
        'metro',
        'tram',
        'commuter_train',
        'motorcycle',
        'bicycle',
        'scooter',
        'walk',
        'plane',
        'long_distance_train',
        'coach',
        'passenger_ferry',
    ];

    /** @var list<string> */
    private const ACCOMPANIMENT_MODES = ['car', 'taxi', 'passenger_van'];

    /** @var list<string> */
    private const ORS_ROAD_MODES = ['taxi', 'urban_bus', 'coach'];

    /** @var array<string, list<string>> */
    private const SPAIN_ONLY_METHODS_BY_MODE = [
        'taxi' => ['route'],
        'urban_bus' => ['route', 'route_stops'],
    ];

    /** @var array<string, list<string>> */
    private const OUTSIDE_SPAIN_ONLY_METHODS_BY_MODE = [
        'freight_van' => ['weight_distance', 'tonne_km'],
        'rigid_truck' => ['weight_distance', 'tonne_km'],
        'articulated_truck' => ['weight_distance', 'tonne_km'],
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

    /** @return array<string, list<string>> */
    public function carTypeMethods(): array
    {
        return self::CAR_TYPE_METHODS;
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $uiMethodsByMode = self::MODE_METHODS;
        $uiMethodsByMode['freight_van'] = array_values(array_filter(
            $uiMethodsByMode['freight_van'],
            static fn (string $method): bool => 'tonne_km' !== $method,
        ));

        return [
            'categories' => self::CATEGORY_MODES,
            'methodsByMode' => $uiMethodsByMode,
            'carTypeMethods' => self::CAR_TYPE_METHODS,
            'unitsByMethod' => self::UNITS_BY_METHOD,
            'weightUnits' => self::WEIGHT_UNITS,
            'vehicleTypes' => self::VEHICLE_TYPES,
            'carSpainVehicleTypes' => self::CAR_SPAIN_VEHICLE_TYPES,
            'taxiSpainVehicleTypes' => self::TAXI_SPAIN_VEHICLE_TYPES,
            'carSizes' => self::CAR_SIZES,
            'fuelsByMode' => self::FUELS_BY_MODE,
            'outsideSpainOnlyModes' => self::OUTSIDE_SPAIN_ONLY_MODES,
            'spainOnlyFuelsByMode' => self::SPAIN_ONLY_FUELS_BY_MODE,
            'thermalFuels' => self::THERMAL_FUELS,
            'tripTypes' => ['one_way', 'round_trip', 'multiple'],
            'passengersByDistanceModes' => self::PASSENGERS_BY_DISTANCE_MODES,
            'passengerModes' => self::PASSENGER_MODES,
            'accompanimentModes' => self::ACCOMPANIMENT_MODES,
            'accompanimentOptions' => ['solo', 'accompanied'],
            'orsRoadModes' => self::ORS_ROAD_MODES,
            'spainOnlyMethodsByMode' => self::SPAIN_ONLY_METHODS_BY_MODE,
            'outsideSpainOnlyMethodsByMode' => self::OUTSIDE_SPAIN_ONLY_METHODS_BY_MODE,
        ];
    }

    public function supports(TransportEmissionInput $input): bool
    {
        if (!in_array($input->mode, self::CATEGORY_MODES[$input->category] ?? [], true)
            || !in_array($input->method, self::MODE_METHODS[$input->mode] ?? [], true)
        ) {
            return false;
        }

        $isSpain = 'ES' === strtoupper(trim($input->country));
        if ($isSpain && in_array($input->mode, self::OUTSIDE_SPAIN_ONLY_MODES, true)) {
            return false;
        }

        if ('car' === $input->mode) {
            if ('ES' === strtoupper(trim($input->country))
                && !in_array($input->vehicleType, self::CAR_SPAIN_VEHICLE_TYPES, true)
            ) {
                return false;
            }

            if (!in_array($input->method, self::CAR_TYPE_METHODS[$input->vehicleType] ?? [], true)) {
                return false;
            }

            if ('fuel' !== $input->method) {
                return true;
            }

            return 'hev' === $input->vehicleType
                ? in_array($input->thermalFuel, self::THERMAL_FUELS, true)
                : in_array($input->fuel, self::FUELS_BY_MODE['car'], true);
        }

        if (!$isSpain && in_array($input->method, self::SPAIN_ONLY_METHODS_BY_MODE[$input->mode] ?? [], true)) {
            return false;
        }
        if ($isSpain && in_array($input->method, self::OUTSIDE_SPAIN_ONLY_METHODS_BY_MODE[$input->mode] ?? [], true)) {
            return false;
        }

        if ('taxi' === $input->mode
            && 'ES' === strtoupper(trim($input->country))
            && in_array($input->method, ['distance', 'route'], true)
        ) {
            return in_array($input->vehicleType, self::TAXI_SPAIN_VEHICLE_TYPES, true);
        }

        if ('fuel' === $input->method) {
            if (!in_array($input->fuel, self::FUELS_BY_MODE[$input->mode] ?? [], true)) {
                return false;
            }
            if (!$isSpain && in_array($input->fuel, self::SPAIN_ONLY_FUELS_BY_MODE[$input->mode] ?? [], true)) {
                return false;
            }
        }

        return true;
    }
}
