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
            'local', 'car', 'distance', 'ES', new \DateTimeImmutable('2026-03-04'),
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
        );
        $snapshot = new TransportEmissionSnapshot();

        $encoded = $snapshot->encode($input, $result);
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $decoded = $snapshot->decode($encoded);

        self::assertSame('transport-v20', $data['version']);
        self::assertSame('12.3400', $data['input']['activityValue']);
        self::assertSame('24.6800', $data['calculation']['normalizedActivityValue']);
        self::assertSame('5.678900', $data['calculation']['generatedKgCo2e']);
        self::assertSame('0.230101', $data['factor']['value']);
        self::assertSame(2025, $data['factor']['factorYear']);
        self::assertSame('MITECO', $data['factor']['source']);
        self::assertTrue($data['factor']['fallback']);
        self::assertSame('exact_year_missing', $data['factor']['fallbackReason']);
        self::assertSame('12.3400', $decoded->activityValue);
        self::assertSame('2026-03-04', $decoded->startedAt->format('Y-m-d'));
        self::assertSame('petrol', $decoded->vehicleType);
    }

    public function testDecodeRejectsUnsupportedVersion(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new TransportEmissionSnapshot())->decode('{"version":"transport-v19","input":{}}');
    }
}
