<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\ProjectPhaseDate;
use App\Service\Bgos\BgosCompletionCalculator;
use App\Service\Bgos\BgosCompletionResult;
use App\Service\Bgos\BgosDailyRecord;
use PHPUnit\Framework\TestCase;

final class BgosCompletionCalculatorTest extends TestCase
{
    public function testDailyFrequencyCountsCompleteAndPendingDays(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_DAILY,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-12',
            today: '2026-09-12',
            records: [
                $this->record('2026-09-10'),
                $this->record('2026-09-12'),
            ],
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_PENDING, $result->status);
        self::assertSame(3, $result->expectedCount);
        self::assertSame(2, $result->completedCount);
        self::assertSame(1, $result->pendingCount);
        self::assertEqualsWithDelta(200 / 3, $result->completionPercentage(), 0.000001);
    }

    public function testDuplicateRecordsOnSameDayCountOnce(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_DAILY,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-10',
            today: '2026-09-10',
            records: [
                $this->record('2026-09-10'),
                $this->record('2026-09-10', status: 'DRAFT'),
            ],
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_COMPLETE, $result->status);
        self::assertSame(1, $result->expectedCount);
        self::assertSame(1, $result->completedCount);
        self::assertSame(0, $result->pendingCount);
        self::assertSame(100.0, $result->completionPercentage());
    }

    public function testFutureDaysDoNotReduceCompletion(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_DAILY,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-20',
            today: '2026-09-12',
            records: [
                $this->record('2026-09-10'),
                $this->record('2026-09-11'),
                $this->record('2026-09-12'),
            ],
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_COMPLETE, $result->status);
        self::assertSame(3, $result->expectedCount);
        self::assertSame(3, $result->completedCount);
        self::assertSame(100.0, $result->completionPercentage());
    }

    public function testFullyFuturePeriodHasNoExpectation(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_DAILY,
            phaseStart: '2026-09-20',
            phaseEnd: '2026-09-22',
            today: '2026-09-17',
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_FUTURE, $result->status);
        self::assertSame(0, $result->expectedCount);
        self::assertNull($result->completionPercentage());
    }

    public function testPunctualWithoutRecordIsNoDataButNotPending(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_PUNCTUAL,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-12',
            today: '2026-09-12',
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_NO_DATA, $result->status);
        self::assertSame(1, $result->expectedCount);
        self::assertSame(0, $result->completedCount);
        self::assertSame(0, $result->pendingCount);
        self::assertSame(0.0, $result->completionPercentage());
    }

    public function testPunctualWithRecordIsCompleteRegardlessOfRecordStatus(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_PUNCTUAL,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-12',
            today: '2026-09-12',
            records: [
                $this->record('2026-09-11', status: 'PENDING_DATA'),
            ],
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_COMPLETE, $result->status);
        self::assertSame(1, $result->expectedCount);
        self::assertSame(1, $result->completedCount);
        self::assertSame(0, $result->pendingCount);
    }

    public function testNotApplicableAndInactiveDoNotEnterDenominator(): void
    {
        $notApplicable = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-12',
            today: '2026-09-12',
        );

        $inactive = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_DAILY,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-12',
            today: '2026-09-12',
            active: false,
        );

        self::assertNotNull($notApplicable);
        self::assertSame(BgosCompletionResult::STATUS_NOT_APPLICABLE, $notApplicable->status);
        self::assertSame(0, $notApplicable->expectedCount);
        self::assertNull($notApplicable->completionPercentage());

        self::assertNotNull($inactive);
        self::assertSame(BgosCompletionResult::STATUS_NOT_APPLICABLE, $inactive->status);
        self::assertSame(0, $inactive->expectedCount);
        self::assertNull($inactive->completionPercentage());
    }

    public function testRecordsFromOtherIdentityOrPhaseAreIgnored(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_DAILY,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-10',
            today: '2026-09-10',
            records: [
                $this->record('2026-09-10', categoryKey: 'energy'),
                $this->record('2026-09-10', subcategoryKey: 'freight'),
                $this->record('2026-09-10', phaseKey: 'preproduccion'),
            ],
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_PENDING, $result->status);
        self::assertSame(1, $result->pendingCount);
    }

    public function testPeriodOutsidePhaseIsNotApplicable(): void
    {
        $result = $this->calculate(
            frequency: BgosSubcategoryConfig::FREQUENCY_DAILY,
            phaseStart: '2026-09-10',
            phaseEnd: '2026-09-12',
            periodStart: '2026-09-01',
            periodEnd: '2026-09-05',
            today: '2026-09-17',
        );

        self::assertNotNull($result);
        self::assertSame(BgosCompletionResult::STATUS_NOT_APPLICABLE, $result->status);
        self::assertSame(0, $result->expectedCount);
    }

    public function testInvalidPhaseOrPeriodIsHandledSafely(): void
    {
        $calculator = new BgosCompletionCalculator();

        $config = $this->config(BgosSubcategoryConfig::FREQUENCY_DAILY);

        $invalidPhase = (new ProjectPhaseDate())
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-09-12'))
            ->setEndDate(new \DateTimeImmutable('2026-09-10'));

        self::assertNull($calculator->calculate(
            $config,
            $invalidPhase,
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-12'),
            new \DateTimeImmutable('2026-09-12'),
            [],
        ));

        $this->expectException(\InvalidArgumentException::class);

        $calculator->calculate(
            $config,
            $this->phase('2026-09-10', '2026-09-12'),
            new \DateTimeImmutable('2026-09-12'),
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-12'),
            [],
        );
    }

    /**
     * @param list<BgosDailyRecord> $records
     */
    private function calculate(
        string $frequency,
        string $phaseStart,
        string $phaseEnd,
        string $today,
        array $records = [],
        bool $active = true,
        string $periodStart = '2026-09-01',
        string $periodEnd = '2026-09-30',
    ): ?BgosCompletionResult {
        return (new BgosCompletionCalculator())->calculate(
            $this->config($frequency, $active),
            $this->phase($phaseStart, $phaseEnd),
            new \DateTimeImmutable($periodStart),
            new \DateTimeImmutable($periodEnd),
            new \DateTimeImmutable($today),
            $records,
        );
    }

    private function config(
        string $frequency,
        bool $active = true,
    ): BgosSubcategoryConfig {
        return (new BgosSubcategoryConfig())
            ->setCategoryKey('transport')
            ->setSubcategoryKey('people')
            ->setLabel('Personas')
            ->setActive($active)
            ->setActivityFrequency($frequency);
    }

    private function phase(string $startDate, string $endDate): ProjectPhaseDate
    {
        return (new ProjectPhaseDate())
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable($startDate))
            ->setEndDate(new \DateTimeImmutable($endDate));
    }

    private function record(
        string $date,
        string $categoryKey = 'transport',
        string $subcategoryKey = 'people',
        string $phaseKey = 'actividad',
        string $status = 'CALCULATED',
    ): BgosDailyRecord {
        return new BgosDailyRecord(
            recordId: random_int(1, 1000000),
            categoryKey: $categoryKey,
            subcategoryKey: $subcategoryKey,
            phaseKey: $phaseKey,
            date: new \DateTimeImmutable($date),
            kgCo2e: 10.0,
            status: $status,
        );
    }
}
