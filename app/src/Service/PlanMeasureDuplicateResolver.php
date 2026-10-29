<?php

namespace App\Service;

use App\Entity\PlanMeasure;

/**
 * Defensive selection for legacy duplicate (plan, measure) relations.
 * Does not delete, merge, or rewrite persisted records.
 */
final class PlanMeasureDuplicateResolver
{
    /**
     * @param iterable<PlanMeasure> $relations
     * @return list<PlanMeasure>
     */
    public static function unique(iterable $relations): array
    {
        $chosen = [];

        foreach ($relations as $relation) {
            if (!$relation instanceof PlanMeasure) {
                continue;
            }

            $measure = $relation->getMeasure();
            if ($measure === null) {
                continue;
            }

            $key = $measure->getId() !== null
                ? 'id:' . $measure->getId()
                : 'object:' . spl_object_id($measure);

            if (!isset($chosen[$key]) || self::prefer($relation, $chosen[$key])) {
                $chosen[$key] = $relation;
            }
        }

        return array_values($chosen);
    }

    /**
     * Finds the canonical PlanMeasure in the plan collection, including
     * pending (not yet flushed) relations and historical duplicate rows.
     * Callers may fall back to the repository only when nothing is attached.
     */
    public static function forMeasure(\App\Entity\Plan $plan, \App\Entity\Measure $measure): ?PlanMeasure
    {
        $measureId = $measure->getId();
        foreach (self::unique($plan->getPlanMeasures()) as $candidate) {
            $candidateMeasure = $candidate->getMeasure();
            if ($candidateMeasure === $measure
                || ($measureId !== null && $candidateMeasure?->getId() === $measureId)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private static function prefer(PlanMeasure $candidate, PlanMeasure $current): bool
    {
        // A recorded answer always wins over a pristine, unanswered duplicate.
        $candidateAnswered = $candidate->isApplicable() !== null;
        $currentAnswered = $current->isApplicable() !== null;
        if ($candidateAnswered !== $currentAnswered) {
            return $candidateAnswered;
        }

        // Otherwise prefer the relation with more persisted user content.
        $candidateScore = self::contentScore($candidate);
        $currentScore = self::contentScore($current);
        if ($candidateScore !== $currentScore) {
            return $candidateScore > $currentScore;
        }

        // Tie: use the older persisted relation, independent of collection order.
        $candidateId = $candidate->getId();
        $currentId = $current->getId();
        return $candidateId !== null && $currentId !== null && $candidateId < $currentId;
    }

    private static function contentScore(PlanMeasure $relation): int
    {
        $score = 0;
        $score += $relation->willImplement() !== null ? 16 : 0;
        $score += $relation->isImplemented() !== null ? 8 : 0;
        $score += trim((string) $relation->getEvidence()) !== '' ? 4 : 0;
        $score += trim((string) $relation->getObservations()) !== '' ? 4 : 0;
        $score += trim((string) $relation->getActionTaken()) !== '' ? 4 : 0;
        $score += trim((string) $relation->getInternalNotes()) !== '' ? 2 : 0;
        $score += $relation->isCritical() !== null ? 1 : 0;
        return $score;
    }
}
