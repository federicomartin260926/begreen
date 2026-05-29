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

            if ($planMeasure->getApplicabilitySource() === 'block_skip') {
                continue;
            }

            if ($planMeasure->isApplicable() === null) {
                return $lastIndex;
            }

            if ($planMeasure->isApplicable() === true) {
                if ($planMeasure->isCritical() === null || $planMeasure->willImplement() === null) {
                    return $lastIndex;
                }
            }
        }

        return $hasMeasures ? $lastIndex : 0;
    }

    private function measureKey(Measure $measure): string
    {
        return $measure->getId() !== null
            ? 'id:' . $measure->getId()
            : 'obj:' . spl_object_id($measure);
    }
}
