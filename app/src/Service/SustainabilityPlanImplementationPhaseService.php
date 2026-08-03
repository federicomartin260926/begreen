<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;

final class SustainabilityPlanImplementationPhaseService
{
    public const NOT_STARTED = 'not_started';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';

    public function __construct(
        private readonly PlanMeasureCatalogResolver $catalogResolver,
        private readonly PlanMeasureOperationalStateResolver $operationalStateResolver,
    ) {
    }

    public function resolve(Plan $plan, Project $project): string
    {
        $states = [];
        $hasActivity = false;
        $protocolId = $plan->getProtocol()?->getId();

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure
                || !$this->catalogResolver->isCatalogMeasure($measure, $project)
                || ($protocolId !== null && $measure->getProtocol()?->getId() !== $protocolId)
                || $planMeasure->isApplicable() !== true
                || $planMeasure->willImplement() !== true
            ) {
                continue;
            }

            $states[] = $this->operationalStateResolver->resolve($planMeasure);
            $hasActivity = $hasActivity
                || $planMeasure->isImplemented() !== null
                || $this->operationalStateResolver->hasExecutionActivity($planMeasure);
        }

        if ($states === []) {
            return self::NOT_STARTED;
        }

        $resolvedStates = [
            PlanMeasureOperationalStateResolver::IMPLEMENTED,
            PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED,
        ];
        if (count(array_filter($states, static fn (string $state): bool => in_array($state, $resolvedStates, true))) === count($states)) {
            return self::COMPLETED;
        }

        return $hasActivity ? self::IN_PROGRESS : self::NOT_STARTED;
    }
}
