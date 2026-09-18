<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Service\Bgos\BgosDailyRecordProjector;
use App\Service\Bgos\BgosTemporalRecord;
use PHPUnit\Framework\TestCase;

final class BgosDailyRecordProjectorTest extends TestCase
{
    public function testProjectsSingleDayRecordWithoutChangingEmission(): void
    {
        $record = $this->record(
            '2026-09-10',
            '2026-09-10',
            90.0,
        );

        $days = (new BgosDailyRecordProjector())->project($record);

        self::assertCount(1, $days);
        self::assertSame('2026-09-10', $days[0]->date->format('Y-m-d'));
        self::assertSame(90.0, $days[0]->kgCo2e);
        self::assertSame(42, $days[0]->recordId);
        self::assertSame('transport', $days[0]->categoryKey);
        self::assertSame('people', $days[0]->subcategoryKey);
        self::assertSame('actividad', $days[0]->phaseKey);
        self::assertSame('calculated', $days[0]->status);
    }

    public function testSplitsEmissionEquallyAcrossInclusiveRange(): void
    {
        $record = $this->record(
            '2026-09-10',
            '2026-09-12',
            90.0,
        );

        $days = (new BgosDailyRecordProjector())->project($record);

        self::assertCount(3, $days);
        self::assertSame(
            ['2026-09-10', '2026-09-11', '2026-09-12'],
            array_map(
                static fn ($day): string => $day->date->format('Y-m-d'),
                $days,
            ),
        );

        foreach ($days as $day) {
            self::assertSame(30.0, $day->kgCo2e);
        }

        self::assertSame(
            90.0,
            array_sum(array_map(static fn ($day): float => $day->kgCo2e, $days)),
        );
    }

    public function testKeepsNullEmissionAcrossWholeRange(): void
    {
        $record = $this->record(
            '2026-09-10',
            '2026-09-12',
            null,
        );

        $days = (new BgosDailyRecordProjector())->project($record);

        self::assertCount(3, $days);

        foreach ($days as $day) {
            self::assertNull($day->kgCo2e);
        }
    }

    public function testDoesNotRoundFractionalDailyEmission(): void
    {
        $record = $this->record(
            '2026-09-10',
            '2026-09-12',
            100.0,
        );

        $days = (new BgosDailyRecordProjector())->project($record);

        self::assertCount(3, $days);
        self::assertEqualsWithDelta(100 / 3, $days[0]->kgCo2e, 0.0000001);
        self::assertEqualsWithDelta(100.0, array_sum(array_map(
            static fn ($day): float => $day->kgCo2e,
            $days,
        )), 0.0000001);
    }

    private function record(
        string $startDate,
        string $endDate,
        ?float $kgCo2e,
    ): BgosTemporalRecord {
        return new BgosTemporalRecord(
            recordId: 42,
            categoryKey: 'transport',
            subcategoryKey: 'people',
            phaseKey: 'actividad',
            startDate: new \DateTimeImmutable($startDate),
            endDate: new \DateTimeImmutable($endDate),
            totalKgCo2e: $kgCo2e,
            status: 'calculated',
        );
    }
}
