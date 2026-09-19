<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Service\Bgos\BgosCrewMobilityValidator;
use App\Service\Emission\Transport\TransportUiCatalog;
use PHPUnit\Framework\TestCase;

final class BgosCrewMobilityValidatorTest extends TestCase
{
    public function testAcceptsTransportPeopleCatalogValues(): void
    {
        $validator = new BgosCrewMobilityValidator(
            new TransportUiCatalog()
        );

        $validator->assertSupported(
            'car',
            'diesel',
            'diesel',
            null,
        );

        self::assertSame('Madrid', $validator->normalize('  Madrid  '));
        self::assertNull($validator->normalize('   '));
    }

    public function testAcceptsTravelModesForPeople(): void
    {
        $validator = new BgosCrewMobilityValidator(
            new TransportUiCatalog()
        );

        $validator->assertSupported(
            'long_distance_train',
            null,
            null,
            null,
        );

        self::assertTrue(true);
    }

    public function testRejectsFreightModeForCrew(): void
    {
        $validator = new BgosCrewMobilityValidator(
            new TransportUiCatalog()
        );

        $this->expectException(\InvalidArgumentException::class);

        $validator->assertSupported(
            'freight_van',
            null,
            null,
            null,
        );
    }

    public function testRejectsUnknownVehicleType(): void
    {
        $validator = new BgosCrewMobilityValidator(
            new TransportUiCatalog()
        );

        $this->expectException(\InvalidArgumentException::class);

        $validator->assertSupported(
            'car',
            'steam',
            null,
            null,
        );
    }
}
