<?php

namespace App\Tests\Service\Emission\Transport;

use App\Service\Emission\Transport\TransportEmissionPresentationMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TransportEmissionPresentationMapperTest extends TestCase
{
    public function testRouteRequiresOriginDestinationAndTripType(): void
    {
        $mapper = new TransportEmissionPresentationMapper();

        $this->expectException(\InvalidArgumentException::class);

        $mapper->map(Request::create('/', 'POST', [
            'method' => 'route',
            'destination' => 'Toledo',
            'tripType' => 'one_way',
        ]));
    }

    public function testRouteKeepsItsJourneyType(): void
    {
        $result = (new TransportEmissionPresentationMapper())->map(
            Request::create('/', 'POST', [
                'method' => 'route',
                'origin' => 'Madrid',
                'destination' => 'Toledo',
                'tripType' => 'round_trip',
            ])
        );

        self::assertSame('Madrid', $result['origin']);
        self::assertSame('Toledo', $result['destination']);
        self::assertSame('round_trip', $result['tripType']);
    }

    public function testRouteStopsAndRouteWeightDoNotAcceptJourneyType(): void
    {
        $mapper = new TransportEmissionPresentationMapper();

        $stops = $mapper->map(Request::create('/', 'POST', [
            'method' => 'route_stops',
            'origin' => 'Parada A',
            'destination' => 'Parada B',
            'tripType' => 'round_trip',
            'stops' => 'Parada intermedia',
        ]));

        self::assertArrayNotHasKey('tripType', $stops);
        self::assertSame('Parada intermedia', $stops['stops']);

        $weight = $mapper->map(Request::create('/', 'POST', [
            'method' => 'route_weight',
            'origin' => 'MAD',
            'destination' => 'LHR',
            'tripType' => 'round_trip',
        ]));

        self::assertArrayNotHasKey('tripType', $weight);
    }

    public function testOperatorReferenceIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TransportEmissionPresentationMapper())->map(
            Request::create('/', 'POST', [
                'method' => 'operator',
            ])
        );
    }
}
