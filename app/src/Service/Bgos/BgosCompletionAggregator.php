<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final class BgosCompletionAggregator
{
    /**
     * @param iterable<BgosCompletionResult> $results
     */
    public function aggregate(iterable $results): BgosCompletionAggregate
    {
        $expectedCount = 0;
        $completedCount = 0;
        $pendingCount = 0;

        foreach ($results as $result) {
            $expectedCount += $result->expectedCount;
            $completedCount += $result->completedCount;
            $pendingCount += $result->pendingCount;
        }

        $status = match (true) {
            0 === $expectedCount => BgosCompletionResult::STATUS_NO_DATA,
            $completedCount === $expectedCount => BgosCompletionResult::STATUS_COMPLETE,
            $pendingCount > 0 => BgosCompletionResult::STATUS_PENDING,
            default => BgosCompletionResult::STATUS_NO_DATA,
        };

        return new BgosCompletionAggregate(
            status: $status,
            expectedCount: $expectedCount,
            completedCount: $completedCount,
            pendingCount: $pendingCount,
        );
    }
}
