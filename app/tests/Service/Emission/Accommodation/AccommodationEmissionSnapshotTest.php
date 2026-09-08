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
            AccommodationEmissionInput::TYPE_HOSTEL,
            EmissionFactor::TEMPORAL_TYPE_ANNUAL,
            2025,
            2024,
            '9.5505',
            '1.59175',
            'kgCO2e/occupied room-night',
            'kgCO2e/guest-night',
            'Greenview Hotel Footprinting Tool',
            null,
            true,
            'exact_year_missing',
            false,
            'Travel & Climate v5.1 proxy derived from the Greenview hotel 4-star factor',
            '1.5',
            '0.25',
            '6',
            'guest-night',
            '9.5505',
            ['accommodationType' => 'hotel', 'iso3' => 'ESP', 'stars' => '4'],
            ['dataset' => 'CHSB 2026', 'toolVersion' => 'Greenview HFT 2026v1.1'],
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
