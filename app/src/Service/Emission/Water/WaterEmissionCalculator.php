<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;

final readonly class WaterEmissionCalculator
{
    private const SCALE = 18;

    public function __construct(private WaterFactorResolver $factorResolver)
    {
    }

    public function calculate(WaterEmissionInput $input): WaterEmissionResult
    {
        $dateValidation = $this->validateDates($input);
        if ($dateValidation instanceof WaterEmissionResult) {
            return $dateValidation;
        }

        $activityYear = (int) $input->startDate->format('Y');
        $country = strtoupper(trim((string) $input->country));
        if ('' === $country) {
            return $this->pending($activityYear, ['country_required']);
        }
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return $this->pending($activityYear, ['country_invalid']);
        }
        if (null === $input->waterUseType || '' === trim($input->waterUseType)) {
            return $this->pending($activityYear, ['water_use_type_required']);
        }
        if (!in_array($input->waterUseType, WaterEmissionInput::waterUseTypes(), true)) {
            return $this->pending($activityYear, ['water_use_type_unknown']);
        }
        if (null === $input->destination || '' === trim($input->destination)) {
            return $this->pending($activityYear, ['destination_required']);
        }
        if (!in_array($input->destination, WaterEmissionInput::destinations(), true)) {
            return $this->pending($activityYear, ['destination_unknown']);
        }

        [$volumeM3, $volumeError] = $this->normalizeVolume($input->volumeInput, $input->volumeInputUnit);
        if (null !== $volumeError) {
            return $this->pending($activityYear, [$volumeError]);
        }

        $resolutions = $this->factorResolver->resolve($country, $activityYear, $input->destination);
        $unavailableTraces = array_map(
            static fn (WaterFactorResolution $resolution): WaterFactorTrace => WaterFactorTrace::fromResolution(
                $resolution,
                $volumeM3,
                null,
            ),
            $resolutions,
        );
        $hasUnavailableFactor = false;
        $hasNonCalculableFactor = false;
        foreach ($resolutions as $resolution) {
            $hasUnavailableFactor = $hasUnavailableFactor || !$resolution->hasFactor();
            $hasNonCalculableFactor = $hasNonCalculableFactor || !$resolution->isCalculable();
        }
        if ([] === $resolutions || $hasUnavailableFactor) {
            return $this->notAutomaticallyCalculable(
                $activityYear,
                ['emission_factor_unavailable'],
                $volumeM3,
                $unavailableTraces,
            );
        }
        if ($hasNonCalculableFactor) {
            return $this->notAutomaticallyCalculable(
                $activityYear,
                ['emission_factor_not_calculable'],
                $volumeM3,
                $unavailableTraces,
            );
        }

        $total = '0';
        $traces = [];
        foreach ($resolutions as $resolution) {
            $componentEmission = $this->multiply($volumeM3, $resolution->factorValue);
            $total = $this->trimDecimal(bcadd($total, $componentEmission, self::SCALE));
            $traces[] = WaterFactorTrace::fromResolution($resolution, $volumeM3, $componentEmission);
        }

        return new WaterEmissionResult(
            status: EmissionRecord::STATUS_CALCULATED,
            emissionKgCo2e: $total,
            normalizedAmount: $volumeM3,
            normalizedUnit: 'm3',
            activityYear: $activityYear,
            temporalType: EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            factorTraces: $traces,
        );
    }

    private function validateDates(WaterEmissionInput $input): ?WaterEmissionResult
    {
        if (null === $input->startDate || null === $input->endDate) {
            return $this->pending(null, ['dates_required']);
        }

        $activityYear = (int) $input->startDate->format('Y');
        if ($input->endDate < $input->startDate) {
            return $this->pending($activityYear, ['invalid_date_range']);
        }
        if ($activityYear !== (int) $input->endDate->format('Y')) {
            return $this->pending($activityYear, ['split_by_year']);
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function normalizeVolume(?string $volume, ?string $unit): array
    {
        $volume = $this->decimal($volume);
        if (null === $volume) {
            return [null, 'volume_invalid'];
        }
        if (!in_array($unit, [WaterEmissionInput::UNIT_LITRES, WaterEmissionInput::UNIT_CUBIC_METRES], true)) {
            return [null, 'volume_unit_unknown'];
        }

        return [
            WaterEmissionInput::UNIT_LITRES === $unit
                ? $this->trimDecimal(bcdiv($volume, '1000', self::SCALE))
                : $volume,
            null,
        ];
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

    private function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return '' === $value || '-0' === $value ? '0' : $value;
    }

    /** @param list<string> $messages */
    private function pending(?int $activityYear, array $messages): WaterEmissionResult
    {
        return new WaterEmissionResult(
            status: EmissionRecord::STATUS_PENDING_DATA,
            emissionKgCo2e: null,
            normalizedAmount: null,
            normalizedUnit: null,
            activityYear: $activityYear,
            temporalType: null,
            messages: $messages,
        );
    }

    /**
     * @param list<string>           $messages
     * @param list<WaterFactorTrace> $traces
     */
    private function notAutomaticallyCalculable(
        int $activityYear,
        array $messages,
        string $normalizedAmount,
        array $traces,
    ): WaterEmissionResult {
        return new WaterEmissionResult(
            status: EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
            emissionKgCo2e: null,
            normalizedAmount: $normalizedAmount,
            normalizedUnit: 'm3',
            activityYear: $activityYear,
            temporalType: EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            factorTraces: $traces,
            messages: $messages,
        );
    }
}
