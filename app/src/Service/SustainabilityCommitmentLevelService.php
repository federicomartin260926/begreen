<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\PlanMeasure;
use App\Repository\MeasureRepository;

final class SustainabilityCommitmentLevelService
{
    public function __construct(
        private readonly MeasureRepository $measureRepository,
        private readonly PlanMeasureCatalogResolver $catalogResolver,
    ) {
    }

    /**
     * @return array{
     *     totalOfficialPoints:int,
     *     officialMeasures:int,
     *     planned: array{
     *         points:int,
     *         percentage:float,
     *         percentageRounded:int,
     *         levelKey:string,
     *         labelKey:string,
     *         messageKey:string,
     *         pointsToNextLevel:int|null
     *     },
     *     implemented: array{
     *         points:int,
     *         percentage:float,
     *         percentageRounded:int,
     *         levelKey:string,
     *         labelKey:string,
     *         messageKey:string,
     *         pointsToNextLevel:int|null
     *     }
     * }
     */
    public function buildSummary(Plan $plan, Project $project): array
    {
        $protocol = $plan->getProtocol();
        if (!$protocol) {
            return $this->emptySummary();
        }

        $catalogMeasures = array_values(array_filter(
            $this->measureRepository->getCatalogMeasuresForProtocol($project, $protocol),
            fn ($measure): bool => $measure instanceof Measure && $this->catalogResolver->isCatalogMeasure($measure, $project)
        ));
        $visibleCatalogMeasures = $this->filterMeasuresBySkippedBlocks($catalogMeasures, $plan);
        $totalOfficialPoints = $this->sumMeasureScores($visibleCatalogMeasures);
        $scoreByMeasureId = $this->indexScoresByMeasureId($catalogMeasures);

        $plannedPoints = 0;
        $implementedPoints = 0;

        foreach ($this->filterPlanMeasuresBySkippedBlocks($plan->getPlanMeasures(), $plan) as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure) {
                continue;
            }

            if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
                continue;
            }

            if ($measure->getProtocol()?->getId() !== $protocol->getId()) {
                continue;
            }

            $score = $scoreByMeasureId[(string) $measure->getId()] ?? (int) ($measure->getScore() ?? 0);
            if ($score <= 0) {
                continue;
            }

            if ($planMeasure->isApplicable() === true && $planMeasure->willImplement() === true) {
                $plannedPoints += $score;
            }

            if ($planMeasure->isApplicable() === true && $planMeasure->isImplemented() === true) {
                $implementedPoints += $score;
            }
        }

        return [
            'totalOfficialPoints' => $totalOfficialPoints,
            'officialMeasures' => count($visibleCatalogMeasures),
            'planned' => $this->buildLevelBlock($plannedPoints, $totalOfficialPoints),
            'implemented' => $this->buildLevelBlock($implementedPoints, $totalOfficialPoints),
        ];
    }

    /**
     * @param Measure[] $measures
     */
    private function sumMeasureScores(array $measures): int
    {
        $total = 0;
        foreach ($measures as $measure) {
            if (!$measure instanceof Measure) {
                continue;
            }

            $total += (int) ($measure->getScore() ?? 0);
        }

        return $total;
    }

    /**
     * @param Measure[] $measures
     * @return array<string, int>
     */
    private function indexScoresByMeasureId(array $measures): array
    {
        $scores = [];
        foreach ($measures as $measure) {
            if (!$measure instanceof Measure || $measure->getId() === null) {
                continue;
            }

            $scores[(string) $measure->getId()] = (int) ($measure->getScore() ?? 0);
        }

        return $scores;
    }

    /**
     * @return array{
     *     points:int,
     *     percentage:float,
     *     percentageRounded:int,
     *     levelKey:string,
     *     labelKey:string,
     *     messageKey:string,
     *     pointsToNextLevel:int|null
     * }
     */
    private function buildLevelBlock(int $points, int $totalOfficialPoints): array
    {
        $percentage = $totalOfficialPoints > 0 ? ($points * 100) / $totalOfficialPoints : 0.0;
        $levelKey = $this->resolveLevelKey($percentage);

        return [
            'points' => $points,
            'percentage' => $percentage,
            'percentageRounded' => (int) round($percentage),
            'levelKey' => $levelKey,
            'labelKey' => 'backend.plan.commitment.levels.' . $levelKey . '.label',
            'messageKey' => 'backend.plan.commitment.levels.' . $levelKey . '.message',
            'pointsToNextLevel' => $this->getPointsToNextLevel($points, $totalOfficialPoints, $levelKey),
        ];
    }

    private function resolveLevelKey(float $percentage): string
    {
        return match (true) {
            $percentage <= 20.0 => 'seed',
            $percentage <= 40.0 => 'plant',
            $percentage <= 60.0 => 'tree',
            $percentage <= 80.0 => 'forest',
            default => 'jungle',
        };
    }

    private function getPointsToNextLevel(int $points, int $totalOfficialPoints, string $levelKey): ?int
    {
        $threshold = match ($levelKey) {
            'seed' => 20,
            'plant' => 40,
            'tree' => 60,
            'forest' => 80,
            default => null,
        };

        if ($threshold === null || $totalOfficialPoints <= 0) {
            return null;
        }

        $minPointsForNextLevel = (int) floor(($totalOfficialPoints * $threshold) / 100) + 1;

        return max(0, $minPointsForNextLevel - $points);
    }

    /**
     * @return array{
     *     totalOfficialPoints:int,
     *     officialMeasures:int,
     *     planned: array{
     *         points:int,
     *         percentage:float,
     *         percentageRounded:int,
     *         levelKey:string,
     *         labelKey:string,
     *         messageKey:string,
     *         pointsToNextLevel:int|null
     *     },
     *     implemented: array{
     *         points:int,
     *         percentage:float,
     *         percentageRounded:int,
     *         levelKey:string,
     *         labelKey:string,
     *         messageKey:string,
     *         pointsToNextLevel:int|null
     *     }
     * }
     */
    private function emptySummary(): array
    {
        return [
            'totalOfficialPoints' => 0,
            'officialMeasures' => 0,
            'planned' => $this->buildLevelBlock(0, 0),
            'implemented' => $this->buildLevelBlock(0, 0),
        ];
    }

    /**
     * @param iterable<int, PlanMeasure> $planMeasures
     * @return array<int, PlanMeasure>
     */
    private function filterPlanMeasuresBySkippedBlocks(iterable $planMeasures, Plan $plan): array
    {
        $skippedBlockIds = $this->getSkippedBlockIds($plan);
        if ($skippedBlockIds === []) {
            return is_array($planMeasures) ? $planMeasures : iterator_to_array($planMeasures, false);
        }

        $result = [];
        foreach ($planMeasures as $planMeasure) {
            $blockId = $planMeasure->getMeasure()?->getMeasureBlock()?->getId();
            if ($blockId !== null && isset($skippedBlockIds[(int) $blockId])) {
                continue;
            }

            $result[] = $planMeasure;
        }

        return $result;
    }

    /**
     * @param iterable<int, Measure> $measures
     * @return Measure[]
     */
    private function filterMeasuresBySkippedBlocks(iterable $measures, Plan $plan): array
    {
        $skippedBlockIds = $this->getSkippedBlockIds($plan);
        if ($skippedBlockIds === []) {
            return is_array($measures) ? $measures : iterator_to_array($measures, false);
        }

        $result = [];
        foreach ($measures as $measure) {
            if (!$measure instanceof Measure) {
                continue;
            }

            $blockId = $measure->getMeasureBlock()?->getId();
            if ($blockId !== null && isset($skippedBlockIds[(int) $blockId])) {
                continue;
            }

            $result[] = $measure;
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function getSkippedBlockIds(Plan $plan): array
    {
        $ids = [];

        foreach ($plan->getBlockAnswers() as $answer) {
            if ($answer->applies() === false && $answer->getMeasureBlock()?->getId() !== null) {
                $ids[(int) $answer->getMeasureBlock()->getId()] = (int) $answer->getMeasureBlock()->getId();
            }
        }

        return $ids;
    }
}
