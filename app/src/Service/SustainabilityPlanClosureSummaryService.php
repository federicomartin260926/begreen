<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Repository\MeasureRepository;

final class SustainabilityPlanClosureSummaryService
{
    public function __construct(
        private readonly MeasureRepository $measureRepository,
        private readonly PlanMeasureCatalogResolver $catalogResolver,
        private readonly SustainabilityCommitmentLevelService $commitmentLevelService,
        private readonly SustainabilityPlanCustomMeasureParser $customMeasureParser,
    ) {
    }

    /**
     * @return array{
     *     commitment: array<string, mixed>,
     *     measures: array{
     *         official:int,
     *         selected:int,
     *         discarded:int,
     *         notApplicable:int,
     *         critical:int,
     *         custom:int
     *     }
     * }
     */
    public function buildSummary(Plan $plan, Project $project): array
    {
        $commitment = $this->commitmentLevelService->buildSummary($plan, $project);
        $protocol = $plan->getProtocol();

        if ($protocol === null) {
            return [
                'commitment' => $commitment,
                'measures' => $this->emptyMeasureSummary($plan),
            ];
        }

        $officialMeasureIds = [];
        foreach ($this->measureRepository->getCatalogMeasuresForProtocol($project, $protocol) as $measure) {
            if (!$measure instanceof Measure || !$this->catalogResolver->isCatalogMeasure($measure, $project)) {
                continue;
            }

            if ($measure->getId() !== null) {
                $officialMeasureIds[(int) $measure->getId()] = true;
            }
        }

        $measures = [
            'official' => count($officialMeasureIds),
            'selected' => 0,
            'discarded' => 0,
            'notApplicable' => 0,
            'critical' => 0,
            'custom' => count($this->customMeasureParser->parse($plan->getCustomMeasures())),
        ];

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measureId = $planMeasure->getMeasure()?->getId();
            if ($measureId === null || !isset($officialMeasureIds[(int) $measureId])) {
                continue;
            }

            if ($planMeasure->isApplicable() === false) {
                // Incluye las respuestas generadas por block_skip.
                $measures['notApplicable']++;
                continue;
            }

            if ($planMeasure->isApplicable() !== true) {
                continue;
            }

            if ($planMeasure->willImplement() === true) {
                $measures['selected']++;
                if ($planMeasure->isCritical() === true) {
                    $measures['critical']++;
                }
                continue;
            }

            if ($planMeasure->willImplement() === false) {
                $measures['discarded']++;
            }
        }

        return [
            'commitment' => $commitment,
            'measures' => $measures,
        ];
    }

    /**
     * @return array{official:int, selected:int, discarded:int, notApplicable:int, critical:int, custom:int}
     */
    private function emptyMeasureSummary(Plan $plan): array
    {
        return [
            'official' => 0,
            'selected' => 0,
            'discarded' => 0,
            'notApplicable' => 0,
            'critical' => 0,
            'custom' => count($this->customMeasureParser->parse($plan->getCustomMeasures())),
        ];
    }
}
