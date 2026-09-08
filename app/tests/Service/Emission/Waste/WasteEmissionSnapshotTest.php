<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Waste;

use App\Entity\EmissionRecord;
use App\Service\Emission\Waste\WasteEmissionInput;
use App\Service\Emission\Waste\WasteEmissionResult;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use PHPUnit\Framework\TestCase;

final class WasteEmissionSnapshotTest extends TestCase
{
    public function testRoundTripPreservesInputsCalculationAndPresentation(): void
    {
        $input = new WasteEmissionInput(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-12-31'),
            'ESP',
            'Textil',
            'Moquetas',
            'Vertedero',
            '1.5',
            WasteEmissionInput::UNIT_TONNE,
        );
        $result = new WasteEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '745.17342',
            '1500',
            'kg',
            2025,
            'Moquetas',
            'Vertedero',
        );

        $snapshot = new WasteEmissionSnapshot();
        $encoded = $snapshot->encode($input, $result, ['label' => 'Moquetas set principal']);
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $decoded = $snapshot->decodeInput($encoded);

        self::assertSame(WasteEmissionSnapshot::VERSION, $data['version']);
        self::assertSame(WasteEmissionSnapshot::VERSION, $data['calculatorVersion']);
        self::assertSame('745.17342', $data['calculation']['emissionKgCo2e']);
        self::assertSame('Moquetas set principal', $snapshot->decodePresentation($encoded)['label']);
        self::assertSame('2025-01-01', $decoded->startDate?->format('Y-m-d'));
        self::assertSame('2025-12-31', $decoded->endDate?->format('Y-m-d'));
        self::assertSame('ESP', $decoded->country);
        self::assertSame('Textil', $decoded->wasteType);
        self::assertSame('Moquetas', $decoded->wasteActivity);
        self::assertSame('Vertedero', $decoded->treatment);
        self::assertSame('1.5', $decoded->weight);
        self::assertSame(WasteEmissionInput::UNIT_TONNE, $decoded->weightUnit);
    }

    public function testPendingRoundTripAndInvalidSnapshotsAreRejected(): void
    {
        $snapshot = new WasteEmissionSnapshot();
        $input = new WasteEmissionInput(null, null, null, null, null, null, null, null);
        $result = new WasteEmissionResult(EmissionRecord::STATUS_PENDING_DATA, null, null, null, null);

        $decoded = $snapshot->decodeInput($snapshot->encode($input, $result));
        self::assertNull($decoded->startDate);
        self::assertNull($decoded->country);
        self::assertNull($decoded->wasteType);
        self::assertNull($decoded->weight);

        foreach (['not-json', '{"version":"waste-v0","input":{}}'] as $invalid) {
            try {
                $snapshot->decodeInput($invalid);
                self::fail('Invalid waste snapshot must be rejected.');
            } catch (\JsonException|\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
