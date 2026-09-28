<?php

namespace App\Service\Animation;

final readonly class AnimationComplianceSummary
{
    public function __construct(
        public int $fulfilledCount,
        public int $notFulfilledCount,
        public int $notApplicableCount,
        public int $pendingCount,
        public int $inProgressCount,
        public int $discardedCount,
        public int $denominator,
        public ?float $percentage,
    ) {
    }

    public function hasEvaluableMeasures(): bool
    {
        return $this->denominator > 0;
    }
}
