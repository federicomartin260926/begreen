<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

final readonly class CateringMenuLine
{
    public function __construct(
        public ?string $menuVariant,
        public ?string $preparedCount,
        public ?string $consumedCount,
    ) {
    }
}
