<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

use App\Entity\EmissionRecord;

final readonly class CateringEmissionCalculator
{
    private const SCALE = 18;

    public function __construct(private CateringFactorResolver $factorResolver)
    {
    }

    public function calculate(CateringEmissionInput $input): CateringEmissionResult
    {
        if (null === $input->startDate || null === $input->endDate) {
            return $this->pending(null, ['dates_required']);
        }
        $activityYear = (int) $input->startDate->format('Y');
        if ($input->endDate < $input->startDate) {
            return $this->pending($activityYear, ['invalid_date_range']);
        }

        $country = strtoupper(trim((string) $input->country));
        if ('' === $country) {
            return $this->pending($activityYear, ['country_required']);
        }
        if (!preg_match('/^[A-Z]{3}$/', $country)) {
            return $this->pending($activityYear, ['country_invalid']);
        }
        if (null === $input->activityType || '' === trim($input->activityType)) {
            return $this->pending($activityYear, ['activity_type_required']);
        }
        if (!in_array($input->activityType, CateringEmissionInput::activityTypes(), true)) {
            return $this->pending($activityYear, ['activity_type_unknown']);
        }

        return match ($input->activityType) {
            CateringEmissionInput::TYPE_MEAL => $this->calculateMeal($input, $activityYear),
            CateringEmissionInput::TYPE_BREAKFAST,
            CateringEmissionInput::TYPE_COFFEEBREAK,
            CateringEmissionInput::TYPE_SNACK => $this->normalizePeople($input, $activityYear),
            CateringEmissionInput::TYPE_SANDWICH => $this->normalizeSandwich($input, $activityYear),
            CateringEmissionInput::TYPE_WATER => $this->normalizeWater($input, $activityYear),
            CateringEmissionInput::TYPE_DRINK => $this->normalizeDrink($input, $activityYear),
            CateringEmissionInput::TYPE_COFFEE => $this->normalizeCoffee($input, $activityYear),
            CateringEmissionInput::TYPE_GAS => $this->normalizeGas($input, $activityYear),
        };
    }

    private function calculateMeal(CateringEmissionInput $input, int $activityYear): CateringEmissionResult
    {
        if (null === $input->tablewareType || !in_array($input->tablewareType, CateringEmissionInput::tablewareTypes(), true)) {
            return $this->pending($activityYear, ['tableware_type_invalid']);
        }

        $traces = [];
        $foodEmission = '0';
        $preparedTotal = '0';
        $consumedTotal = '0';
        $hasPrepared = false;
        $calculatedFoodLines = 0;
        $messages = [];
        foreach ($input->menuLines as $line) {
            if (!$line instanceof CateringMenuLine) {
                return $this->pending($activityYear, ['menu_line_invalid']);
            }
            if ($this->emptyMenuLine($line)) {
                continue;
            }
            $prepared = $this->nonNegativeInteger($line->preparedCount);
            $consumed = $this->nonNegativeInteger($line->consumedCount);
            if (null === $prepared || null === $consumed) {
                return $this->pending($activityYear, ['menu_counts_invalid']);
            }
            if (bccomp($consumed, $prepared, 0) > 0) {
                return $this->pending($activityYear, ['consumed_exceeds_prepared']);
            }
            if ('0' === $prepared) {
                continue;
            }
            if (null === $line->menuVariant || !in_array($line->menuVariant, CateringEmissionInput::menuVariants(), true)) {
                return $this->pending($activityYear, ['menu_variant_invalid']);
            }

            $hasPrepared = true;
            $preparedTotal = $this->add($preparedTotal, $prepared);
            $consumedTotal = $this->add($consumedTotal, $consumed);
            $resolution = $this->factorResolver->resolveFood($line->menuVariant, $activityYear);
            if (!$resolution->hasFactor() || null === $resolution->factorValue) {
                $traces[] = CateringFactorTrace::fromResolution($resolution, $prepared, 'prepared_menu', null);
                $messages[] = 'food_automatic_factor_unavailable';
                continue;
            }
            $emission = $this->multiply($prepared, $resolution->factorValue);
            $foodEmission = $this->add($foodEmission, $emission);
            ++$calculatedFoodLines;
            $traces[] = CateringFactorTrace::fromResolution($resolution, $prepared, 'prepared_menu', $emission);
        }
        if (!$hasPrepared) {
            return $this->pending($activityYear, ['prepared_menu_required']);
        }
        if (0 === $calculatedFoodLines) {
            return $this->notCalculable($activityYear, $preparedTotal, 'prepared_menu', $traces, ['automatic_factor_unavailable']);
        }

        $totalEmission = $foodEmission;
        $tablewareEmission = null;
        $tableware = $this->factorResolver->resolveTableware($input->tablewareType, $activityYear);
        if (null === $tableware || !$tableware->hasFactor() || null === $tableware->factorValue) {
            $messages[] = 'tableware_automatic_factor_unavailable';
        } else {
            $tablewareEmission = $this->multiply($consumedTotal, $tableware->factorValue);
            $totalEmission = $this->add($totalEmission, $tablewareEmission);
            $traces[] = CateringFactorTrace::fromResolution($tableware, $consumedTotal, 'consumed_menu', $tablewareEmission);
        }

        return new CateringEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            $totalEmission,
            $preparedTotal,
            'prepared_menu',
            $activityYear,
            $traces,
            $messages,
            $foodEmission,
            $tablewareEmission,
        );
    }

    private function normalizePeople(CateringEmissionInput $input, int $activityYear): CateringEmissionResult
    {
        $people = $this->positiveInteger($input->people);

        return null === $people
            ? $this->pending($activityYear, ['people_invalid'])
            : $this->notCalculable($activityYear, $people, 'people');
    }

    private function normalizeSandwich(CateringEmissionInput $input, int $activityYear): CateringEmissionResult
    {
        $prepared = $this->positiveInteger($input->preparedCount);
        $consumed = $this->nonNegativeInteger($input->consumedCount);
        if (null === $prepared) {
            return $this->pending($activityYear, ['prepared_count_invalid']);
        }
        if (null !== $input->consumedCount && (null === $consumed || bccomp($consumed, $prepared, 0) > 0)) {
            return $this->pending($activityYear, ['consumed_count_invalid']);
        }

        return $this->notCalculable($activityYear, $prepared, 'prepared sandwich');
    }

    private function normalizeWater(CateringEmissionInput $input, int $activityYear): CateringEmissionResult
    {
        $volume = $this->positiveDecimal($input->containerVolumeLiters);
        $count = $this->positiveInteger($input->containerCount);
        if (null === $volume || null === $count || null === $input->containerMaterial || '' === trim($input->containerMaterial)) {
            return $this->pending($activityYear, ['water_input_invalid']);
        }

        return $this->notCalculable($activityYear, $this->multiply($volume, $count), 'L');
    }

    private function normalizeDrink(CateringEmissionInput $input, int $activityYear): CateringEmissionResult
    {
        $units = $this->positiveInteger($input->unitCount);
        $liters = $this->positiveDecimal($input->litersPerUnit);
        if (null === $units || null === $liters || null === $input->description || '' === trim($input->description)) {
            return $this->pending($activityYear, ['drink_input_invalid']);
        }

        return $this->notCalculable($activityYear, $this->multiply($units, $liters), 'L');
    }

    private function normalizeCoffee(CateringEmissionInput $input, int $activityYear): CateringEmissionResult
    {
        $services = $this->positiveInteger($input->serviceCount);

        return null === $services
            ? $this->pending($activityYear, ['service_count_invalid'])
            : $this->notCalculable($activityYear, $services, 'service');
    }

    private function normalizeGas(CateringEmissionInput $input, int $activityYear): CateringEmissionResult
    {
        $cylinders = $this->positiveInteger($input->cylinderCount);
        $kg = $this->positiveDecimal($input->kgPerCylinder);
        if (!in_array($input->gasType, ['propane', 'butane'], true) || null === $cylinders || null === $kg) {
            return $this->pending($activityYear, ['gas_input_invalid']);
        }

        return $this->notCalculable($activityYear, $this->multiply($cylinders, $kg), 'kg');
    }

    private function emptyMenuLine(CateringMenuLine $line): bool
    {
        return (null === $line->menuVariant || '' === trim($line->menuVariant))
            && (null === $line->preparedCount || '' === trim($line->preparedCount) || '0' === trim($line->preparedCount))
            && (null === $line->consumedCount || '' === trim($line->consumedCount) || '0' === trim($line->consumedCount));
    }

    private function positiveInteger(?string $value): ?string
    {
        $value = $this->nonNegativeInteger($value);

        return null !== $value && '0' !== $value ? $value : null;
    }

    private function nonNegativeInteger(?string $value): ?string
    {
        if (null === $value || !preg_match('/^(?:0|[1-9]\d*)$/', trim($value))) {
            return null;
        }

        return ltrim(trim($value), '0') ?: '0';
    }

    private function positiveDecimal(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) || bccomp($value, '0', self::SCALE) <= 0) {
            return null;
        }

        return $this->trimDecimal($value);
    }

    private function multiply(string $left, string $right): string
    {
        return $this->trimDecimal(bcmul($left, $right, self::SCALE));
    }

    private function add(string $left, string $right): string
    {
        return $this->trimDecimal(bcadd($left, $right, self::SCALE));
    }

    private function trimDecimal(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return '' === $value || '-0' === $value ? '0' : $value;
    }

    /** @param list<string> $messages */
    private function pending(?int $activityYear, array $messages): CateringEmissionResult
    {
        return new CateringEmissionResult(EmissionRecord::STATUS_PENDING_DATA, null, null, null, $activityYear, messages: $messages);
    }

    /** @param list<CateringFactorTrace> $traces
     *  @param list<string> $messages
     */
    private function notCalculable(int $activityYear, ?string $amount, ?string $unit, array $traces = [], array $messages = ['automatic_factor_unavailable']): CateringEmissionResult
    {
        return new CateringEmissionResult(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, null, $amount, $unit, $activityYear, $traces, $messages);
    }
}
