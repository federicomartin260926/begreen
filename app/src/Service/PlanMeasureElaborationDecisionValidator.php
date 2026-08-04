<?php

namespace App\Service;

final class PlanMeasureElaborationDecisionValidator
{
    public const MIN_OBSERVATIONS_LENGTH = 50;

    public function normalizeObservations(?string $observations): string
    {
        return trim((string) $observations);
    }

    public function observationsLength(?string $observations): int
    {
        return mb_strlen($this->normalizeObservations($observations));
    }

    public function hasValidObservations(?string $observations): bool
    {
        return $this->observationsLength($observations) >= self::MIN_OBSERVATIONS_LENGTH;
    }
}
