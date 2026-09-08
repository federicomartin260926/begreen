<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Catering;

use App\Entity\EmissionRecord;
use App\Service\Emission\Catering\CateringEmissionInput;
use App\Service\Emission\Catering\CateringEmissionResult;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Catering\CateringMenuLine;
use PHPUnit\Framework\TestCase;

final class CateringEmissionSnapshotTest extends TestCase
{
    public function testRoundTripPreservesMealWithMultipleLinesAndCalculation(): void
    {
        $input = new CateringEmissionInput(
            new \DateTimeImmutable('2025-12-31'),
            new \DateTimeImmutable('2026-01-01'),
            'ESP',
            CateringEmissionInput::TYPE_MEAL,
            menuLines: [
                new CateringMenuLine('vegan', '100', '90'),
                new CateringMenuLine('beef', '20', '18'),
            ],
            tablewareType: 'compostable',
        );
        $result = new CateringEmissionResult(EmissionRecord::STATUS_CALCULATED, '156.975', '120', 'prepared_menu', 2025);
        $snapshot = new CateringEmissionSnapshot();
        $encoded = $snapshot->encode($input, $result, ['label' => 'Catering principal']);
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $decoded = $snapshot->decodeInput($encoded);

        self::assertSame('catering-v1', $data['version']);
        self::assertSame('156.975', $data['calculation']['emissionKgCo2e']);
        self::assertSame('Catering principal', $snapshot->decodePresentation($encoded)['label']);
        self::assertSame('2025-12-31', $decoded->startDate->format('Y-m-d'));
        self::assertSame('2026-01-01', $decoded->endDate->format('Y-m-d'));
        self::assertSame('ESP', $decoded->country);
        self::assertSame('compostable', $decoded->tablewareType);
        self::assertCount(2, $decoded->menuLines);
        self::assertSame('beef', $decoded->menuLines[1]->menuVariant);
        self::assertSame('20', $decoded->menuLines[1]->preparedCount);
        self::assertSame('18', $decoded->menuLines[1]->consumedCount);
    }

    public function testRoundTripPreservesNonCalculableActivity(): void
    {
        $input = new CateringEmissionInput(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-01-02'),
            'ESP',
            CateringEmissionInput::TYPE_WATER,
            containerVolumeLiters: '0.5',
            containerMaterial: 'glass',
            containerCount: '6',
        );
        $result = new CateringEmissionResult(
            EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
            null,
            '3',
            'L',
            2025,
            messages: ['automatic_factor_unavailable'],
        );

        $decoded = (new CateringEmissionSnapshot())->decodeInput((new CateringEmissionSnapshot())->encode($input, $result));

        self::assertSame(CateringEmissionInput::TYPE_WATER, $decoded->activityType);
        self::assertSame('0.5', $decoded->containerVolumeLiters);
        self::assertSame('glass', $decoded->containerMaterial);
        self::assertSame('6', $decoded->containerCount);
        self::assertSame([], $decoded->menuLines);
    }

    public function testWrongVersionIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new CateringEmissionSnapshot())->decodeInput('{"version":"catering-v0","input":{}}');
    }
}
