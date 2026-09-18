<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final readonly class BgosCompletionResult
{
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_PENDING = 'pending';
    public const STATUS_NO_DATA = 'no_data';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';
    public const STATUS_FUTURE = 'future';

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
