<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Repository\MeasureRepository;
use Doctrine\ORM\QueryBuilder;

final class SustainabilityPlanCompletionService
{
    public function __construct(
        private readonly MeasureRepository $measureRepository,
        private readonly PlanMeasureCatalogResolver $catalogResolver,
        private readonly SustainabilityPlanMeasureOrderer $measureOrderer,
    ) {
    }

    public function syncStatus(Plan $plan, Project $project, ?MeasureRepository $measureRepository = null): bool
    {
        $complete = $this->isComplete($plan, $project, $measureRepository);
        $nextStatus = $complete ? 'completo' : 'incompleto';

        if ($plan->getStatus() !== $nextStatus) {
            $plan->setStatus($nextStatus);
            $plan->setStatusChangedAt(new \DateTimeImmutable());
        }

        return $complete;
    }

    public function isComplete(Plan $plan, Project $project, ?MeasureRepository $measureRepository = null): bool
    {
        return $this->findFirstPendingVisibleMeasure($plan, $project, $measureRepository) === null;
    }

    /**
     * @return array<int, array{measure: Measure, index: int, reason: string}>
     */
    public function getPendingVisibleMeasures(Plan $plan, Project $project, ?MeasureRepository $measureRepository = null): array
    {
        return $this->collectPendingVisibleMeasures($plan, $project, $measureRepository);
    }

    /**
     * @return array<int, Measure>
     */
    public function getVisibleMeasures(Plan $plan, Project $project, ?MeasureRepository $measureRepository = null): array
    {
        $protocol = $plan->getProtocol();
        if (!$protocol instanceof Protocol) {
            return [];
        }

        if ($protocol->getId() === null) {
            $measures = $this->buildVisibleMeasuresFromPlan($plan, $protocol);
        } else {
            $qb = $this->createVisibleMeasuresQueryBuilder((int) $protocol->getId(), $project, $measureRepository);
            $measures = $this->filterMeasuresBySkippedBlocks($qb->getQuery()->getResult(), $plan);
        }

        return $this->measureOrderer->sortVisibleMeasures($measures, $protocol->getGroupingBy());
    }

    /**
     * @return array{measure: Measure, index: int, reason: string}|null
     */
    public function findFirstPendingVisibleMeasure(Plan $plan, Project $project, ?MeasureRepository $measureRepository = null): ?array
    {
        return $this->collectPendingVisibleMeasures($plan, $project, $measureRepository)[0] ?? null;
    }

    /**
     * @param array<int, Measure> $visibleMeasures
     */
    public function findVisibleMeasureIndex(array $visibleMeasures, Measure $measure): ?int
    {
        $measureId = $measure->getId();
        if ($measureId === null) {
            return null;
        }

        foreach ($visibleMeasures as $index => $visibleMeasure) {
            if ($visibleMeasure->getId() === $measureId) {
                return (int) $index;
            }
        }

        return null;
    }

    private function hasCriticalReason(PlanMeasure $planMeasure): bool
    {
        return trim((string) ($planMeasure->getCriticalReason() ?? '')) !== '';
    }

    /**
     * @return array<int, array{measure: Measure, index: int, reason: string}>
     */
    private function collectPendingVisibleMeasures(Plan $plan, Project $project, ?MeasureRepository $measureRepository = null): array
    {
        $protocol = $plan->getProtocol();
        if (!$protocol instanceof Protocol) {
            return [];
        }

        $pending = [];
        foreach ($this->getVisibleMeasures($plan, $project, $measureRepository) as $index => $measure) {
            $planMeasure = $this->findPlanMeasureForMeasure($plan, $measure);
            if (!$planMeasure instanceof PlanMeasure) {
                $pending[] = [
                    'measure' => $measure,
                    'index' => (int) $index,
                    'reason' => 'missing_plan_measure',
                ];
                continue;
            }

            if ($planMeasure->getApplicabilitySource() === 'block_skip') {
                continue;
            }

            if ($planMeasure->isApplicable() === null) {
                $pending[] = [
                    'measure' => $measure,
                    'index' => (int) $index,
                    'reason' => 'applicability_missing',
                ];
                continue;
            }

            if ($planMeasure->isApplicable() === true) {
                if ($planMeasure->isCritical() === null) {
                    $pending[] = [
                        'measure' => $measure,
                        'index' => (int) $index,
                        'reason' => 'critical_missing',
                    ];
                    continue;
                }

                if ($planMeasure->isCritical() === true && !$this->hasCriticalReason($planMeasure)) {
                    $pending[] = [
                        'measure' => $measure,
                        'index' => (int) $index,
                        'reason' => 'critical_reason_missing',
                    ];
                    continue;
                }

                if ($planMeasure->willImplement() === null) {
                    $pending[] = [
                        'measure' => $measure,
                        'index' => (int) $index,
                        'reason' => 'will_implement_missing',
                    ];
                }
            }
        }

        return $pending;
    }

    private function findPlanMeasureForMeasure(Plan $plan, Measure $measure): ?PlanMeasure
    {
        $measureId = $measure->getId();
        if ($measureId === null) {
            return null;
        }

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure->getMeasure()?->getId() === $measureId) {
                return $planMeasure;
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function getSkippedMeasureBlockIds(Plan $plan): array
    {
        $ids = [];

        foreach ($plan->getBlockAnswers() as $answer) {
            if ($answer->applies() === false && $answer->getMeasureBlock()?->getId() !== null) {
                $ids[(int) $answer->getMeasureBlock()->getId()] = (int) $answer->getMeasureBlock()->getId();
            }
        }

        return $ids;
    }

    /**
     * @param array<int, Measure> $measures
     * @return array<int, Measure>
     */
    private function filterMeasuresBySkippedBlocks(array $measures, Plan $plan): array
    {
        $skippedBlockIds = $this->getSkippedMeasureBlockIds($plan);
        if ($skippedBlockIds === []) {
            return $measures;
        }

        return array_values(array_filter($measures, static function (Measure $measure) use ($skippedBlockIds): bool {
            $blockId = $measure->getMeasureBlock()?->getId();
            return $blockId === null || !isset($skippedBlockIds[(int) $blockId]);
        }));
    }

    private function createVisibleMeasuresQueryBuilder(int $protocolId, Project $project, ?MeasureRepository $measureRepository = null): QueryBuilder
    {
        $repository = $measureRepository ?? $this->measureRepository;

        $qb = $repository->createQueryBuilder('m')
            ->join('m.protocol', 'p')
            ->leftJoin('m.category', 'c')
            ->leftJoin('m.department', 'd')
            ->leftJoin('m.measureBlock', 'mb')
            ->addSelect('c', 'd', 'mb')
            ->andWhere('p.id = :protocolId')
            ->setParameter('protocolId', $protocolId);
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p', $project);

        return $qb;
    }

    /**
     * @return array<int, Measure>
     */
    private function buildVisibleMeasuresFromPlan(Plan $plan, Protocol $protocol): array
    {
        $measures = [];

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure) {
                continue;
            }

            $measureProtocol = $measure->getProtocol();
            if (!$measureProtocol instanceof Protocol) {
                continue;
            }

            if ($measureProtocol !== $protocol) {
                $protocolId = $protocol->getId();
                if ($protocolId === null || $measureProtocol->getId() === null || $measureProtocol->getId() !== $protocolId) {
                    continue;
                }
            }

            $measures[] = $measure;
        }

        return $this->filterMeasuresBySkippedBlocks($measures, $plan);
    }
}
