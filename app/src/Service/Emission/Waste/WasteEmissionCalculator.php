<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use App\Entity\EmissionRecord;

final readonly class WasteEmissionCalculator
{
    private const SCALE = 18;

    public function __construct(
        private WasteUiCatalog $catalog,
        private WasteFactorResolver $factorResolver,
    ) {
    }

    public function calculate(WasteEmissionInput $input): WasteEmissionResult
    {
        if (null === $input->startDate || null === $input->endDate) {
            return $this->pending(null, ['dates_required']);
        }
        $activityYear = (int) $input->startDate->format('Y');
        if ($input->endDate < $input->startDate) {
            return $this->pending($activityYear, ['invalid_date_range']);
        }
        if ($input->endDate->format('Y') !== $input->startDate->format('Y')) {
            return $this->pending($activityYear, ['activity_crosses_year']);
        }

        try {
            $country = $this->catalog->normalizeCountry((string) $input->country);
        } catch (\InvalidArgumentException) {
            return $this->pending($activityYear, ['country_invalid']);
        }

        $wasteType = null === $input->wasteType ? '' : trim($input->wasteType);
        if ('' === $wasteType) {
            return $this->pending($activityYear, ['waste_type_required']);
        }
        if (!$this->catalog->hasWasteType($country, $wasteType)) {
            return $this->pending($activityYear, ['waste_type_invalid']);
        }

        $requiresActivity = $this->catalog->requiresSubactivity($country, $wasteType);
        if ($requiresActivity && (null === $input->wasteActivity || '' === trim($input->wasteActivity))) {
            return $this->pending($activityYear, ['waste_activity_required']);
        }
        $wasteActivity = $this->catalog->canonicalActivity($country, $wasteType, $input->wasteActivity);
        if (null === $wasteActivity) {
            return $this->pending($activityYear, ['waste_activity_invalid']);
        }

        $routes = $this->catalog->routesFor($country, $wasteType, $wasteActivity);
        if ([] === $routes) {
            return $this->pending($activityYear, ['treatment_unavailable']);
        }
        $treatment = null === $input->treatment ? '' : trim($input->treatment);
        if ('' === $treatment) {
            if (1 !== count($routes)) {
                return $this->pending($activityYear, ['treatment_required']);
            }
            $treatment = $routes[0]['treatment'];
        }
        if (null === $this->catalog->resolveRoute($country, $wasteType, $wasteActivity, $treatment)) {
            return $this->pending($activityYear, ['treatment_invalid']);
        }

        $weight = $this->positiveDecimal($input->weight);
        $weightUnit = null === $input->weightUnit ? '' : trim($input->weightUnit);
        if (null === $weight || !in_array($weightUnit, WasteEmissionInput::weightUnits(), true)) {
            return $this->pending($activityYear, ['weight_invalid']);
        }
        $normalizedKg = WasteEmissionInput::UNIT_TONNE === $weightUnit
            ? $this->multiply($weight, '1000')
            : $weight;

        $resolution = $this->factorResolver->resolve(
            $country,
            $wasteType,
            $wasteActivity,
            $treatment,
            $activityYear,
        );
        if (!$resolution->isCalculable() || null === $resolution->factorValue) {
            return new WasteEmissionResult(
                EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
                null,
                $normalizedKg,
                'kg',
                $activityYear,
                $wasteActivity,
                $resolution->resolvedTreatment,
                [new WasteFactorTrace($resolution, $normalizedKg, 'kg', null)],
                ['automatic_factor_unavailable'],
            );
        }

        $emission = $this->multiply($normalizedKg, $resolution->factorValue);

        return new WasteEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            $emission,
            $normalizedKg,
            'kg',
            $activityYear,
            $wasteActivity,
            $resolution->resolvedTreatment,
            [new WasteFactorTrace($resolution, $normalizedKg, 'kg', $emission)],
        );
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

    private function trimDecimal(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return '' === $value || '-0' === $value ? '0' : $value;
    }

    /** @param list<string> $messages */
    private function pending(?int $activityYear, array $messages): WasteEmissionResult
    {
        return new WasteEmissionResult(EmissionRecord::STATUS_PENDING_DATA, null, null, null, $activityYear, messages: $messages);
    }
}
