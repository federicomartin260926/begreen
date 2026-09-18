<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final readonly class BgosDailyRecord
{
    public function __construct(
        public int $recordId,
        public string $categoryKey,
        public string $subcategoryKey,
        public string $phaseKey,
        public \DateTimeImmutable $date,
        public ?float $kgCo2e,
        public string $status,
    ) {
    }
}
