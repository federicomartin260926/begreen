<?php

namespace App\Service\Animation;

use App\Entity\PlanMeasure;
use App\Service\PlanMeasureOperationalStateResolver;

final readonly class AnimationComplianceService
{
    public function __construct(private PlanMeasureOperationalStateResolver $stateResolver)
    {
    }

    /** @param iterable<PlanMeasure> $currentlyEligiblePlanMeasures */
    public function summarize(iterable $currentlyEligiblePlanMeasures): AnimationComplianceSummary
    {
        $counts = [
            PlanMeasureOperationalStateResolver::IMPLEMENTED => 0,
            PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED => 0,
            PlanMeasureOperationalStateResolver::NOT_APPLICABLE => 0,
            PlanMeasureOperationalStateResolver::PENDING => 0,
            PlanMeasureOperationalStateResolver::IN_PROGRESS => 0,
            PlanMeasureOperationalStateResolver::DISCARDED => 0,
        ];

        foreach ($currentlyEligiblePlanMeasures as $planMeasure) {
            $state = $this->stateResolver->resolve($planMeasure);
            if (isset($counts[$state])) {
                ++$counts[$state];
            }
        }

        $denominator = $counts[PlanMeasureOperationalStateResolver::IMPLEMENTED]
            + $counts[PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED];

        return new AnimationComplianceSummary(
            fulfilledCount: $counts[PlanMeasureOperationalStateResolver::IMPLEMENTED],
            notFulfilledCount: $counts[PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED],
            notApplicableCount: $counts[PlanMeasureOperationalStateResolver::NOT_APPLICABLE],
            pendingCount: $counts[PlanMeasureOperationalStateResolver::PENDING],
            inProgressCount: $counts[PlanMeasureOperationalStateResolver::IN_PROGRESS],
            discardedCount: $counts[PlanMeasureOperationalStateResolver::DISCARDED],
            denominator: $denominator,
            percentage: $denominator > 0
                ? round(($counts[PlanMeasureOperationalStateResolver::IMPLEMENTED] / $denominator) * 100, 1)
                : null,
        );
    }
}
