<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final readonly class BgosCompletionAggregate
{
    public function __construct(
        public string $status,
        public int $expectedCount,
        public int $completedCount,
        public int $pendingCount,
    ) {
    }

    public function completionPercentage(): ?float
    {
        if (0 === $this->expectedCount) {
            return null;
        }

        return ($this->completedCount / $this->expectedCount) * 100;
    }
}
