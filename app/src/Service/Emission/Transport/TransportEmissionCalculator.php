<?php

namespace App\Service\Emission\Transport;

use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;

final readonly class TransportEmissionCalculator
{
    public const DECIMAL_SCALE = 18;
    private const TRANSPORT_CATEGORY_KEY = 'transport';

    public function __construct(
        private TransportFactorCriteriaMapper $mapper,
        private EmissionFactorResolver $factorResolver,
        private EmissionFactorKeyGenerator $keyGenerator,
    ) {
    }

    public function calculate(TransportEmissionInput $input): TransportEmissionResult
    {
        $year = (int) $input->startDate->format('Y');
        $this->positiveInteger($input->repetitions, 'repetitions');

        if (!$this->mapper->supportsUiCombination($input)) {
            return $this->directResult(TransportEmissionResult::STATUS_UNSUPPORTED, $year);
        }

        if ('distance_consumption' === $input->method) {
            return $this->directResult(TransportEmissionResult::STATUS_UNSUPPORTED, $year);
        }

        if (in_array($input->method, ['electricity', 'fuel_and_electricity'], true)) {
            return $this->directResult(TransportEmissionResult::STATUS_EXTERNAL_FACTOR_REQUIRED, $year);
        }

        if ('fuel' === $input->method && in_array($input->activityUnit, ['m3', 'm³'], true)) {
            return $this->directResult(TransportEmissionResult::STATUS_UNSUPPORTED, $year);
        }

        $this->validateActivityUnit($input);

        if ('operator' === $input->method) {
            $kg = $this->multiply(
                $this->normalizeOperatorEmission($input->activityValue, $input->activityUnit),
                $this->positiveInteger($input->repetitions, 'repetitions'),
            );

            return new TransportEmissionResult(
                TransportEmissionResult::STATUS_DIRECT_OPERATOR_EMISSION,
                $kg,
                'kg CO2e',
                $kg,
                null,
                null,
                $year,
                source: 'operator',
            );
        }

        if ('distance' === $input->method && in_array($input->mode, ['walk', 'bicycle', 'scooter'], true)) {
            $distance = $this->normalizeRouteDistance($input->activityValue, $input->activityUnit);

            return new TransportEmissionResult(
                TransportEmissionResult::STATUS_DIRECT_ZERO,
                $this->multiply($distance, $this->positiveInteger($input->repetitions, 'repetitions')),
                'km',
                '0',
                null,
                null,
                $year,
                source: 'operational_zero',
            );
        }

        $mapping = $this->mapper->map($input);
        if (null === $mapping) {
            return $this->directResult(TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE, $year);
        }

        [$amount, $unit] = $this->normalizeFunctionalAmount($input, $mapping->criteria['unit']);
        $functionalKey = $this->keyGenerator->generate($mapping->criteria);
        $resolution = $this->factorResolver->resolve(self::TRANSPORT_CATEGORY_KEY, $mapping->criteria, $year);
        if (!$resolution->hasFactor()) {
            return new TransportEmissionResult(
                TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE,
                $amount,
                $unit,
                null,
                $mapping->criteria,
                $functionalKey,
                $year,
            );
        }

        $factor = $resolution->factor;
        if (null === $factor?->getValue()) {
            return new TransportEmissionResult(
                TransportEmissionResult::STATUS_EXPLICIT_NULL_FACTOR,
                $amount,
                $unit,
                null,
                $mapping->criteria,
                $functionalKey,
                $year,
                $resolution->factorYear,
                null,
                $factor?->getUnit(),
                $factor?->getSource(),
                $factor?->getSourceDetail(),
                $resolution->isFallback,
                $resolution->fallbackReason,
            );
        }

        return new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED,
            $amount,
            $unit,
            $this->multiply($amount, $factor->getValue()),
            $mapping->criteria,
            $functionalKey,
            $year,
            $resolution->factorYear,
            $factor->getValue(),
            $factor->getUnit(),
            $factor->getSource(),
            $factor->getSourceDetail(),
            $resolution->isFallback,
            $resolution->fallbackReason,
        );
    }

    private function normalizeFunctionalAmount(TransportEmissionInput $input, string $factorUnit): array
    {
        $repetitions = $this->positiveInteger($input->repetitions, 'repetitions');

        if ('fuel' === $input->method) {
            $amount = $this->normalizeFuel($input->activityValue, $input->activityUnit, $factorUnit);

            return [$this->multiply($amount, $repetitions), $factorUnit];
        }

        if ('tonne_km' === $input->method) {
            $amount = $this->positiveNumber($input->activityValue, 'activityValue');
            if ('t-mi' === $input->activityUnit) {
                $amount = $this->multiply($amount, '1.609344');
            } elseif ('t-km' !== $input->activityUnit) {
                throw new \InvalidArgumentException('Unsupported tonne-kilometre unit.');
            }

            return [$this->multiply($amount, $repetitions), 'km*tonelada'];
        }

        if (in_array($input->method, ['weight_distance', 'route_weight'], true)) {
            $distance = $this->normalizeRouteDistance($input->activityValue, $input->activityUnit);
            if (null === $input->weightValue || null === $input->weightUnit) {
                throw new \InvalidArgumentException('Weight and weight unit are required.');
            }
            $tonnes = $this->normalizeWeight($input->weightValue, $input->weightUnit);

            return [$this->multiply($this->multiply($distance, $tonnes), $repetitions), 'km*tonelada'];
        }

        $distance = 'passenger_distance' === $input->method
            ? $this->normalizePassengerDistance($input->activityValue, $input->activityUnit)
            : $this->normalizeRouteDistance($input->activityValue, $input->activityUnit);
        if ('km*pasajero' === $factorUnit && 'passenger_distance' !== $input->method) {
            if (null === $input->passengers) {
                throw new \InvalidArgumentException('Passengers are required for a passenger-kilometre factor.');
            }
            $distance = $this->multiply($distance, $this->positiveInteger($input->passengers, 'passengers'));
        }

        return [$this->multiply($distance, $repetitions), $factorUnit];
    }

    private function normalizeRouteDistance(string $value, string $unit): string
    {
        $distance = $this->positiveNumber($value, 'activityValue');

        return match ($unit) {
            'km' => $distance,
            'mi' => $this->multiply($distance, '1.609344'),
            default => throw new \InvalidArgumentException('Unsupported distance unit.'),
        };
    }

    private function normalizePassengerDistance(string $value, string $unit): string
    {
        $distance = $this->positiveNumber($value, 'activityValue');

        return match ($unit) {
            'passenger-km' => $distance,
            'passenger-mi' => $this->multiply($distance, '1.609344'),
            default => throw new \InvalidArgumentException('Unsupported passenger-distance unit.'),
        };
    }

    private function normalizeWeight(string $value, string $unit): string
    {
        $weight = $this->positiveNumber($value, 'weightValue');

        return match ($unit) {
            'kg' => $this->multiply($weight, '0.001'),
            't' => $weight,
            'lb' => $this->multiply($weight, '0.00045359237'),
            'short_ton' => $this->multiply($weight, '0.90718474'),
            'long_ton' => $this->multiply($weight, '1.0160469088'),
            default => throw new \InvalidArgumentException('Unsupported weight unit.'),
        };
    }

    private function normalizeFuel(string $value, string $unit, string $factorUnit): string
    {
        $quantity = $this->positiveNumber($value, 'activityValue');
        if ('m3' === $unit || 'm³' === $unit) {
            throw new \InvalidArgumentException('Cubic metres are unsupported without a v20 conversion rule.');
        }

        if ('kg' === $factorUnit) {
            if ('kg' !== $unit) {
                throw new \InvalidArgumentException('The selected factor requires kilograms.');
            }

            return $quantity;
        }

        return match ($unit) {
            'l', 'L' => $quantity,
            'us_gal' => $this->multiply($quantity, '3.785411784'),
            'imp_gal' => $this->multiply($quantity, '4.54609'),
            default => throw new \InvalidArgumentException('Unsupported fuel unit.'),
        };
    }

    private function normalizeOperatorEmission(string $value, string $unit): string
    {
        $emission = $this->positiveNumber($value, 'activityValue');

        return match ($unit) {
            'kg_co2e' => $emission,
            't_co2e' => $this->multiply($emission, '1000'),
            default => throw new \InvalidArgumentException('Unsupported operator emission unit.'),
        };
    }

    private function validateActivityUnit(TransportEmissionInput $input): void
    {
        $allowedUnits = match ($input->method) {
            'distance', 'route', 'route_stops', 'weight_distance', 'route_weight' => ['km', 'mi'],
            'passenger_distance' => ['passenger-km', 'passenger-mi'],
            'tonne_km' => ['t-km', 't-mi'],
            'fuel' => ['L', 'l', 'us_gal', 'imp_gal', 'kg'],
            'operator' => ['kg_co2e', 't_co2e'],
            default => null,
        };

        if (null !== $allowedUnits && !in_array($input->activityUnit, $allowedUnits, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported unit for %s.', $input->method));
        }
    }

    private function positiveNumber(string $value, string $field): string
    {
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a non-negative decimal string.', $field));
        }

        return $this->trimDecimal($value);
    }

    private function positiveInteger(string $value, string $field): string
    {
        if (!preg_match('/^[1-9]\d*$/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $field));
        }

        return $value;
    }

    private function multiply(string $left, string $right): string
    {
        if (!function_exists('bcmul')) {
            throw new \LogicException('The BCMath extension is required for transport emission calculations.');
        }

        return $this->trimDecimal(bcmul($left, $right, self::DECIMAL_SCALE));
    }

    private function trimDecimal(string $value): string
    {
        if (!str_contains($value, '.')) {
            return ltrim($value, '0') ?: '0';
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return '' === $trimmed ? '0' : $trimmed;
    }

    private function directResult(string $status, int $year): TransportEmissionResult
    {
        return new TransportEmissionResult($status, null, null, null, null, null, $year);
    }
}
