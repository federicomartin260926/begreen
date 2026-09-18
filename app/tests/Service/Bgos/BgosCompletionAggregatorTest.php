<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Service\Bgos\BgosCompletionAggregator;
use App\Service\Bgos\BgosCompletionResult;
use PHPUnit\Framework\TestCase;

final class BgosCompletionAggregatorTest extends TestCase
{
    public function testAggregatesExpectedCompletedAndPendingCounts(): void
    {
        $aggregate = (new BgosCompletionAggregator())->aggregate([
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_COMPLETE,
                2,
                2,
                0,
            ),
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_PENDING,
                3,
                2,
                1,
            ),
        ]);

        self::assertSame(BgosCompletionResult::STATUS_PENDING, $aggregate->status);
        self::assertSame(5, $aggregate->expectedCount);
        self::assertSame(4, $aggregate->completedCount);
        self::assertSame(1, $aggregate->pendingCount);
        self::assertSame(80.0, $aggregate->completionPercentage());
    }

    public function testNotApplicableAndFutureResultsStayOutsideDenominator(): void
    {
        $aggregate = (new BgosCompletionAggregator())->aggregate([
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_NOT_APPLICABLE,
                0,
                0,
                0,
            ),
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_FUTURE,
                0,
                0,
                0,
            ),
        ]);

        self::assertSame(BgosCompletionResult::STATUS_NO_DATA, $aggregate->status);
        self::assertSame(0, $aggregate->expectedCount);
        self::assertSame(0, $aggregate->completedCount);
        self::assertSame(0, $aggregate->pendingCount);
        self::assertNull($aggregate->completionPercentage());
    }

    public function testPunctualWithoutDataReducesCompletionWithoutBecomingPending(): void
    {
        $aggregate = (new BgosCompletionAggregator())->aggregate([
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_COMPLETE,
                1,
                1,
                0,
            ),
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_NO_DATA,
                1,
                0,
                0,
            ),
        ]);

        self::assertSame(BgosCompletionResult::STATUS_NO_DATA, $aggregate->status);
        self::assertSame(2, $aggregate->expectedCount);
        self::assertSame(1, $aggregate->completedCount);
        self::assertSame(0, $aggregate->pendingCount);
        self::assertSame(50.0, $aggregate->completionPercentage());
    }

    public function testAllExpectedUnitsCompleteProducesCompleteAggregate(): void
    {
        $aggregate = (new BgosCompletionAggregator())->aggregate([
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_COMPLETE,
                2,
                2,
                0,
            ),
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_COMPLETE,
                1,
                1,
                0,
            ),
        ]);

        self::assertSame(BgosCompletionResult::STATUS_COMPLETE, $aggregate->status);
        self::assertSame(100.0, $aggregate->completionPercentage());
    }
}
