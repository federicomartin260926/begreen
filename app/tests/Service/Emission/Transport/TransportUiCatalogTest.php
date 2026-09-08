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
            'local' => ['car', 'taxi', 'passenger_van', 'minibus', 'urban_bus', 'metro', 'tram', 'commuter_train', 'motorcycle', 'bicycle', 'scooter', 'walk'],
            'travel' => ['plane', 'long_distance_train', 'coach', 'passenger_ferry'],
            'freight' => ['freight_van', 'rigid_truck', 'articulated_truck', 'freight_train', 'air_freight', 'freight_ship', 'courier', 'cargo_bike'],
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
        self::assertSame('external_factor_required', $configuration['unavailableMethods']['electricity']);
        self::assertSame('unsupported', $configuration['unavailableMethods']['distance_consumption']);

        array_walk_recursive($configuration, static fn (mixed $value) => self::assertIsString($value));
    }

    public function testEveryCatalogCombinationIsAcceptedByTheSharedMapperContract(): void
    {
        $catalog = new TransportUiCatalog();
        $mapper = new TransportFactorCriteriaMapper($catalog);

        foreach ($catalog->categories() as $category => $modes) {
            foreach ($modes as $mode) {
                foreach ($catalog->methodsByMode()[$mode] as $method) {
                    $vehicleType = match ($method) {
                        'electricity' => 'bev',
                        'fuel_and_electricity' => 'phev',
                        default => 'petrol',
                    };
                    $input = new TransportEmissionInput(
                        $category,
                        $mode,
                        $method,
                        'ES',
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

    public function testCarPowertrainMatrixRemainsIntact(): void
    {
        self::assertSame([
            'petrol' => ['distance', 'fuel', 'distance_consumption'],
            'diesel' => ['distance', 'fuel', 'distance_consumption'],
            'lpg' => ['distance', 'fuel', 'distance_consumption'],
            'cng' => ['distance', 'fuel', 'distance_consumption'],
            'hev' => ['distance', 'fuel', 'distance_consumption'],
            'bev' => ['distance', 'electricity', 'distance_consumption'],
            'phev' => ['distance', 'fuel', 'electricity', 'fuel_and_electricity', 'distance_consumption'],
            'unknown' => ['distance'],
        ], (new TransportUiCatalog())->carTypeMethods());
    }
}
