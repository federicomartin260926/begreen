<?php

namespace App\Service\Emission\Energy;

use App\Service\Emission\EmissionFactorResolver;

final readonly class StationaryCombustionFactorResolver
{
    private const CATEGORY_KEY = 'energy';

    public function __construct(private EmissionFactorResolver $factorResolver)
    {
    }

    public function resolve(StationaryCombustionFactorInput $input): StationaryCombustionFactorResolution
    {
        $country = mb_strtoupper(trim($input->country), 'UTF-8');
        $isSpain = in_array($country, ['ES', 'ESP', 'ESPAÑA'], true);
        $criteria = [
            'geography' => $isSpain ? 'ESPAÑA' : 'FUERA DE ESPAÑA',
            'category' => 'COMBUSTIÓN ESTACIONARIA',
            'activity' => $input->fuel,
            'labeling' => '',
            'supplier' => '',
            'unit' => $input->unit,
        ];
        $resolution = $this->factorResolver->resolve(self::CATEGORY_KEY, $criteria, $input->activityYear);
        $isUk = in_array($country, ['GB', 'GBR', 'UK', 'REINO UNIDO', 'UNITED KINGDOM'], true);
        $metadata = $resolution->factor?->getMetadata() ?? [];
        $isCountryProxy = !$isSpain && !$isUk && $resolution->hasFactor();
        $isProxy = $isCountryProxy || true === ($metadata['isGeographicProxy'] ?? false);
        $proxyGeography = $isCountryProxy
            ? 'Reino Unido'
            : (is_string($metadata['proxyGeography'] ?? null) ? $metadata['proxyGeography'] : null);

        return new StationaryCombustionFactorResolution(
            $resolution,
            $isProxy,
            $proxyGeography,
        );
    }
}
