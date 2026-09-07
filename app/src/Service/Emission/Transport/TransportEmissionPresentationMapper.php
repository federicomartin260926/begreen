<?php

namespace App\Service\Emission\Transport;

use Symfony\Component\HttpFoundation\Request;

final class TransportEmissionPresentationMapper
{
    private const TEXT_FIELDS = [
        'origin' => 250,
        'destination' => 250,
        'tripType' => 20,
        'stops' => 2000,
        'operatorReference' => 500,
        'secondaryActivityValue' => 50,
        'secondaryActivityUnit' => 30,
    ];

    private const COORDINATES = [
        'originLatitude' => [-90.0, 90.0],
        'originLongitude' => [-180.0, 180.0],
        'destinationLatitude' => [-90.0, 90.0],
        'destinationLongitude' => [-180.0, 180.0],
    ];

    /** @return array<string, string> */
    public function map(Request $request): array
    {
        $method = $request->request->get('method');
        $allowedFields = match ($method) {
            'route' => ['origin', 'destination', 'originLatitude', 'originLongitude', 'destinationLatitude', 'destinationLongitude', 'tripType'],
            'route_stops' => ['origin', 'destination', 'originLatitude', 'originLongitude', 'destinationLatitude', 'destinationLongitude', 'stops'],
            'route_weight' => ['origin', 'destination', 'originLatitude', 'originLongitude', 'destinationLatitude', 'destinationLongitude'],
            'operator' => ['operatorReference'],
            'fuel_and_electricity', 'distance_consumption' => ['secondaryActivityValue', 'secondaryActivityUnit'],
            default => [],
        };
        $requiredFields = match ($method) {
            'route' => ['origin', 'destination', 'tripType'],
            'route_stops', 'route_weight' => ['origin', 'destination'],
            'operator' => ['operatorReference'],
            default => [],
        };

        $presentation = [];
        foreach (self::TEXT_FIELDS as $field => $maximumLength) {
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }
            $value = $this->optionalString($request, $field);
            if (null === $value) {
                continue;
            }
            if (mb_strlen($value) > $maximumLength) {
                throw new \InvalidArgumentException(sprintf('%s is too long.', $field));
            }
            $presentation[$field] = $value;
        }

        if (isset($presentation['tripType']) && !in_array($presentation['tripType'], ['one_way', 'round_trip', 'multiple'], true)) {
            throw new \InvalidArgumentException('Invalid trip type.');
        }

        foreach ($requiredFields as $field) {
            if (!isset($presentation[$field])) {
                throw new \InvalidArgumentException(sprintf('%s is required.', $field));
            }
        }

        foreach (self::COORDINATES as $field => [$minimum, $maximum]) {
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }
            $value = $this->optionalString($request, $field);
            if (null === $value) {
                continue;
            }
            if (!is_numeric($value) || (float) $value < $minimum || (float) $value > $maximum) {
                throw new \InvalidArgumentException(sprintf('Invalid %s.', $field));
            }
            $presentation[$field] = $value;
        }

        if (isset($presentation['secondaryActivityValue'])
            && !preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $presentation['secondaryActivityValue'])
        ) {
            throw new \InvalidArgumentException('Invalid secondary activity value.');
        }
        $allowedSecondaryUnits = match ($method) {
            'fuel_and_electricity' => ['kWh', 'MWh'],
            'distance_consumption' => ['L/100_km', 'mpg_us', 'mpg_imp', 'kWh/100_km', 'kWh/100_mi'],
            default => [],
        };
        if (isset($presentation['secondaryActivityUnit'])
            && !in_array($presentation['secondaryActivityUnit'], $allowedSecondaryUnits, true)
        ) {
            throw new \InvalidArgumentException('Invalid secondary activity unit.');
        }

        return $presentation;
    }

    private function optionalString(Request $request, string $field): ?string
    {
        $value = $request->request->get($field);
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
