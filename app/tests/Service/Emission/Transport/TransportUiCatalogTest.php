<?php

namespace App\Tests\Service\Emission\Transport;

use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use App\Service\Emission\Transport\TransportUiCatalog;
use PHPUnit\Framework\TestCase;

final class TransportUiCatalogTest extends TestCase
{
    public function testCatalogContainsTheExactCategoryAndModeCodesWithoutNumericFactors(): void
    {
        $catalog = new TransportUiCatalog();

        self::assertSame([
            'local' => ['car', 'taxi', 'urban_bus', 'metro', 'tram', 'commuter_train'],
            'travel' => ['plane', 'long_distance_train', 'passenger_ferry'],
            'freight' => ['freight_van', 'rigid_truck', 'articulated_truck', 'freight_train', 'air_freight', 'freight_ship'],
        ], $catalog->categories());

        $catalogValues = [$catalog->categories(), $catalog->methodsByMode()];
        array_walk_recursive(
            $catalogValues,
            static fn (mixed $value) => self::assertIsString($value),
        );

        $configuration = $catalog->configuration();
        self::assertSame(['km', 'mi'], $configuration['unitsByMethod']['distance']);
        self::assertSame(['passenger-km', 'passenger-mi'], $configuration['unitsByMethod']['passenger_distance']);
        self::assertSame(['kg', 't', 'lb', 'short_ton', 'long_ton'], $configuration['weightUnits']);
        self::assertSame(['L', 'us_gal', 'imp_gal', 'kg'], $configuration['unitsByMethod']['fuel']);
        self::assertNotContains('distance_consumption', $catalog->methods());
        self::assertNotContains('fuel_and_electricity', $catalog->methods());
        self::assertNotContains('electricity', $catalog->methods());

        array_walk_recursive($configuration, static fn (mixed $value) => self::assertIsString($value));
    }

    public function testEveryCatalogCombinationIsAcceptedByTheSharedMapperContract(): void
    {
        $catalog = new TransportUiCatalog();
        $mapper = new TransportFactorCriteriaMapper($catalog);

        foreach ($catalog->categories() as $category => $modes) {
            foreach ($modes as $mode) {
                foreach ($catalog->methodsByMode()[$mode] as $method) {
                    $vehicleType = 'petrol';
                    $country = in_array($method, $catalog->configuration()['outsideSpainOnlyMethodsByMode'][$mode] ?? [], true)
                        ? 'FR'
                        : 'ES';
                    $input = new TransportEmissionInput(
                        $category,
                        $mode,
                        $method,
                        $country,
                        new \DateTimeImmutable('2026-01-15'),
                        new \DateTimeImmutable('2026-01-15'),
                        '1',
                        'km',
                        vehicleType: (
                            'car' === $mode
                            || ('taxi' === $mode && in_array($method, ['distance', 'route'], true))
                        ) ? $vehicleType : null,
                    );

                    self::assertTrue($mapper->supportsUiCombination($input), sprintf('%s/%s/%s', $category, $mode, $method));
                }
            }
        }
    }

    public function testCarPowertrainMatrixOnlyOffersBaseBackedMethods(): void
    {
        self::assertSame([
            'petrol' => ['distance', 'fuel'],
            'diesel' => ['distance', 'fuel'],
            'lpg' => ['distance', 'fuel'],
            'cng' => ['distance', 'fuel'],
            'hev' => ['distance', 'fuel'],
            'bev' => ['distance'],
            'phev' => ['distance'],
            'unknown' => ['distance'],
        ], (new TransportUiCatalog())->carTypeMethods());
    }

    public function testCountrySpecificOptionsAreRejectedByTheBackendContract(): void
    {
        $catalog = new TransportUiCatalog();

        self::assertFalse($catalog->supports($this->input('freight', 'freight_van', 'tonne_km', 'ES')));
        self::assertTrue($catalog->supports($this->input('freight', 'freight_van', 'tonne_km', 'FR')));
        self::assertTrue($catalog->supports($this->input('local', 'taxi', 'route', 'ES', 'petrol')));
        self::assertFalse($catalog->supports($this->input('local', 'taxi', 'route', 'FR')));
        self::assertFalse($catalog->supports($this->input('local', 'car', 'distance', 'ES', 'bev')));
        self::assertTrue($catalog->supports($this->input('local', 'car', 'distance', 'FR', 'bev')));
    }

    private function input(string $category, string $mode, string $method, string $country, ?string $vehicleType = null): TransportEmissionInput
    {
        return new TransportEmissionInput(
            $category, $mode, $method, $country,
            new \DateTimeImmutable('2026-01-15'), new \DateTimeImmutable('2026-01-15'), '1', 'km',
            vehicleType: $vehicleType,
        );
    }
}
