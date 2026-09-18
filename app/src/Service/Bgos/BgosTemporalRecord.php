<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final readonly class BgosTemporalRecord
{
    public function __construct(
        public int $recordId,
        public string $categoryKey,
        public string $subcategoryKey,
        public string $phaseKey,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?float $totalKgCo2e,
        public string $status,
    ) {
    }
}
