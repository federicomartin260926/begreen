<?php

namespace App\Service\Emission\Energy;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorResolver;

final readonly class ElectricityFactorResolver
{
    private const CATEGORY_KEY = 'energy';

    public function __construct(private EmissionFactorResolver $factorResolver)
    {
    }

    public function resolve(ElectricityFactorInput $input): ElectricityFactorResolution
    {
        $country = mb_strtoupper(trim($input->country), 'UTF-8');
        if (ElectricityFactorInput::ORIGIN_SOLAR === $input->origin) {
            return ElectricityFactorResolution::rule(
                $input->activityYear,
                '0',
                'kgCO2e/kWh',
                'BGMF',
                'Energy specification FINAL: solar operational emissions, Scope 2.',
                ['electricityOrigin' => ElectricityFactorInput::ORIGIN_SOLAR, 'unit' => 'kWh'],
                [
                    'scope' => 'ALCANCE 2',
                    'boundary' => 'Operational electricity only; lifecycle emissions are not zero.',
                    'country' => $country,
                ],
            );
        }

        if (ElectricityFactorInput::ORIGIN_GRID !== $input->origin) {
            $temporalType = ElectricityFactorInput::ORIGIN_MIXED === $input->origin
                ? EmissionFactor::TEMPORAL_TYPE_COMPOSITE
                : EmissionFactor::TEMPORAL_TYPE_ANNUAL;

            return ElectricityFactorResolution::unavailable($input->activityYear, $temporalType);
        }

        if ('ES' === $country || 'ESP' === $country || 'ESPAÑA' === $country) {
            return $this->resolveSpain($input, $country);
        }

        $criteria = $this->criteria('FUERA DE ESPAÑA', 'PROMEDIO NACIONAL', '', '');
        $resolution = $this->factorResolver->resolve(self::CATEGORY_KEY, $criteria, $input->activityYear);
        $isUk = in_array($country, ['GB', 'GBR', 'UK', 'REINO UNIDO', 'UNITED KINGDOM'], true);

        return ElectricityFactorResolution::fromAnnual(
            $resolution,
            !$isUk && $resolution->hasFactor(),
            !$isUk && $resolution->hasFactor() ? 'Reino Unido' : null,
            ['country' => $country],
        );
    }

    private function resolveSpain(ElectricityFactorInput $input, string $country): ElectricityFactorResolution
    {
        $labeling = null === $input->labeling || '' === trim($input->labeling)
            ? 'SIN GDO'
            : strtoupper(trim($input->labeling));
        $supplier = trim((string) $input->supplier);
        $activity = '' === $supplier ? 'PROMEDIO NACIONAL' : 'SUMINISTRO COMERCIALIZADORA';
        $resolution = $this->factorResolver->resolve(
            self::CATEGORY_KEY,
            $this->criteria('ESPAÑA', $activity, $labeling, $supplier),
            $input->activityYear,
        );

        if (!$resolution->hasFactor() && '' !== $supplier) {
            $resolution = $this->factorResolver->resolve(
                self::CATEGORY_KEY,
                $this->criteria('ESPAÑA', 'PROMEDIO NACIONAL', $labeling, ''),
                $input->activityYear,
            );
        }

        return ElectricityFactorResolution::fromAnnual($resolution, false, null, ['country' => $country]);
    }

    /** @return array<string, string> */
    private function criteria(string $geography, string $activity, string $labeling, string $supplier): array
    {
        return [
            'geography' => $geography,
            'category' => 'ELECTRICIDAD',
            'activity' => $activity,
            'labeling' => $labeling,
            'supplier' => $supplier,
            'unit' => 'kWh',
        ];
    }
}
