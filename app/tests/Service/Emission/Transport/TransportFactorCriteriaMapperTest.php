<?php

namespace App\Tests\Service\Emission\Transport;

use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use PHPUnit\Framework\TestCase;

final class TransportFactorCriteriaMapperTest extends TestCase
{
    private TransportFactorCriteriaMapper $mapper;
    /** @var array<string, true> */
    private array $catalogCriteria = [];

    protected function setUp(): void
    {
        $this->mapper = new TransportFactorCriteriaMapper();
        $file = new \SplFileObject(__DIR__.'/../../../../src/DataFixtures/data/emission/transport_factors_v20.csv', 'rb');
        $file->setCsvControl(',', '"', '');
        $headers = $file->fgetcsv();
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            $row = array_combine($headers, $values);
            $criteria = array_intersect_key($row, array_flip(['area', 'subcategory', 'activity', 'fuel', 'unit', 'method']));
            ksort($criteria);
            $this->catalogCriteria[json_encode($criteria, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)] = true;
        }
    }

    public function testEveryMappingReturnedForTheSupportedInputDomainExistsExactlyInTheCsv(): void
    {
        $countries = ['ES', 'FR'];
        $modes = [
            'car', 'taxi', 'passenger_van', 'minibus', 'urban_bus', 'metro', 'tram', 'commuter_train',
            'motorcycle', 'plane', 'long_distance_train', 'coach', 'passenger_ferry', 'freight_van',
            'rigid_truck', 'articulated_truck', 'freight_train', 'air_freight', 'freight_ship', 'courier', 'cargo_bike',
        ];
        $methods = ['distance', 'route', 'route_stops', 'passenger_distance', 'weight_distance', 'tonne_km', 'route_weight'];
        $types = [null, 'petrol', 'diesel', 'hev', 'lpg', 'cng', 'phev', 'bev'];
        $sizes = [null, 'average', 'small', 'medium', 'large'];

        foreach ($countries as $country) {
            foreach ($modes as $mode) {
                foreach ($methods as $method) {
                    foreach ($types as $type) {
                        foreach ($sizes as $size) {
                            $mapping = $this->mapper->map($this->input($country, $mode, $method, vehicleType: $type, carSize: $size));
                            if (null !== $mapping) {
                                $this->assertCatalogContains($mapping->criteria);
                            }
                        }
                    }
                }
            }
        }

        $fuelAliases = ['petrol', 'diesel', 'hvo', 'biodiesel', 'bioethanol', 'lpg', 'cng', 'lng'];
        foreach ($countries as $country) {
            foreach ($modes as $mode) {
                foreach ($fuelAliases as $fuel) {
                    $mapping = $this->mapper->map($this->input($country, $mode, 'fuel', fuel: $fuel));
                    if (null !== $mapping) {
                        $this->assertCatalogContains($mapping->criteria);
                    }
                    foreach (['hev', 'phev'] as $type) {
                        $mapping = $this->mapper->map($this->input($country, $mode, 'fuel', vehicleType: $type, thermalFuel: $fuel));
                        if (null !== $mapping) {
                            $this->assertCatalogContains($mapping->criteria);
                        }
                    }
                }
            }
        }
    }

    public function testCriticalApprovedMappingsUseTheExactCatalogRows(): void
    {
        self::assertSame('Metro', $this->map('ES', 'metro', 'route')['activity']);
        self::assertSame('Metro', $this->map('DE', 'metro', 'route')['activity']);
        self::assertSame('Eléctrico', $this->map('ES', 'freight_train', 'tonne_km')['fuel']);
        self::assertSame('Vuelo (internacional)', $this->map('ES', 'air_freight', 'tonne_km')['activity']);
        self::assertSame('Buque (carga general diversa)', $this->map('US', 'freight_ship', 'tonne_km')['activity']);
        self::assertSame(
            ['Coche pequeño (< 1.400 cc)', 'Gasolina'],
            array_values(array_intersect_key($this->map('FR', 'car', 'distance', 'petrol', 'small'), array_flip(['activity', 'fuel']))),
        );
        self::assertNull($this->mapper->map($this->input('FR', 'car', 'distance', vehicleType: 'lpg', carSize: 'small')));
        self::assertSame('Moto promedio', $this->map('FR', 'motorcycle', 'fuel', fuel: 'petrol')['activity']);
    }

    public function testMapperRejectsCategoryModeAndModeMethodOutsideTheUiContract(): void
    {
        $wrongCategory = new TransportEmissionInput(
            'local', 'plane', 'route', 'ES', new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-01'), '1', 'km', passengers: '1', routeClassification: 'domestic',
        );
        self::assertFalse($this->mapper->supportsUiCombination($wrongCategory));
        self::assertNull($this->mapper->map($wrongCategory));

        $wrongMethod = $this->input('ES', 'car', 'route_stops', vehicleType: 'petrol');
        self::assertFalse($this->mapper->supportsUiCombination($wrongMethod));
        self::assertNull($this->mapper->map($wrongMethod));
    }

    /** @return array<string, string> */
    private function map(
        string $country,
        string $mode,
        string $method,
        ?string $type = null,
        ?string $size = null,
        ?string $fuel = null,
    ): array
    {
        $mapping = $this->mapper->map($this->input($country, $mode, $method, vehicleType: $type, carSize: $size, fuel: $fuel));
        self::assertNotNull($mapping);

        return $mapping->criteria;
    }

    /** @param array<string, string> $criteria */
    private function assertCatalogContains(array $criteria): void
    {
        ksort($criteria);
        self::assertArrayHasKey(
            json_encode($criteria, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $this->catalogCriteria,
            'Mapper criteria must exist exactly in transport_factors_v20.csv.',
        );
    }

    private function input(
        string $country,
        string $mode,
        string $method,
        ?string $vehicleType = null,
        ?string $carSize = null,
        ?string $fuel = null,
        ?string $thermalFuel = null,
    ): TransportEmissionInput {
        return new TransportEmissionInput(
            $this->categoryForMode($mode), $mode, $method, $country,
            new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-01'), '1', 'km',
            vehicleType: $vehicleType, carSize: $carSize, fuel: $fuel, thermalFuel: $thermalFuel,
        );
    }

    private function categoryForMode(string $mode): string
    {
        if (in_array($mode, ['plane', 'long_distance_train', 'coach', 'passenger_ferry'], true)) {
            return 'travel';
        }

        if (in_array($mode, ['freight_van', 'rigid_truck', 'articulated_truck', 'freight_train', 'air_freight', 'freight_ship', 'courier', 'cargo_bike'], true)) {
            return 'freight';
        }

        return 'local';
    }
}
