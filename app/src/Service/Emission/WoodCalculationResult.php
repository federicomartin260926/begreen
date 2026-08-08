<?php

namespace App\Service\Emission;

final readonly class WoodCalculationResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public float $amount,
        public float $emission,
        public array $details,
    ) {
    }
}
