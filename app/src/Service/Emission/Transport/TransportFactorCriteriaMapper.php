<?php

namespace App\Service\Emission\Transport;

final class TransportFactorCriteriaMapper
{
    private const SPAIN = 'ESPAÑA';
    private const OUTSIDE_SPAIN = 'FUERA DE ESPAÑA';

    /** @var array<string, array{0: string, 1: string}> */
    private const PASSENGER_DISTANCE = [
        'ESPAÑA|urban_bus' => ['PÚBLICO', 'Autobuses (Urbanos y Metropolitanos)'],
        'ESPAÑA|metro' => ['PÚBLICO', 'Metro'],
        'ESPAÑA|tram' => ['PÚBLICO', 'Tren Ligero/Tranvía'],
        'ESPAÑA|commuter_train' => ['PÚBLICO', 'Trenes Cercanías (Urbano y Metropolitano)'],
        'ESPAÑA|long_distance_train' => ['VIAJES', 'Tren RENFE (Larga Distancia)'],
        'ESPAÑA|passenger_ferry' => ['VIAJES', 'Barco de pasajeros promedio (a pie + coche)'],
        'FUERA DE ESPAÑA|urban_bus' => ['PÚBLICO', 'Autobús local (promedio)'],
        'FUERA DE ESPAÑA|metro' => ['PÚBLICO', 'Metro'],
        'FUERA DE ESPAÑA|tram' => ['PÚBLICO', 'Tren Ligero/Tranvía'],
        'FUERA DE ESPAÑA|commuter_train' => ['PÚBLICO', 'Tren (urbano y metropolitano)'],
        'FUERA DE ESPAÑA|long_distance_train' => ['VIAJES', 'Tren (Nacional)'],
        'FUERA DE ESPAÑA|coach' => ['VIAJES', 'Autocar (Larga distancia)'],
        'FUERA DE ESPAÑA|passenger_ferry' => ['VIAJES', 'Barco de pasajeros promedio (a pie + coche)'],
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private const FREIGHT_TONNE_KM = [
        'ESPAÑA|freight_train' => ['Tren', 'Eléctrico'],
        'ESPAÑA|air_freight' => ['Vuelo (internacional)', 'Desconocido'],
        'ESPAÑA|freight_ship' => ['Buque de transporte de mercancías (carga general diversa)', 'Desconocido'],
        'FUERA DE ESPAÑA|freight_train' => ['Tren de mercancías', 'Desconocido'],
        'FUERA DE ESPAÑA|air_freight' => ['Vuelo (internacional)', 'Desconocido'],
        'FUERA DE ESPAÑA|freight_ship' => ['Buque (carga general diversa)', 'Desconocido'],
    ];

    public function __construct(private readonly TransportUiCatalog $uiCatalog = new TransportUiCatalog())
    {
    }

    public function map(TransportEmissionInput $input): ?TransportFactorMapping
    {
        if (!$this->supportsUiCombination($input)) {
            return null;
        }

        $area = $this->area($input->country);

        if ('fuel' === $input->method) {
            return $this->mapFuel($input, $area);
        }

        if (!in_array($input->method, ['distance', 'route', 'route_stops', 'passenger_distance', 'weight_distance', 'tonne_km', 'route_weight'], true)) {
            return null;
        }

        if ('car' === $input->mode && in_array($input->method, ['distance', 'route', 'route_stops'], true)) {
            return $this->mapCarDistance($input, $area);
        }

        if ('taxi' === $input->mode && in_array($input->method, ['distance', 'route', 'route_stops'], true)) {
            if (self::OUTSIDE_SPAIN === $area) {
                return $this->criteria($area, 'PÚBLICO', 'Taxi regular', 'Desconocido', 'km');
            }

            return $this->mapSpanishCarDistance($input, $area);
        }

        if ('motorcycle' === $input->mode
            && self::OUTSIDE_SPAIN === $area
            && in_array($input->method, ['distance', 'route', 'route_stops'], true)
        ) {
            return $this->criteria($area, 'PRIVADO', 'Moto promedio (tamaño del motor desconocido)', 'Gasolina', 'km');
        }

        if ('passenger_van' === $input->mode && in_array($input->method, ['distance', 'route', 'route_stops'], true)) {
            return $this->criteria($area, 'PRIVADO', 'Vans (pasajeros)', 'Desconocido', 'km');
        }

        if (in_array($input->mode, ['urban_bus', 'metro', 'tram', 'commuter_train', 'long_distance_train', 'coach', 'passenger_ferry', 'plane'], true)
            && in_array($input->method, ['distance', 'route', 'route_stops', 'passenger_distance'], true)
        ) {
            return $this->mapPassengerDistance($input, $area);
        }

        if (in_array($input->mode, ['freight_van', 'rigid_truck', 'articulated_truck'], true)
            && in_array($input->method, ['distance', 'route', 'route_stops', 'weight_distance', 'tonne_km', 'route_weight'], true)
        ) {
            return $this->mapRoadFreight($input, $area);
        }

        if (isset(self::FREIGHT_TONNE_KM[$area.'|'.$input->mode])
            && in_array($input->method, ['weight_distance', 'tonne_km', 'route_weight'], true)
        ) {
            [$activity, $fuel] = self::FREIGHT_TONNE_KM[$area.'|'.$input->mode];

            return $this->criteria($area, 'MERCANCÍAS', $activity, $fuel, 'km*tonelada');
        }

        return null;
    }

    public function supportsUiCombination(TransportEmissionInput $input): bool
    {
        return $this->uiCatalog->supports($input);
    }

    private function mapPassengerDistance(TransportEmissionInput $input, string $area): ?TransportFactorMapping
    {
        if ('plane' === $input->mode) {
            $route = $input->routeClassification;
            if ('route' === $input->method && null === $route) {
                return null;
            }
            if (null === $route || 'domestic' === $route) {
                $activity = 'Vuelo (Nacional pasajero promedio)';
            } elseif ('international' === $route) {
                $suffix = match ($input->travelClass ?? 'average') {
                    'average' => 'pasajero promedio',
                    'economy' => 'clase económica',
                    'premium_economy' => 'clase económica Premium',
                    'business' => 'clase ejecutiva',
                    'first' => 'clase primera',
                    default => null,
                };
                if (null === $suffix) {
                    return null;
                }
                $activity = 'Vuelo (Internacional '.$suffix.')';
            } else {
                return null;
            }

            return $this->criteria($area, 'VIAJES', $activity, 'Desconocido', 'km*pasajero');
        }

        if (self::SPAIN === $area && 'coach' === $input->mode) {
            return $this->criteria($area, 'VIAJES', 'Autocar (larga distancia)', 'Gasóleo', 'km*pasajero');
        }

        $mapping = self::PASSENGER_DISTANCE[$area.'|'.$input->mode] ?? null;
        if (null === $mapping) {
            return null;
        }

        return $this->criteria($area, $mapping[0], $mapping[1], 'Desconocido', 'km*pasajero');
    }

    private function mapRoadFreight(TransportEmissionInput $input, string $area): ?TransportFactorMapping
    {
        $isTonneKm = in_array($input->method, ['weight_distance', 'tonne_km', 'route_weight'], true);
        if (self::SPAIN === $area && $isTonneKm) {
            return null;
        }

        if ('freight_van' === $input->mode) {
            return $this->criteria(
                $area,
                'MERCANCÍAS',
                'Furgonetas y furgones Promedio (< 3,5 tn)',
                self::SPAIN === $area ? 'Gasóleo' : ($isTonneKm ? 'Desconocido' : 'Diésel'),
                $isTonneKm ? 'km*tonelada' : 'km',
            );
        }

        return $this->criteria(
            $area,
            'MERCANCÍAS',
            self::SPAIN === $area
                ? 'Vehículos pesados (> 3,5 tn ) (sin carga)'
                : 'Vehículos Pesados (> 3,5 tn) (Carga media)',
            self::SPAIN === $area ? 'Gasóleo' : 'Diésel',
            $isTonneKm ? 'km*tonelada' : 'km',
        );
    }

    private function mapCarDistance(TransportEmissionInput $input, string $area): ?TransportFactorMapping
    {
        if (self::SPAIN === $area) {
            return $this->mapSpanishCarDistance($input, $area);
        }

        $type = $input->vehicleType;
        $size = $input->carSize ?? 'average';
        $rows = [
            'average|petrol' => ['Coche promedio (tamaño del motor desconocido)', 'Gasolina'],
            'average|diesel' => ['Coche promedio (tamaño del motor desconocido)', 'Diésel'],
            'average|hev' => ['Coche promedio (tamaño del motor desconocido)', 'Híbrido'],
            'average|lpg' => ['Coche promedio (tamaño del motor desconocido)', 'GLP'],
            'average|cng' => ['Coche promedio (tamaño del motor desconocido)', 'GNC'],
            'average|phev' => ['Coche promedio (tamaño del motor desconocido)', 'Eléctrico híbrido enchufable*'],
            'average|bev' => ['Coche promedio (tamaño del motor desconocido)', 'Eléctrico de batería*'],
            'small|petrol' => ['Coche pequeño (< 1.400 cc)', 'Gasolina'],
            'small|diesel' => ['Coche pequeño (< 1.700 cc)', 'Diésel'],
            'small|hev' => ['Coche pequeño (< 1.700 cc)', 'Híbrido'],
            'small|phev' => ['Coche pequeño (< 1.700 cc)', 'Eléctrico híbrido enchufable'],
            'small|bev' => ['Coche pequeño (< 1.700 cc)', 'Eléctrico de batería'],
            'medium|petrol' => ['Coche mediano (1.400 - 2.000 cc)', 'Gasolina'],
            'medium|lpg' => ['Coche mediano (1.400 - 2.000 cc)', 'GLP'],
            'medium|cng' => ['Coche mediano (1.400 - 2.000 cc)', 'GNC'],
            'medium|diesel' => ['Coche mediano (1.700 - 2.000 cc)', 'Diésel'],
            'medium|hev' => ['Coche mediano (1.700 - 2.000 cc)', 'Híbrido'],
            'medium|phev' => ['Coche mediano (1.700 - 2.000 cc)', 'Eléctrico híbrido enchufable'],
            'medium|bev' => ['Coche mediano (1.700 - 2.000 cc)', 'Eléctrico de batería'],
            'large|petrol' => ['Coche grande (> 2.000 cc)', 'Gasolina'],
            'large|diesel' => ['Coche grande (> 2.000 cc)', 'Diésel'],
            'large|hev' => ['Coche grande (> 2.000 cc)', 'Híbrido'],
            'large|lpg' => ['Coche grande (> 2.000 cc)', 'GLP'],
            'large|cng' => ['Coche grande (> 2.000 cc)', 'GNC'],
            'large|phev' => ['Coche grande (> 2.000 cc)', 'Eléctrico híbrido enchufable'],
            'large|bev' => ['Coche grande (> 2.000 cc)', 'Eléctrico de batería'],
        ];
        $row = $rows[$size.'|'.$type] ?? null;

        return null === $row ? null : $this->criteria($area, 'PRIVADO', $row[0], $row[1], 'km');
    }

    private function mapSpanishCarDistance(TransportEmissionInput $input, string $area): ?TransportFactorMapping
    {
        $fuel = match ($input->vehicleType) {
            'petrol' => 'Gasolina',
            'diesel' => 'Gasóleo',
            'hev' => 'Híbrido',
            'lpg' => 'LPG',
            'cng' => 'CNG',
            default => null,
        };

        return null === $fuel
            ? null
            : $this->criteria($area, 'PRIVADO', 'Turismos/ Taxis (hasta 8 asientos)', $fuel, 'km');
    }

    private function mapFuel(TransportEmissionInput $input, string $area): ?TransportFactorMapping
    {
        if (self::OUTSIDE_SPAIN === $area && 'taxi' === $input->mode) {
            return null;
        }

        $activity = match ($input->mode) {
            'car', 'taxi' => self::SPAIN === $area ? 'Turismos (hasta 8 asientos) / Taxis' : 'Coche promedio',
            'passenger_van' => 'Vans (pasajeros)',
            'urban_bus' => self::SPAIN === $area ? 'Autobuses (urbanos y metropolitanos)' : 'Autobús Local (urbano y metropolitano)',
            'commuter_train' => 'Tren (Cercanías)',
            'coach' => self::SPAIN === $area ? 'Autocar (larga distancia)' : 'Autocar (coach) / Minibús',
            'plane' => 'Avión',
            'passenger_ferry' => 'Barco',
            'motorcycle' => self::OUTSIDE_SPAIN === $area ? 'Moto promedio' : null,
            'freight_van' => self::SPAIN === $area ? 'Furgonetas y furgones (< 3,5 tn)' : 'Furgoneta / Camión (< 3,5 Tn)',
            'rigid_truck', 'articulated_truck' => self::SPAIN === $area ? 'Camiones (> 3,5 tn)' : 'Vehículo pesado (> 3,5 Tn)',
            default => null,
        };
        if (null === $activity) {
            return null;
        }

        $fuelAlias = in_array($input->vehicleType, ['hev', 'phev'], true) ? $input->thermalFuel : $input->fuel;
        if (null === $fuelAlias) {
            return null;
        }

        if (in_array($input->vehicleType, ['hev', 'phev'], true)) {
            $fuel = match ($input->thermalFuel) {
                'petrol' => match ($input->mode) {
                    'car', 'taxi' => 'Híbrido gasolina',
                    'passenger_van' => self::SPAIN === $area ? 'Híbrido gasolina' : 'Híbrida gasolina',
                    'freight_van' => self::SPAIN === $area ? 'Híbrida gasolina' : 'Híbrido gasolina',
                    default => null,
                },
                'diesel' => match ($input->mode) {
                    'car', 'taxi' => 'Híbrido diésel',
                    'passenger_van' => self::SPAIN === $area ? 'Híbrido diésel' : 'Híbrida diésel',
                    'freight_van' => self::SPAIN === $area ? 'Híbrida diésel' : 'Híbrido diésel',
                    default => null,
                },
                default => null,
            };
            if (null === $fuel || 'phev' === $input->vehicleType) {
                return null;
            }
        } else {
            $fuel = $this->fuelName($area, $input->mode, $fuelAlias);
            if (null === $fuel) {
                return null;
            }
        }

        $unit = self::SPAIN === $area && in_array($fuelAlias, ['cng', 'lng'], true) ? 'kg' : 'litros';

        return $this->criteria($area, $this->subcategoryForFuelMode($input->mode), $activity, $fuel, $unit, 'combustible');
    }

    private function fuelName(string $area, string $mode, string $alias): ?string
    {
        if (self::OUTSIDE_SPAIN === $area) {
            $common = [
                'petrol' => 'Gasolina',
                'diesel' => 'Diésel',
                'hvo' => 'XTL (Biodiésel HVO)',
                'biodiesel' => 'Biodiésel',
                'bioethanol' => 'Bioetanol',
            ];
            $allowed = match ($mode) {
                'car', 'taxi', 'coach' => $common,
                'passenger_van', 'freight_van' => $common + ['lpg' => 'LPG', 'cng' => 'CNG'],
                'urban_bus' => ['diesel' => 'Diésel'],
                'commuter_train', 'passenger_ferry' => ['diesel' => 'Gasóleo'],
                'plane' => ['petrol' => 'Gasolina'],
                'motorcycle' => $common,
                'rigid_truck', 'articulated_truck' => $common + ['lpg' => 'LPG'],
                default => [],
            };

            return $allowed[$alias] ?? null;
        }

        $common = [
            'petrol' => 'Gasolina',
            'diesel' => 'Diésel',
            'hvo' => 'XTL (Biodiésel HVO)',
            'biodiesel' => 'Biodiésel 100% (B100)',
            'bioethanol' => 'Bioetanol 100% (E100)',
        ];
        $allowed = match ($mode) {
            'car', 'taxi', 'passenger_van' => $common + ['lpg' => 'GLP', 'cng' => 'CNG'],
            'urban_bus', 'coach' => $common + ['lpg' => 'LPG', 'cng' => 'CNG', 'lng' => 'LNG'],
            'commuter_train', 'passenger_ferry' => ['diesel' => 'Gasóleo'],
            'plane' => ['petrol' => 'Gasolina'],
            'freight_van' => $common + ['lpg' => 'LPG', 'cng' => 'CNG', 'lng' => 'LNG'],
            'rigid_truck', 'articulated_truck' => [
                'petrol' => 'Gasolina',
                'diesel' => 'Diésel',
                'hvo' => 'Biodiésel HVO',
                'biodiesel' => 'Biodiésel E100',
                'bioethanol' => 'Bioetanol (E100)',
                'lpg' => 'LPG',
                'cng' => 'CNG',
                'lng' => 'LNG',
            ],
            default => [],
        };

        return $allowed[$alias] ?? null;
    }

    private function subcategoryForFuelMode(string $mode): string
    {
        return match ($mode) {
            'car', 'taxi', 'passenger_van', 'motorcycle' => 'PRIVADO',
            'urban_bus', 'commuter_train' => 'PÚBLICO',
            'coach', 'plane', 'passenger_ferry' => 'VIAJES',
            default => 'MERCANCÍAS',
        };
    }

    private function area(string $country): string
    {
        return 'ES' === strtoupper(trim($country)) ? self::SPAIN : self::OUTSIDE_SPAIN;
    }

    private function criteria(
        string $area,
        string $subcategory,
        string $activity,
        string $fuel,
        string $unit,
        string $method = 'distancia',
    ): TransportFactorMapping {
        return new TransportFactorMapping([
            'area' => $area,
            'subcategory' => $subcategory,
            'activity' => $activity,
            'fuel' => $fuel,
            'unit' => $unit,
            'method' => $method,
        ]);
    }
}
