<?php

namespace App\Tests\Service\Emission\Transport;

use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use App\Service\Emission\Transport\TransportUiCatalog;
use PHPUnit\Framework\TestCase;

final class TransportTaxiV20Test extends TestCase
{
    public function testSpanishTaxiDistanceRequiresAndMapsTheFiveMasterVehicleTypes(): void
    {
        $catalog = new TransportUiCatalog();
        $mapper = new TransportFactorCriteriaMapper($catalog);

        self::assertSame(
            ['petrol', 'diesel', 'hev', 'lpg', 'cng'],
            $catalog->configuration()['taxiSpainVehicleTypes'],
        );

        foreach ([
            'petrol' => 'Gasolina',
            'diesel' => 'Gasóleo',
            'hev' => 'Híbrido',
            'lpg' => 'LPG',
            'cng' => 'CNG',
        ] as $vehicleType => $expectedFuel) {
            $input = new TransportEmissionInput(
                'local',
                'taxi',
                'route',
                'ES',
                new \DateTimeImmutable('2026-08-05'),
                new \DateTimeImmutable('2026-08-05'),
                '10',
                'km',
                '1',
                vehicleType: $vehicleType,
            );

            self::assertTrue($catalog->supports($input));

            $mapping = $mapper->map($input);
            self::assertNotNull($mapping);
            self::assertSame('ESPAÑA', $mapping->criteria['area']);
            self::assertSame('PRIVADO', $mapping->criteria['subcategory']);
            self::assertSame('Turismos/ Taxis (hasta 8 asientos)', $mapping->criteria['activity']);
            self::assertSame($expectedFuel, $mapping->criteria['fuel']);
            self::assertSame('km', $mapping->criteria['unit']);
            self::assertSame('distancia', $mapping->criteria['method']);
        }
    }

    public function testSpanishTaxiDistanceWithoutVehicleTypeIsNotSupported(): void
    {
        $input = new TransportEmissionInput(
            'local',
            'taxi',
            'route',
            'ES',
            new \DateTimeImmutable('2026-08-05'),
            new \DateTimeImmutable('2026-08-05'),
            '10',
            'km',
        );

        self::assertFalse((new TransportUiCatalog())->supports($input));
        self::assertNull((new TransportFactorCriteriaMapper())->map($input));
    }

    public function testTaxiOutsideSpainKeepsTheGenericMasterFactorWithoutVehicleType(): void
    {
        $input = new TransportEmissionInput(
            'local',
            'taxi',
            'route',
            'FR',
            new \DateTimeImmutable('2026-08-05'),
            new \DateTimeImmutable('2026-08-05'),
            '10',
            'km',
        );

        $catalog = new TransportUiCatalog();
        $mapping = (new TransportFactorCriteriaMapper($catalog))->map($input);

        self::assertTrue($catalog->supports($input));
        self::assertNotNull($mapping);
        self::assertSame('FUERA DE ESPAÑA', $mapping->criteria['area']);
        self::assertSame('PÚBLICO', $mapping->criteria['subcategory']);
        self::assertSame('Taxi regular', $mapping->criteria['activity']);
        self::assertSame('Desconocido', $mapping->criteria['fuel']);
        self::assertSame('km', $mapping->criteria['unit']);
    }

    public function testOperatorMethodDoesNotRequireVehicleType(): void
    {
        $input = new TransportEmissionInput(
            'local',
            'taxi',
            'operator',
            'ES',
            new \DateTimeImmutable('2026-08-05'),
            new \DateTimeImmutable('2026-08-05'),
            '2',
            'kg_co2e',
        );

        self::assertTrue((new TransportUiCatalog())->supports($input));
    }
}
