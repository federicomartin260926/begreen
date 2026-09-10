<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionRecord;

final readonly class MaterialEmissionCalculator
{
    private const MIN_YEAR = 2022;
    private const MAX_YEAR = 2026;
    private const SCALE = 18;

    public function __construct(
        private MaterialUiCatalog $catalog,
        private MaterialAmountNormalizer $normalizer,
        private MaterialFactorResolver $factorResolver,
    ) {
    }

    public function calculate(MaterialEmissionInput $input): MaterialEmissionResult
    {
        if (null === $input->startDate || null === $input->endDate) {
            return $this->pending(null, 'dates_required');
        }
        $activityYear = (int) $input->startDate->format('Y');
        if ($input->endDate < $input->startDate) {
            return $this->pending($activityYear, 'invalid_date_range');
        }
        if ($activityYear < self::MIN_YEAR || $activityYear > self::MAX_YEAR) {
            return $this->pending($activityYear, 'activity_year_out_of_range');
        }

        try {
            $this->catalog->normalizeCountry((string) $input->country);
        } catch (\InvalidArgumentException) {
            return $this->pending($activityYear, 'country_invalid');
        }

        $activity = null === $input->activity ? '' : trim($input->activity);
        if ('' === $activity) {
            return $this->pending($activityYear, 'activity_required');
        }
        if (!$this->catalog->hasActivity($activity)) {
            return $this->pending($activityYear, 'activity_invalid');
        }

        $subproduct = $this->catalog->canonicalSubproduct($activity, $input->subproduct);
        if (null === $subproduct) {
            return $this->pending(
                $activityYear,
                $this->catalog->requiresSubproduct($activity) && (null === $input->subproduct || '' === trim($input->subproduct))
                    ? 'subproduct_required'
                    : 'subproduct_invalid',
            );
        }

        $origin = $this->catalog->canonicalOrigin($activity, $subproduct, $input->origin);
        if (null === $origin) {
            return $this->pending($activityYear, 'origin_required');
        }

        $normalization = $this->normalizer->normalize($input);
        if (EmissionRecord::STATUS_CALCULATED !== $normalization['status']) {
            return new MaterialEmissionResult(
                $normalization['status'],
                null,
                $normalization['amount'],
                $normalization['unit'],
                $activityYear,
                messages: [($normalization['message'] ?? 'normalization_unavailable')],
            );
        }
        $normalizedAmount = $normalization['amount'];
        $normalizedUnit = $normalization['unit'];
        if (null === $normalizedAmount || null === $normalizedUnit) {
            throw new \LogicException('A successful material normalization must define amount and unit.');
        }

        $resolution = $this->factorResolver->resolve(
            $activity,
            '' === $subproduct ? null : $subproduct,
            $origin,
            $normalizedUnit,
            $activityYear,
        );
        if (!$resolution->isCalculable() || null === $resolution->factorValue) {
            return new MaterialEmissionResult(
                EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
                null,
                $normalizedAmount,
                $normalizedUnit,
                $activityYear,
                [new MaterialFactorTrace($resolution, $normalizedAmount, $normalizedUnit, null)],
                ['automatic_factor_unavailable'],
            );
        }

        $emission = $this->trimDecimal(bcmul($normalizedAmount, $resolution->factorValue, self::SCALE));
        $avoidedEmission = null;
        $avoidedFactorTraces = [];
        $counterfactualOrigin = $this->catalog->counterfactualOrigin(
            $activity,
            '' === $subproduct ? null : $subproduct,
            $origin,
            $normalizedUnit,
        );
        if (null !== $counterfactualOrigin) {
            $counterfactual = $this->factorResolver->resolve(
                $activity,
                '' === $subproduct ? null : $subproduct,
                $counterfactualOrigin,
                $normalizedUnit,
                $activityYear,
            );
            if ($counterfactual->isCalculable() && null !== $counterfactual->factorValue) {
                $counterfactualEmission = $this->trimDecimal(bcmul($normalizedAmount, $counterfactual->factorValue, self::SCALE));
                $avoidedEmission = $this->trimDecimal(bcsub($counterfactualEmission, $emission, self::SCALE));
                $avoidedFactorTraces[] = new MaterialFactorTrace(
                    $counterfactual,
                    $normalizedAmount,
                    $normalizedUnit,
                    $counterfactualEmission,
                    'counterfactual',
                );
            }
        }

        return new MaterialEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            $emission,
            $normalizedAmount,
            $normalizedUnit,
            $activityYear,
            [new MaterialFactorTrace($resolution, $normalizedAmount, $normalizedUnit, $emission)],
            avoidedEmissionKgCo2e: $avoidedEmission,
            avoidedFactorTraces: $avoidedFactorTraces,
        );
    }

    private function trimDecimal(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return '' === $value || '-0' === $value ? '0' : $value;
    }

    private function pending(?int $activityYear, string $message): MaterialEmissionResult
    {
        return new MaterialEmissionResult(EmissionRecord::STATUS_PENDING_DATA, null, null, null, $activityYear, messages: [$message]);
    }
}
