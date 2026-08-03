<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\PlanMeasure;

final class PlanMeasureResumeService
{
    /**
     * @param iterable<int, Measure> $visibleMeasures
     * @param iterable<int, PlanMeasure> $planMeasures
     */
    public function resolveIndex(iterable $visibleMeasures, iterable $planMeasures): int
    {
        $planMeasureByKey = [];
        foreach ($planMeasures as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure) {
                continue;
            }

            $planMeasureByKey[$this->measureKey($measure)] = $planMeasure;
        }

        $lastIndex = 0;
        $hasMeasures = false;

        foreach ($visibleMeasures as $index => $measure) {
            if (!$measure instanceof Measure) {
                continue;
            }

            $hasMeasures = true;
            $lastIndex = (int) $index;

            $planMeasure = $planMeasureByKey[$this->measureKey($measure)] ?? null;
            if (!$planMeasure instanceof PlanMeasure) {
                return $lastIndex;
            }

            if ($this->isStaleBlockSkip($planMeasure)) {
                return $lastIndex;
            }

            if ($this->isActiveBlockSkip($planMeasure)) {
                continue;
            }

            if ($planMeasure->isApplicable() === null) {
                return $lastIndex;
            }

            if ($planMeasure->isApplicable() === true) {
                if ($planMeasure->willImplement() === null) {
                    return $lastIndex;
                }

                if ($planMeasure->willImplement() === true && $planMeasure->isCritical() === null) {
                    return $lastIndex;
                }
            }

            if (trim((string) $planMeasure->getObservations()) === '') {
                return $lastIndex;
            }
        }

        return $hasMeasures ? $lastIndex : 0;
    }

    private function isActiveBlockSkip(PlanMeasure $planMeasure): bool
    {
        return $planMeasure->getApplicabilitySource() === 'block_skip' && $this->isBlockSkipStillJustified($planMeasure);
    }

    private function isStaleBlockSkip(PlanMeasure $planMeasure): bool
    {
        return $planMeasure->getApplicabilitySource() === 'block_skip' && !$this->isBlockSkipStillJustified($planMeasure);
    }

    private function isBlockSkipStillJustified(PlanMeasure $planMeasure): bool
    {
        $measureBlock = $planMeasure->getMeasure()?->getMeasureBlock();
        $blockSkipAnswer = $planMeasure->getBlockSkipAnswer();

        return $measureBlock instanceof \App\Entity\MeasureBlock
            && $blockSkipAnswer instanceof \App\Entity\SustainabilityPlanBlockAnswer
            && $measureBlock->getId() !== null
            && $blockSkipAnswer->getId() !== null
            && $blockSkipAnswer->getMeasureBlock()?->getId() === $measureBlock->getId()
            && $blockSkipAnswer->applies() === false;
    }

    private function measureKey(Measure $measure): string
    {
        return $measure->getId() !== null
            ? 'id:' . $measure->getId()
            : 'obj:' . spl_object_id($measure);
    }
}
