<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Measure;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SustainabilityPlanImplementationViewService
{
    public const UNCATEGORIZED_KEY = 'uncategorized';

    public function __construct(
        private readonly PlanMeasureOperationalStateResolver $stateResolver,
        private readonly SustainabilityPlanMeasureOrderer $measureOrderer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param PlanMeasure[] $allPlanMeasures
     * @param PlanMeasure[] $filteredPlanMeasures
     *
     * @return array{groups: list<array<string, mixed>>, visibleCount: int}
     */
    public function build(
        array $allPlanMeasures,
        array $filteredPlanMeasures,
        string $state,
        ?int $openMeasureId = null,
        ?string $requestedOpenCategory = null,
    ): array {
        $progressByCategory = [];

        foreach ($allPlanMeasures as $planMeasure) {
            if (!$this->isComputable($planMeasure)) {
                continue;
            }

            $categoryKey = $this->categoryKey($planMeasure->getMeasure()?->getCategory());
            $progressByCategory[$categoryKey] ??= $this->emptyProgress();
            ++$progressByCategory[$categoryKey]['totalComputable'];

            $operationalState = $this->stateResolver->resolve($planMeasure);
            match ($operationalState) {
                PlanMeasureOperationalStateResolver::PENDING => ++$progressByCategory[$categoryKey]['pending'],
                PlanMeasureOperationalStateResolver::IN_PROGRESS => ++$progressByCategory[$categoryKey]['inProgress'],
                PlanMeasureOperationalStateResolver::IMPLEMENTED => ++$progressByCategory[$categoryKey]['implemented'],
                PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED => ++$progressByCategory[$categoryKey]['notImplemented'],
                default => null,
            };
        }

        foreach ($progressByCategory as &$progress) {
            $progress['resolved'] = $progress['implemented'] + $progress['notImplemented'];
            $progress['progressPercentage'] = $progress['totalComputable'] > 0
                ? (int) round(($progress['resolved'] / $progress['totalComputable']) * 100)
                : 0;
            $progress['completed'] = $progress['totalComputable'] > 0
                && $progress['resolved'] === $progress['totalComputable'];
        }
        unset($progress);

        $historicalState = in_array($state, [
            PlanMeasureOperationalStateResolver::DISCARDED,
            PlanMeasureOperationalStateResolver::NOT_APPLICABLE,
        ], true);
        $visibleById = [];
        $visibleMeasures = [];

        foreach ($filteredPlanMeasures as $planMeasure) {
            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure) {
                continue;
            }

            $operationalState = $this->stateResolver->resolve($planMeasure);
            $visible = $historicalState
                ? $operationalState === $state
                : $this->isComputable($planMeasure)
                    && ($state === PlanMeasureOperationalStateResolver::ALL || $operationalState === $state);
            if (!$visible) {
                continue;
            }

            $measureId = $measure->getId();
            if ($measureId === null) {
                continue;
            }

            $visibleMeasures[] = $measure;
            $visibleById[$measureId] = [
                'measure' => $measure,
                'planMeasure' => $planMeasure,
                'operationalState' => $operationalState,
            ];
        }

        $orderedMeasures = $this->measureOrderer->sortVisibleMeasures($visibleMeasures, Protocol::GROUP_BY_CATEGORY);
        $groupsByKey = [];
        $openMeasureCategory = null;

        foreach ($orderedMeasures as $measure) {
            $measureId = $measure->getId();
            if ($measureId === null || !isset($visibleById[$measureId])) {
                continue;
            }

            $category = $measure->getCategory();
            $categoryKey = $this->categoryKey($category);
            $groupsByKey[$categoryKey] ??= [
                'key' => $categoryKey,
                'name' => $category?->getName() ?? $this->translator->trans('backend.plan.review.implementation_categories.uncategorized'),
                'sortOrder' => $category?->getSortOrder() ?? PHP_INT_MAX,
                'categoryId' => $category?->getId(),
                'items' => [],
                'outsideProgress' => $historicalState,
                ...($progressByCategory[$categoryKey] ?? $this->emptyProgress()),
            ];
            $groupsByKey[$categoryKey]['items'][] = $visibleById[$measureId];

            if ($openMeasureId === $measureId) {
                $openMeasureCategory = $categoryKey;
            }
        }

        $groups = array_values($groupsByKey);
        usort($groups, fn (array $left, array $right): int => $this->compareGroups($left, $right));

        $visibleKeys = array_fill_keys(array_column($groups, 'key'), true);
        $openCategory = $openMeasureCategory;
        if ($openCategory === null && $requestedOpenCategory !== null && isset($visibleKeys[$requestedOpenCategory])) {
            $openCategory = $requestedOpenCategory;
        }
        if ($openCategory === null && count($groups) === 1) {
            $openCategory = $groups[0]['key'];
        }

        foreach ($groups as &$group) {
            $group['visibleCount'] = count($group['items']);
            $group['isOpen'] = $group['key'] === $openCategory;
        }
        unset($group);

        return [
            'groups' => $groups,
            'visibleCount' => count($visibleById),
        ];
    }

    private function isComputable(PlanMeasure $planMeasure): bool
    {
        return $planMeasure->isApplicable() === true && $planMeasure->willImplement() === true;
    }

    private function categoryKey(?Category $category): string
    {
        return $category?->getId() !== null
            ? (string) $category->getId()
            : self::UNCATEGORIZED_KEY;
    }

    /** @return array{totalComputable:int, resolved:int, pending:int, inProgress:int, implemented:int, notImplemented:int, progressPercentage:int, completed:bool} */
    private function emptyProgress(): array
    {
        return [
            'totalComputable' => 0,
            'resolved' => 0,
            'pending' => 0,
            'inProgress' => 0,
            'implemented' => 0,
            'notImplemented' => 0,
            'progressPercentage' => 0,
            'completed' => false,
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareGroups(array $left, array $right): int
    {
        $comparison = $this->groupOrderRank($left) <=> $this->groupOrderRank($right);
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcasecmp((string) $left['name'], (string) $right['name']);
        if ($comparison !== 0) {
            return $comparison;
        }

        return ((int) ($left['categoryId'] ?? PHP_INT_MAX)) <=> ((int) ($right['categoryId'] ?? PHP_INT_MAX));
    }

    /** @param array<string, mixed> $group */
    private function groupOrderRank(array $group): array
    {
        if ($group['key'] === self::UNCATEGORIZED_KEY) {
            return [2, PHP_INT_MAX];
        }

        $sortOrder = (int) $group['sortOrder'];

        return $sortOrder > 0 ? [0, $sortOrder] : [1, PHP_INT_MAX];
    }
}
