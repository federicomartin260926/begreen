<?php

namespace App\Tests\Service\Emission\Transport;

use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionResult;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use PHPUnit\Framework\TestCase;

final class TransportEmissionSnapshotTest extends TestCase
{
    public function testSnapshotPreservesVersionDecimalStringsAndFactorProvenance(): void
    {
        $input = new TransportEmissionInput(
            'local', 'car', 'distance', 'ES',
            new \DateTimeImmutable('2026-03-04'), new \DateTimeImmutable('2026-03-06'),
            '12.3400', 'km', '2', vehicleType: 'petrol', carSize: 'average',
        );
        $result = new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED,
            '24.6800',
            'km',
            '5.678900',
            ['area' => 'ESPAÑA'],
            'functional-key',
            2026,
            2025,
            '0.230101',
            'km',
            'MITECO',
            'Detalle',
            true,
            'exact_year_missing',
            factorId: 'TRA_TEST',
            factorActivityYear: 2025,
            temporalType: 'ANNUAL',
            factorVersion: '2025.1',
            isGeographicProxy: true,
            proxyGeography: 'ESPAÑA',
            qualityStatus: 'verified',
            factorMetadata: ['sourceWorkbook' => 'master.xlsx'],
        );
        $snapshot = new TransportEmissionSnapshot();

        $encoded = $snapshot->encode($input, $result);
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $decoded = $snapshot->decode($encoded);

        self::assertSame('transport-v20', $data['version']);
        self::assertSame('transport-v20', $data['calculatorVersion']);
        self::assertSame('12.3400', $data['input']['activityValue']);
        self::assertSame('24.6800', $data['calculation']['normalizedActivityValue']);
        self::assertSame('5.678900', $data['calculation']['generatedKgCo2e']);
        self::assertSame('0.230101', $data['factor']['value']);
        self::assertSame(2025, $data['factor']['factorYear']);
        self::assertSame(2025, $data['factor']['activityYear']);
        self::assertSame('TRA_TEST', $data['factor']['factorId']);
        self::assertSame('ANNUAL', $data['factor']['temporalType']);
        self::assertSame('2025.1', $data['factor']['factorVersion']);
        self::assertTrue($data['factor']['isTemporalFallback']);
        self::assertTrue($data['factor']['isGeographicProxy']);
        self::assertSame('ESPAÑA', $data['factor']['proxyGeography']);
        self::assertSame('verified', $data['factor']['qualityStatus']);
        self::assertSame(['sourceWorkbook' => 'master.xlsx'], $data['factor']['metadata']);
        self::assertSame('MITECO', $data['factor']['source']);
        self::assertTrue($data['factor']['fallback']);
        self::assertSame('exact_year_missing', $data['factor']['fallbackReason']);
        self::assertSame('12.3400', $decoded->activityValue);
        self::assertSame('2026-03-04', $data['input']['startDate']);
        self::assertSame('2026-03-06', $data['input']['endDate']);
        self::assertArrayNotHasKey('startedAt', $data['input']);
        self::assertSame('2026-03-04', $decoded->startDate->format('Y-m-d'));
        self::assertSame('2026-03-06', $decoded->endDate->format('Y-m-d'));
        self::assertSame('petrol', $decoded->vehicleType);
    }

    public function testDecodeRejectsUnsupportedVersion(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new TransportEmissionSnapshot())->decode('{"version":"transport-v19","input":{}}');
    }

    public function testPresentationRoundTripsWithoutChangingInputCalculationOrFactor(): void
    {
        $input = new TransportEmissionInput(
            'local', 'taxi', 'route', 'ES', new \DateTimeImmutable('2026-03-04'), new \DateTimeImmutable('2026-03-04'), '20', 'km', passengers: '2',
        );
        $result = new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED, '20', 'km', '3', [], 'key', 2026, 2025, '0.15', 'km', 'MITECO',
        );
        $snapshot = new TransportEmissionSnapshot();
        $withoutPresentation = json_decode($snapshot->encode($input, $result), true, 512, JSON_THROW_ON_ERROR);
        $presentation = ['origin' => 'Madrid', 'destination' => 'Toledo', 'tripType' => 'round_trip'];
        $withPresentation = json_decode($snapshot->encode($input, $result, $presentation), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($presentation, $snapshot->decodePresentation(json_encode($withPresentation, JSON_THROW_ON_ERROR)));
        self::assertSame($withoutPresentation['input'], $withPresentation['input']);
        self::assertSame($withoutPresentation['calculation'], $withPresentation['calculation']);
        self::assertSame($withoutPresentation['factor'], $withPresentation['factor']);
    }

    public function testSummaryExposesModeDetailAndNormalizedUnit(): void
    {
        $input = new TransportEmissionInput(
            'local',
            'car',
            'distance',
            'ES',
            new \DateTimeImmutable('2026-08-05'),
            new \DateTimeImmutable('2026-08-05'),
            '10',
            'km',
            vehicleType: 'petrol',
            carSize: 'small',
        );
        $result = new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED,
            '10',
            'km',
            '1.83',
            [],
            'key',
            2026,
            2025,
            '0.183',
            'km',
            'MITECO',
        );

        $summary = (new TransportEmissionSnapshot())->decodeSummary(
            (new TransportEmissionSnapshot())->encode($input, $result)
        );

        self::assertSame('car', $summary['mode']);
        self::assertSame('vehicle_type', $summary['detailKind']);
        self::assertSame('petrol', $summary['detailCode']);
        self::assertSame('km', $summary['normalizedActivityUnit']);
        self::assertSame('km', $summary['displayActivityUnit']);
    }

    public function testSummaryMapsCanonicalFactorUnitsToUiUnits(): void
    {
        $snapshot = new TransportEmissionSnapshot();

        $passengerInput = new TransportEmissionInput(
            'local',
            'metro',
            'passenger_distance',
            'ES',
            new \DateTimeImmutable('2026-08-05'),
            new \DateTimeImmutable('2026-08-05'),
            '10',
            'passenger-km',
        );
        $passengerResult = new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED,
            '10',
            'km*pasajero',
            '0.3828',
            [],
            'passenger-key',
            2026,
            2025,
            '0.03828',
            'km*pasajero',
            'MITECO',
        );

        $passengerSummary = $snapshot->decodeSummary(
            $snapshot->encode($passengerInput, $passengerResult)
        );

        self::assertSame('km*pasajero', $passengerSummary['normalizedActivityUnit']);
        self::assertSame('passenger-km', $passengerSummary['displayActivityUnit']);

        $freightInput = new TransportEmissionInput(
            'freight',
            'rigid_truck',
            'tonne_km',
            'ES',
            new \DateTimeImmutable('2026-08-05'),
            new \DateTimeImmutable('2026-08-05'),
            '10',
            't-km',
        );
        $freightResult = new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED,
            '10',
            'km*tonelada',
            '1',
            [],
            'freight-key',
            2026,
            2025,
            '0.1',
            'km*tonelada',
            'MITECO',
        );

        $freightSummary = $snapshot->decodeSummary(
            $snapshot->encode($freightInput, $freightResult)
        );

        self::assertSame('t-km', $freightSummary['displayActivityUnit']);
    }


    public function testOldSnapshotWithoutPresentationStillDecodesInput(): void
    {
        $input = new TransportEmissionInput(
            'local', 'walk', 'distance', 'ES', new \DateTimeImmutable('2026-03-04'), new \DateTimeImmutable('2026-03-04'), '2', 'km',
        );
        $result = new TransportEmissionResult(
            TransportEmissionResult::STATUS_DIRECT_ZERO, '2', 'km', '0', null, null, 2026,
        );
        $snapshot = new TransportEmissionSnapshot();
        $encoded = $snapshot->encode($input, $result);

        self::assertSame('2', $snapshot->decode($encoded)->activityValue);
        self::assertSame([], $snapshot->decodePresentation($encoded));
    }

    public function testHistoricalStartedAtSnapshotDecodesAsSameStartAndEndDate(): void
    {
        $snapshot = new TransportEmissionSnapshot();
        $input = new TransportEmissionInput(
            'local', 'walk', 'distance', 'ES', new \DateTimeImmutable('2026-03-04'), new \DateTimeImmutable('2026-03-04'), '2', 'km',
        );
        $result = new TransportEmissionResult(
            TransportEmissionResult::STATUS_DIRECT_ZERO, '2', 'km', '0', null, null, 2026,
        );
        $data = json_decode($snapshot->encode($input, $result), true, 512, JSON_THROW_ON_ERROR);
        $data['input']['startedAt'] = $data['input']['startDate'];
        unset($data['input']['startDate'], $data['input']['endDate']);

        $decoded = $snapshot->decode(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertSame('2026-03-04', $decoded->startDate->format('Y-m-d'));
        self::assertSame('2026-03-04', $decoded->endDate->format('Y-m-d'));
    }
}
