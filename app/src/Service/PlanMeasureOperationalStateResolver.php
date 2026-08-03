<?php

namespace App\Service;

use App\Entity\PlanMeasure;

final class PlanMeasureOperationalStateResolver
{
    public const ALL = 'all';
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const IMPLEMENTED = 'implemented';
    public const NOT_IMPLEMENTED = 'not_implemented';
    public const DISCARDED = 'discarded';
    public const NOT_APPLICABLE = 'not_applicable';

    public function resolve(PlanMeasure $planMeasure): string
    {
        if ($planMeasure->isApplicable() === false) {
            return self::NOT_APPLICABLE;
        }

        if ($planMeasure->isApplicable() === true && $planMeasure->willImplement() === false) {
            return self::DISCARDED;
        }

        if ($planMeasure->isApplicable() === true && $planMeasure->willImplement() === true) {
            if ($planMeasure->isImplemented() === null) {
                return self::PENDING;
            }

            if ($planMeasure->isImplemented() === false) {
                return self::NOT_IMPLEMENTED;
            }

            return $planMeasure->canBeMarkedAsImplemented() ? self::IMPLEMENTED : self::IN_PROGRESS;
        }

        return self::PENDING;
    }

    public function matches(PlanMeasure $planMeasure, string $state): bool
    {
        return $state === self::ALL || $this->resolve($planMeasure) === $state;
    }

    public function hasExecutionActivity(PlanMeasure $planMeasure): bool
    {
        return $planMeasure->hasActionTaken()
            || $planMeasure->hasEvidence()
            || trim((string) $planMeasure->getExecutionIncident()) !== ''
            || trim((string) $planMeasure->getInternalNotes()) !== ''
            || !$planMeasure->getResponsibleCrewMembers()->isEmpty()
            || $planMeasure->isVerification();
    }
}
