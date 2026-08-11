<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Protocol;

final class SustainabilityPlanMeasureOrderer
{
    /**
     * @param Measure[] $measures
     *
     * @return Measure[]
     */
    public function sortVisibleMeasures(array $measures, string $groupingBy, bool $orderByCommercialTier = false): array
    {
        $groupingBy = $groupingBy === Protocol::GROUP_BY_DEPARTMENT
            ? Protocol::GROUP_BY_DEPARTMENT
            : Protocol::GROUP_BY_CATEGORY;

        $blockRanks = $this->buildBlockRanks($measures, $groupingBy);

        usort($measures, function (Measure $left, Measure $right) use ($groupingBy, $blockRanks, $orderByCommercialTier): int {
            if ($orderByCommercialTier) {
                $comparison = $this->commercialTierRank($left) <=> $this->commercialTierRank($right);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $leftGroup = $this->groupSortKey($left, $groupingBy);
            $rightGroup = $this->groupSortKey($right, $groupingBy);
            $comparison = $this->compareSortKeys($leftGroup, $rightGroup);
            if ($comparison !== 0) {
                return $comparison;
            }

            $leftBlock = $this->blockSortKey($left, $groupingBy, $blockRanks);
            $rightBlock = $this->blockSortKey($right, $groupingBy, $blockRanks);
            $comparison = $this->compareSortKeys($leftBlock, $rightBlock);
            if ($comparison !== 0) {
                return $comparison;
            }

            $leftMeasure = $this->measureSortKey($left);
            $rightMeasure = $this->measureSortKey($right);
            $comparison = $this->compareSortKeys($leftMeasure, $rightMeasure);
            if ($comparison !== 0) {
                return $comparison;
            }

            return 0;
        });

        return array_values($measures);
    }

    private function commercialTierRank(Measure $measure): int
    {
        return match ((int) ($measure->getScore() ?? 0)) {
            4, 5 => 0,
            3 => 1,
            1, 2 => 2,
            default => 3,
        };
    }

    /**
     * @param Measure[] $measures
     *
     * @return array<string, array<string, array{priority:int, value:int, name:string, id:int}>>
     */
    private function buildBlockRanks(array $measures, string $groupingBy): array
    {
        $ranks = [];

        foreach ($measures as $measure) {
            $groupKey = $this->groupKey($measure, $groupingBy);
            $block = $measure->getMeasureBlock();
            if (!$block) {
                continue;
            }

            $blockKey = $this->blockKey($measure, $groupingBy);
            $candidate = $this->positiveSortOrder($block->getSortOrder());
            $candidate = $candidate > 0 ? $candidate : ($measure->getSourceRow() ?? PHP_INT_MAX);

            $current = $ranks[$groupKey][$blockKey]['value'] ?? PHP_INT_MAX;
            if ($candidate < $current) {
                $ranks[$groupKey][$blockKey] = [
                    'priority' => 0,
                    'value' => $candidate,
                    'name' => (string) $block->getName(),
                    'id' => (int) ($block->getId() ?? 0),
                ];
            } elseif (!isset($ranks[$groupKey][$blockKey])) {
                $ranks[$groupKey][$blockKey] = [
                    'priority' => 0,
                    'value' => $candidate,
                    'name' => (string) $block->getName(),
                    'id' => (int) ($block->getId() ?? 0),
                ];
            }
        }

        return $ranks;
    }

    /**
     * @return array{priority:int, value:int, name:string, id:int}
     */
    private function groupSortKey(Measure $measure, string $groupingBy): array
    {
        $entity = $groupingBy === Protocol::GROUP_BY_DEPARTMENT
            ? $measure->getDepartment()
            : $measure->getCategory();

        $sortOrder = $entity ? $this->positiveSortOrder((int) $entity->getSortOrder()) : 0;

        return [
            'priority' => $sortOrder > 0 ? 0 : 1,
            'value' => $sortOrder > 0 ? $sortOrder : PHP_INT_MAX,
            'sourceRow' => PHP_INT_MAX,
            'name' => $entity ? (string) $entity->getName() : '',
            'id' => (int) ($entity?->getId() ?? 0),
        ];
    }

    /**
     * @param array<string, array<string, array{priority:int, value:int, name:string, id:int}>> $blockRanks
     *
     * @return array{priority:int, value:int, name:string, id:int}
     */
    private function blockSortKey(Measure $measure, string $groupingBy, array $blockRanks): array
    {
        $groupKey = $this->groupKey($measure, $groupingBy);
        $blockKey = $this->blockKey($measure, $groupingBy);

        return $blockRanks[$groupKey][$blockKey] ?? [
            'priority' => 1,
            'value' => PHP_INT_MAX,
            'sourceRow' => PHP_INT_MAX,
            'name' => '',
            'id' => 0,
        ];
    }

    /**
     * @return array{priority:int, value:int, name:string, id:int}
     */
    private function measureSortKey(Measure $measure): array
    {
        $sortOrder = $this->positiveSortOrder($measure->getSortOrder());
        $sourceRow = $measure->getSourceRow() ?? PHP_INT_MAX;

        return [
            'priority' => $sortOrder > 0 ? 0 : 1,
            'value' => $sortOrder > 0 ? $sortOrder : PHP_INT_MAX,
            'sourceRow' => $sourceRow,
            'name' => (string) ($measure->getName() ?? ''),
            'id' => (int) ($measure->getId() ?? 0),
        ];
    }

    /**
     * @param array{priority:int, value:int, sourceRow?:int, name:string, id:int} $left
     * @param array{priority:int, value:int, sourceRow?:int, name:string, id:int} $right
     */
    private function compareSortKeys(array $left, array $right): int
    {
        $comparison = $left['priority'] <=> $right['priority'];
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = $left['value'] <=> $right['value'];
        if ($comparison !== 0) {
            return $comparison;
        }

        $leftSourceRow = $left['sourceRow'] ?? PHP_INT_MAX;
        $rightSourceRow = $right['sourceRow'] ?? PHP_INT_MAX;
        $comparison = $leftSourceRow <=> $rightSourceRow;
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp((string) $left['name'], (string) $right['name']);
        if ($comparison !== 0) {
            return $comparison;
        }

        return $left['id'] <=> $right['id'];
    }

    private function groupKey(Measure $measure, string $groupingBy): string
    {
        $entity = $groupingBy === Protocol::GROUP_BY_DEPARTMENT
            ? $measure->getDepartment()
            : $measure->getCategory();

        if (!$entity) {
            return '__none__';
        }

        return (string) ($entity->getId() ?? $entity->getName() ?? '__none__');
    }

    private function blockKey(Measure $measure, string $groupingBy): string
    {
        $block = $measure->getMeasureBlock();
        if (!$block) {
            return '__none__';
        }

        return sprintf('%s:%s', $this->groupKey($measure, $groupingBy), (string) ($block->getId() ?? spl_object_hash($block)));
    }

    private function positiveSortOrder(int $sortOrder): int
    {
        return $sortOrder > 0 ? $sortOrder : 0;
    }
}
