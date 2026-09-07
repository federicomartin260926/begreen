<?php

declare(strict_types=1);

namespace App\Service\Emission\Energy;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;

final readonly class EnergyEmissionCalculator
{
    private const SCALE = 18;

    public function __construct(
        private ElectricityFactorResolver $electricityFactorResolver,
        private StationaryCombustionFactorResolver $stationaryCombustionFactorResolver,
    ) {
    }

    public function calculate(EnergyEmissionInput $input): EnergyEmissionResult
    {
        $dateValidation = $this->validateDates($input);
        if ($dateValidation instanceof EnergyEmissionResult) {
            return $dateValidation;
        }

        $activityYear = (int) $input->startDate->format('Y');
        if (null === $input->country || '' === trim($input->country)) {
            return $this->pending($activityYear, ['country_required']);
        }

        return match ($input->family) {
            EnergyEmissionInput::FAMILY_ELECTRICITY => $this->calculateElectricity($input, $activityYear),
            EnergyEmissionInput::FAMILY_EQUIPMENT => $this->calculateEquipment($input, $activityYear),
            EnergyEmissionInput::FAMILY_BATTERY => $this->calculateBattery($input, $activityYear),
            EnergyEmissionInput::FAMILY_DIGITAL => $this->calculateDigital($input, $activityYear),
            default => throw new \InvalidArgumentException(sprintf('Unsupported energy family "%s".', $input->family)),
        };
    }

    private function validateDates(EnergyEmissionInput $input): ?EnergyEmissionResult
    {
        if (null === $input->startDate || null === $input->endDate) {
            return $this->pending(null, ['dates_required']);
        }

        $activityYear = (int) $input->startDate->format('Y');
        if ($input->endDate < $input->startDate) {
            return $this->pending($activityYear, ['invalid_date_range']);
        }

        if ($activityYear !== (int) $input->endDate->format('Y')) {
            return $this->pending($activityYear, [
                'split_by_year',
                'Consumption spanning calendar years must be split into separate yearly records.',
            ]);
        }

        return null;
    }

    private function calculateElectricity(EnergyEmissionInput $input, int $activityYear): EnergyEmissionResult
    {
        [$amount, $error] = $this->normalizeElectricityAmount(
            $input->amount,
            $input->unit,
            $input->initialReading,
            $input->finalReading,
        );
        if (null !== $error) {
            return $this->pending($activityYear, [$error]);
        }

        return $this->calculateElectricityConsumption($input, $activityYear, $amount, $input->origin);
    }

    private function calculateBattery(EnergyEmissionInput $input, int $activityYear): EnergyEmissionResult
    {
        if (null === $input->batteryType || '' === trim($input->batteryType)) {
            return $this->pending($activityYear, ['battery_type_required']);
        }

        $amount = $this->decimal($input->chargedKwh);
        if (null === $amount) {
            return $this->pending($activityYear, ['charged_kwh_required']);
        }

        return $this->calculateElectricityConsumption($input, $activityYear, $amount, $input->chargeSource, 'battery_charge');
    }

    private function calculateDigital(EnergyEmissionInput $input, int $activityYear): EnergyEmissionResult
    {
        if (null === $input->digitalType || '' === trim($input->digitalType)) {
            return $this->pending($activityYear, ['digital_type_required']);
        }

        $knownKwh = $this->decimal($input->knownKwh);
        if (null !== $knownKwh) {
            $electricityCountry = null !== $input->digitalCountry && '' !== trim($input->digitalCountry)
                ? $input->digitalCountry
                : $input->country;

            return $this->calculateElectricityConsumption(
                $input,
                $activityYear,
                $knownKwh,
                EnergyEmissionInput::ORIGIN_GRID,
                'digital_energy',
                $electricityCountry,
            );
        }

        if (null !== $input->knownKwh && '' !== trim($input->knownKwh)) {
            return $this->pending($activityYear, ['known_kwh_invalid']);
        }

        if ($this->hasDigitalEstimationSignals($input)) {
            return $this->notAutomaticallyCalculable($activityYear, ['digital_estimation_methodology_unavailable']);
        }

        return $this->pending($activityYear, ['digital_activity_data_required']);
    }

    private function calculateEquipment(EnergyEmissionInput $input, int $activityYear): EnergyEmissionResult
    {
        if (null === $input->equipmentType || '' === trim($input->equipmentType)) {
            return $this->pending($activityYear, ['equipment_type_required']);
        }
        if (null === $input->fuel || '' === trim($input->fuel)) {
            return $this->pending($activityYear, ['fuel_required']);
        }

        $unit = $this->normalizeCombustionUnit($input->unit);
        $amount = null;
        if (!in_array($input->mode, [EnergyEmissionInput::EQUIPMENT_MODE_DIRECT, EnergyEmissionInput::EQUIPMENT_MODE_CYLINDERS], true)) {
            return $this->pending($activityYear, ['equipment_mode_unknown']);
        }
        if (EnergyEmissionInput::EQUIPMENT_MODE_CYLINDERS === $input->mode) {
            [$amount, $unit, $error] = $this->normalizeCylinders($input);
            if (null !== $error) {
                return $this->pending($activityYear, [$error]);
            }
        } else {
            $amount = $this->decimal($input->amount);
            if (null === $amount || null === $unit) {
                return $this->pending($activityYear, ['fuel_amount_and_unit_required']);
            }
        }

        if ($this->isBottledGas($input->fuel) && 'kg' !== $unit) {
            return $this->pending($activityYear, ['incompatible_fuel_unit']);
        }

        $resolution = $this->stationaryCombustionFactorResolver->resolve(new StationaryCombustionFactorInput(
            country: $input->country,
            activityYear: $activityYear,
            fuel: $input->fuel,
            unit: $unit,
        ));
        if (!$resolution->hasFactor()) {
            return $this->notAutomaticallyCalculable($activityYear, ['emission_factor_unavailable'], $amount, $unit);
        }

        $trace = EnergyFactorTrace::fromStationary($resolution, $amount, $unit);
        if (!$resolution->isCalculable()) {
            return $this->notAutomaticallyCalculable(
                $activityYear,
                ['emission_factor_not_calculable'],
                $amount,
                $unit,
                $resolution->resolution->temporalType,
                [$trace],
            );
        }

        return new EnergyEmissionResult(
            status: EmissionRecord::STATUS_CALCULATED,
            emissionKgCo2e: $this->multiply($amount, $resolution->resolution->factor->getValue()),
            normalizedAmount: $amount,
            normalizedUnit: $unit,
            activityYear: $activityYear,
            temporalType: $resolution->resolution->temporalType,
            factorTraces: [$trace],
        );
    }

    private function calculateElectricityConsumption(
        EnergyEmissionInput $input,
        int $activityYear,
        string $totalKwh,
        ?string $origin,
        string $componentPrefix = 'electricity',
        ?string $electricityCountry = null,
    ): EnergyEmissionResult {
        if (null === $origin || '' === trim($origin)) {
            return $this->pending($activityYear, ['electricity_origin_required'], $totalKwh, 'kWh');
        }

        if (EnergyEmissionInput::ORIGIN_MIXED === $origin) {
            return $this->calculateMixedElectricity($input, $activityYear, $totalKwh, $componentPrefix);
        }

        if (!in_array($origin, [EnergyEmissionInput::ORIGIN_GRID, EnergyEmissionInput::ORIGIN_SOLAR], true)) {
            return $this->pending($activityYear, ['electricity_origin_unknown'], $totalKwh, 'kWh');
        }

        $resolution = $this->resolveElectricity($input, $activityYear, $origin, $electricityCountry);
        if (!$resolution->hasFactor()) {
            return $this->notAutomaticallyCalculable($activityYear, ['emission_factor_unavailable'], $totalKwh, 'kWh');
        }

        $trace = EnergyFactorTrace::fromElectricity($resolution, $componentPrefix, $totalKwh);
        if (!$resolution->isCalculable()) {
            return $this->notAutomaticallyCalculable(
                $activityYear,
                ['emission_factor_not_calculable'],
                $totalKwh,
                'kWh',
                $resolution->temporalType,
                [$trace],
            );
        }

        return new EnergyEmissionResult(
            status: EmissionRecord::STATUS_CALCULATED,
            emissionKgCo2e: $this->multiply($totalKwh, $resolution->value),
            normalizedAmount: $totalKwh,
            normalizedUnit: 'kWh',
            activityYear: $activityYear,
            temporalType: $resolution->temporalType,
            factorTraces: [$trace],
        );
    }

    private function calculateMixedElectricity(
        EnergyEmissionInput $input,
        int $activityYear,
        string $totalKwh,
        string $componentPrefix,
    ): EnergyEmissionResult {
        $gridKwh = $this->decimal($input->gridKwh);
        $solarKwh = $this->decimal($input->solarKwh);
        if (null === $gridKwh || null === $solarKwh) {
            return $this->pending($activityYear, ['mixed_energy_breakdown_required'], $totalKwh, 'kWh');
        }

        if (!$this->decimalsMatch($totalKwh, bcadd($gridKwh, $solarKwh, self::SCALE))) {
            return $this->pending($activityYear, ['mixed_energy_total_mismatch'], $totalKwh, 'kWh');
        }

        $grid = $this->resolveElectricity($input, $activityYear, EnergyEmissionInput::ORIGIN_GRID);
        $solar = $this->resolveElectricity($input, $activityYear, EnergyEmissionInput::ORIGIN_SOLAR);
        $traces = [];
        if ($grid->hasFactor()) {
            $traces[] = EnergyFactorTrace::fromElectricity($grid, $componentPrefix.'_grid', $gridKwh);
        }
        if ($solar->hasFactor()) {
            $traces[] = EnergyFactorTrace::fromElectricity($solar, $componentPrefix.'_solar', $solarKwh);
        }

        if (!$grid->hasFactor() || !$solar->hasFactor()) {
            return $this->notAutomaticallyCalculable(
                $activityYear,
                ['emission_factor_unavailable'],
                $totalKwh,
                'kWh',
                EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
                $traces,
            );
        }
        if (!$grid->isCalculable() || !$solar->isCalculable()) {
            return $this->notAutomaticallyCalculable(
                $activityYear,
                ['emission_factor_not_calculable'],
                $totalKwh,
                'kWh',
                EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
                $traces,
            );
        }

        $emission = bcadd(
            $this->multiply($gridKwh, $grid->value),
            $this->multiply($solarKwh, $solar->value),
            self::SCALE,
        );

        return new EnergyEmissionResult(
            status: EmissionRecord::STATUS_CALCULATED,
            emissionKgCo2e: $this->trimDecimal($emission),
            normalizedAmount: $totalKwh,
            normalizedUnit: 'kWh',
            activityYear: $activityYear,
            temporalType: EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
            factorTraces: $traces,
        );
    }

    private function resolveElectricity(
        EnergyEmissionInput $input,
        int $activityYear,
        string $origin,
        ?string $electricityCountry = null,
    ): ElectricityFactorResolution
    {
        return $this->electricityFactorResolver->resolve(new ElectricityFactorInput(
            country: $electricityCountry ?? $input->country,
            activityYear: $activityYear,
            origin: $origin,
            supplier: $input->supplier,
            labeling: $input->labeling,
        ));
    }

    /** @return array{0: ?string, 1: ?string} */
    private function normalizeElectricityAmount(
        ?string $amount,
        ?string $unit,
        ?string $initialReading,
        ?string $finalReading,
    ): array {
        if (null !== $amount) {
            $decimal = $this->decimal($amount);
            if (null === $decimal || !in_array($unit, ['kWh', 'MWh'], true)) {
                return [null, 'electricity_amount_and_unit_required'];
            }

            return ['MWh' === $unit ? $this->multiply($decimal, '1000') : $decimal, null];
        }

        $initial = $this->decimal($initialReading);
        $final = $this->decimal($finalReading);
        if (null === $initial || null === $final || !in_array($unit, ['kWh', 'MWh'], true)) {
            return [null, 'electricity_activity_data_required'];
        }
        if (bccomp($final, $initial, self::SCALE) < 0) {
            return [null, 'final_reading_before_initial'];
        }

        $difference = $this->trimDecimal(bcsub($final, $initial, self::SCALE));

        return ['MWh' === $unit ? $this->multiply($difference, '1000') : $difference, null];
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function normalizeCylinders(EnergyEmissionInput $input): array
    {
        $allowedSizes = match ($input->fuel) {
            'Gas butano' => ['6', '12.5'],
            'Gas propano' => ['11', '35'],
            default => [],
        };
        $size = $this->decimal($input->bottleSizeKg);
        $count = $this->decimal($input->bottleCount);
        if ([] === $allowedSizes || null === $size || !in_array($size, $allowedSizes, true)) {
            return [null, null, 'invalid_cylinder_size'];
        }
        if (null === $count || bccomp($count, '0', self::SCALE) <= 0 || !preg_match('/^\d+$/', $count)) {
            return [null, null, 'invalid_cylinder_count'];
        }

        return [$this->multiply($size, $count), 'kg', null];
    }

    private function hasDigitalEstimationSignals(EnergyEmissionInput $input): bool
    {
        foreach ([$input->hours, $input->units, $input->gpu, $input->service, $input->model, $input->provider] as $value) {
            if (null !== $value && '' !== trim($value)) {
                return true;
            }
        }

        return false;
    }

    private function isBottledGas(string $fuel): bool
    {
        return in_array($fuel, ['Gas butano', 'Gas propano'], true);
    }

    private function normalizeCombustionUnit(?string $unit): ?string
    {
        if (null === $unit || '' === trim($unit)) {
            return null;
        }

        return match ($unit) {
            'l', 'L', 'litro', 'litros' => 'litros',
            'm³' => 'm3',
            default => $unit,
        };
    }

    private function decimal(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            return null;
        }

        return $this->trimDecimal($value);
    }

    private function multiply(string $left, string $right): string
    {
        return $this->trimDecimal(bcmul($left, $right, self::SCALE));
    }

    private function decimalsMatch(string $left, string $right): bool
    {
        $difference = bcsub($left, $right, self::SCALE);
        if (str_starts_with($difference, '-')) {
            $difference = substr($difference, 1);
        }

        return bccomp($difference, '0.000001', self::SCALE) <= 0;
    }

    private function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return '' === $value || '-0' === $value ? '0' : $value;
    }

    /** @param list<string> $messages */
    private function pending(
        ?int $activityYear,
        array $messages,
        ?string $normalizedAmount = null,
        ?string $normalizedUnit = null,
    ): EnergyEmissionResult {
        return new EnergyEmissionResult(
            status: EmissionRecord::STATUS_PENDING_DATA,
            emissionKgCo2e: null,
            normalizedAmount: $normalizedAmount,
            normalizedUnit: $normalizedUnit,
            activityYear: $activityYear,
            temporalType: null,
            messages: $messages,
        );
    }

    /**
     * @param list<string>            $messages
     * @param list<EnergyFactorTrace> $traces
     */
    private function notAutomaticallyCalculable(
        int $activityYear,
        array $messages,
        ?string $normalizedAmount = null,
        ?string $normalizedUnit = null,
        ?string $temporalType = null,
        array $traces = [],
    ): EnergyEmissionResult {
        return new EnergyEmissionResult(
            status: EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
            emissionKgCo2e: null,
            normalizedAmount: $normalizedAmount,
            normalizedUnit: $normalizedUnit,
            activityYear: $activityYear,
            temporalType: $temporalType,
            factorTraces: $traces,
            messages: $messages,
        );
    }
}
