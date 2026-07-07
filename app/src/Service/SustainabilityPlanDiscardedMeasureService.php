<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;

final class SustainabilityPlanDiscardedMeasureService
{
    public function __construct(
        private readonly PlanMeasureCatalogResolver $catalogResolver,
    ) {
    }

    /**
     * @return array<int, PlanMeasure>
     */
    public function getDiscardedMeasures(Plan $plan, Project $project): array
    {
        $discarded = [];
        $planProtocolId = $plan->getProtocol()?->getId();

        foreach ($this->iterateDiscardablePlanMeasures($plan, $project, $planProtocolId) as $planMeasure) {
            $discarded[] = $planMeasure;
        }

        return $discarded;
    }

    public function recoverDiscardedMeasure(Plan $plan, Project $project, int $measureId): ?PlanMeasure
    {
        $planProtocolId = $plan->getProtocol()?->getId();

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure || $measure->getId() !== $measureId) {
                continue;
            }

            if (!$this->isRecoverableDiscardedMeasure($planMeasure, $project, $planProtocolId)) {
                return null;
            }

            $planMeasure
                ->setIsApplicable(true)
                ->setWillImplement(true)
                ->setIsCritical(false)
                ->setCriticalReason(null)
                ->setImplemented(null)
                ->markAsManual();

            return $planMeasure;
        }

        return null;
    }

    /**
     * @return iterable<int, PlanMeasure>
     */
    private function iterateDiscardablePlanMeasures(Plan $plan, Project $project, ?int $planProtocolId): iterable
    {
        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            if ($this->isDiscardedMeasure($planMeasure, $project, $planProtocolId)) {
                yield $planMeasure;
            }
        }
    }

    private function isDiscardedMeasure(PlanMeasure $planMeasure, Project $project, ?int $planProtocolId): bool
    {
        $measure = $planMeasure->getMeasure();
        if (!$measure instanceof Measure) {
            return false;
        }

        if (!$this->belongsToCurrentPlanAndProject($measure, $project, $planProtocolId)) {
            return false;
        }

        if ($planMeasure->isApplicable() === false) {
            return true;
        }

        return $planMeasure->isApplicable() === true && $planMeasure->willImplement() === false;
    }

    private function isRecoverableDiscardedMeasure(PlanMeasure $planMeasure, Project $project, ?int $planProtocolId): bool
    {
        return $this->isDiscardedMeasure($planMeasure, $project, $planProtocolId);
    }

    private function belongsToCurrentPlanAndProject(Measure $measure, Project $project, ?int $planProtocolId): bool
    {
        if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
            return false;
        }

        $measureProtocolId = $measure->getProtocol()?->getId();
        if ($planProtocolId !== null && $measureProtocolId !== null && $measureProtocolId !== $planProtocolId) {
            return false;
        }

        return true;
    }
}
