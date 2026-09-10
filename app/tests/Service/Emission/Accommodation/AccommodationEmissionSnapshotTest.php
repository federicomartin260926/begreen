<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Accommodation;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Service\Emission\Accommodation\AccommodationEmissionInput;
use App\Service\Emission\Accommodation\AccommodationEmissionResult;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Accommodation\AccommodationFactorTrace;
use PHPUnit\Framework\TestCase;

final class AccommodationEmissionSnapshotTest extends TestCase
{
    public function testRoundTripPreservesInputResultPresentationAndProxyTrace(): void
    {
        $input = new AccommodationEmissionInput(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-12-31'),
            'ESP',
            AccommodationEmissionInput::TYPE_HOSTEL,
            null,
            null,
            '3',
            '2',
        );
        $trace = new AccommodationFactorTrace(
            accommodationType: AccommodationEmissionInput::TYPE_HOSTEL,
            factorId: null,
            temporalType: EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            activityYear: 2025,
            factorYear: 2024,
            factorVersion: null,
            baseFactorValue: '9.5505',
            effectiveFactorValue: '1.59175',
            baseFactorUnit: 'kgCO2e/occupied room-night',
            effectiveFactorUnit: 'kgCO2e/guest-night',
            source: 'Greenview Hotel Footprinting Tool',
            sourceDetail: null,
            isFallback: true,
            fallbackReason: 'exact_year_missing',
            isGeographicProxy: false,
            proxyReason: 'Legacy Travel & Climate v5.1 proxy',
            proxyGeography: null,
            qualityStatus: null,
            averageOccupancy: '1.5',
            hostelReductionFactor: '0.25',
            normalizedAmount: '6',
            normalizedUnit: 'guest-night',
            emissionKgCo2e: '9.5505',
            criteria: ['accommodationType' => 'hotel', 'iso3' => 'ESP', 'stars' => '4'],
            metadata: ['dataset' => 'CHSB 2026', 'toolVersion' => 'Greenview HFT 2026v1.1'],
        );
        $result = new AccommodationEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '9.5505',
            '6',
            'guest-night',
            2025,
            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            [$trace],
        );
        $snapshot = new AccommodationEmissionSnapshot();
        $encoded = $snapshot->encode($input, $result, ['label' => 'Hostal equipo']);
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $decoded = $snapshot->decodeInput($encoded);

        self::assertSame('accommodation-v1', $data['version']);
        self::assertSame('9.5505', $data['calculation']['emissionKgCo2e']);
        self::assertSame('9.5505', $data['calculation']['factorTraces'][0]['baseFactorValue']);
        self::assertSame('1.59175', $data['calculation']['factorTraces'][0]['effectiveFactorValue']);
        self::assertSame('Greenview HFT 2026v1.1', $data['calculation']['factorTraces'][0]['metadata']['toolVersion']);
        self::assertSame('Hostal equipo', $snapshot->decodePresentation($encoded)['label']);
        self::assertSame('2025-01-01', $decoded->startDate->format('Y-m-d'));
        self::assertSame('2025-12-31', $decoded->endDate->format('Y-m-d'));
        self::assertSame('ESP', $decoded->iso3);
        self::assertSame(AccommodationEmissionInput::TYPE_HOSTEL, $decoded->accommodationType);
        self::assertNull($decoded->stars);
        self::assertNull($decoded->occupiedRooms);
        self::assertSame('3', $decoded->nights);
        self::assertSame('2', $decoded->people);
    }

    public function testPendingInputRoundTripAndInvalidSnapshotsAreHandledStrictly(): void
    {
        $snapshot = new AccommodationEmissionSnapshot();
        $input = new AccommodationEmissionInput(null, null, null, null, null, null, null, null);
        $result = new AccommodationEmissionResult(EmissionRecord::STATUS_PENDING_DATA, null, null, null, null, null);
        $decoded = $snapshot->decodeInput($snapshot->encode($input, $result));

        self::assertNull($decoded->startDate);
        self::assertNull($decoded->endDate);
        self::assertNull($decoded->iso3);
        self::assertNull($decoded->accommodationType);
        self::assertNull($decoded->stars);
        self::assertNull($decoded->occupiedRooms);
        self::assertNull($decoded->nights);
        self::assertNull($decoded->people);

        foreach (['not-json', '{"version":"accommodation-v0","input":{}}'] as $invalid) {
            try {
                $snapshot->decodeInput($invalid);
                self::fail('Invalid accommodation snapshot must be rejected.');
            } catch (\JsonException|\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
