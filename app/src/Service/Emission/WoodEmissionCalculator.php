<?php

namespace App\Service\Emission;

use App\Entity\EmissionActivity;

final readonly class WoodEmissionCalculator
{
    private const SCHEMA_VERSION = '1.0';

    private const ORIGIN_CODES = [
        'purchased' => 'wood_purchased',
        'recycled' => 'wood_recycled',
        'reused' => 'wood_reused',
    ];

    public function __construct(private WoodCatalog $catalog)
    {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function calculate(EmissionActivity $activity, array $input): WoodCalculationResult
    {
        $method = (string) ($input['method'] ?? '');
        $origin = (string) ($input['origin'] ?? '');
        $certification = (string) ($input['certification'] ?? '');
        $quantity = $this->positiveNumber($input['quantity'] ?? null, 'invalid_quantity');

        if (!isset(self::ORIGIN_CODES[$origin])) {
            throw new \InvalidArgumentException('invalid_origin');
        }
        if (!in_array($certification, ['fsc', 'pefc', 'none'], true)) {
            throw new \InvalidArgumentException('invalid_certification');
        }
        if (
            $activity->getCalculationCode() !== self::ORIGIN_CODES[$origin]
            || $activity->getSubcategory() !== 'madera'
            || $activity->getUnit() !== 'kg'
        ) {
            throw new \InvalidArgumentException('invalid_activity');
        }
        if ($activity->getId() === null || $activity->getEmissionFactor() < 0) {
            throw new \InvalidArgumentException('invalid_factor');
        }

        $inputs = [
            'method' => $method,
            'origin' => $origin,
            'certification' => $certification,
            'quantity' => $quantity,
        ];
        $resolved = [
            'emissionFactor' => $activity->getEmissionFactor(),
            'emissionFactorUnit' => 'kg CO₂e/kg',
            'emissionFactorSource' => $activity->getEmissionSource()?->getName(),
            'emissionFactorYear' => $activity->getEmissionSource()?->getYear(),
            'emissionActivityId' => $activity->getId(),
            'calculationCode' => $activity->getCalculationCode(),
        ];

        if ($method === 'known_weight') {
            $inputWeightKg = $this->positiveNumber($input['inputWeightKg'] ?? null, 'invalid_weight');
            $unitWeightKg = $inputWeightKg;
            $totalWeightKg = $unitWeightKg * $quantity;
            $inputs['inputWeightKg'] = $inputWeightKg;
            $derived = [
                'unitWeightKg' => $unitWeightKg,
                'totalWeightKg' => $totalWeightKg,
            ];
        } elseif ($method === 'unknown_dimensions') {
            $classification = (string) ($input['woodClassification'] ?? '');
            $thicknessM = $this->positiveNumber($input['thicknessM'] ?? null, 'invalid_dimensions');
            $lengthM = $this->positiveNumber($input['lengthM'] ?? null, 'invalid_dimensions');
            $widthM = $this->positiveNumber($input['widthM'] ?? null, 'invalid_dimensions');
            $densityKgM3 = $this->catalog->getDefaultDensity($classification);
            $volumeM3 = $thicknessM * $lengthM * $widthM;
            $unitWeightKg = $volumeM3 * $densityKgM3;
            $totalWeightKg = $unitWeightKg * $quantity;

            $inputs += [
                'thicknessM' => $thicknessM,
                'lengthM' => $lengthM,
                'widthM' => $widthM,
                'woodClassification' => $classification,
            ];
            $resolved['densityKgM3'] = $densityKgM3;
            $derived = [
                'volumeM3' => $volumeM3,
                'unitWeightKg' => $unitWeightKg,
                'totalWeightKg' => $totalWeightKg,
            ];
        } else {
            throw new \InvalidArgumentException('invalid_method');
        }

        $emission = $totalWeightKg * $activity->getEmissionFactor();
        $derived['emissionKgCo2e'] = $emission;

        return new WoodCalculationResult(
            $totalWeightKg,
            $emission,
            [
                'schemaVersion' => self::SCHEMA_VERSION,
                'catalogVersion' => WoodCatalog::VERSION,
                'inputs' => $inputs,
                'resolved' => $resolved,
                'derived' => $derived,
            ],
        );
    }

    private function positiveNumber(mixed $value, string $error): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
            throw new \InvalidArgumentException($error);
        }

        return (float) $value;
    }
}
