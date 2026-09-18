<?php

declare(strict_types=1);

namespace App\Service\Bgos;

final readonly class BgosPeriodWindow
{
    public function __construct(
        public string $view,
        public \DateTimeImmutable $selectedDate,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?\DateTimeImmutable $previousDate,
        public ?\DateTimeImmutable $nextDate,
    ) {
    }
}
