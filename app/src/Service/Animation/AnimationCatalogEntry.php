<?php

namespace App\Service\Animation;

final readonly class AnimationCatalogEntry
{
    /** @param array<string, mixed> $editorial */
    public function __construct(
        public string $catalogId,
        public int $sourceRow,
        public string $categoryCode,
        public string $blockCode,
        public string $blockName,
        public array $editorial,
        public string $expectedImpact,
        public string $effortCost,
        public string $complexity,
        public AnimationMeasure $filter,
    ) {
    }
}
