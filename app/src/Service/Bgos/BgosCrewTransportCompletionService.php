<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewTransportDay;
use App\Entity\CrewDepartment;

final class BgosCrewTransportCompletionService
{
    /**
     * @param iterable<BgosCrewTransportDay> $days
     *
     * @return array{
     *     expectedCount:int,
     *     completedCount:int,
     *     pendingCount:int,
     *     notApplicableCount:int,
     *     status:string,
     *     departments:list<array{
     *         department:?CrewDepartment,
     *         expectedCount:int,
     *         completedCount:int,
     *         pendingCount:int,
     *         notApplicableCount:int,
     *         status:string,
     *         days:list<BgosCrewTransportDay>
     *     }>
     * }
     */
    public function summarize(iterable $days): array
    {
        $expectedCount = 0;
        $completedCount = 0;
        $pendingCount = 0;
        $notApplicableCount = 0;
        $groups = [];

        foreach ($days as $day) {
            $department = $day->getCrewAssignment()?->getCrewDepartment();
            $groupKey = $this->departmentKey($department);

            $groups[$groupKey] ??= [
                'department' => $department,
                'expectedCount' => 0,
                'completedCount' => 0,
                'pendingCount' => 0,
                'notApplicableCount' => 0,
                'days' => [],
            ];

            $groups[$groupKey]['days'][] = $day;

            if (BgosCrewTransportDay::STATUS_NOT_APPLICABLE === $day->getStatus()) {
                ++$notApplicableCount;
                ++$groups[$groupKey]['notApplicableCount'];

                continue;
            }

            ++$expectedCount;
            ++$groups[$groupKey]['expectedCount'];

            if (BgosCrewTransportDay::STATUS_RESOLVED === $day->getStatus()) {
                ++$completedCount;
                ++$groups[$groupKey]['completedCount'];

                continue;
            }

            ++$pendingCount;
            ++$groups[$groupKey]['pendingCount'];
        }

        foreach ($groups as &$group) {
            $group['status'] = $this->aggregateStatus(
                $group['expectedCount'],
                $group['completedCount'],
                $group['pendingCount'],
            );
        }
        unset($group);

        uasort(
            $groups,
            static function (array $left, array $right): int {
                $leftDepartment = $left['department'];
                $rightDepartment = $right['department'];

                if ($leftDepartment instanceof CrewDepartment
                    && $rightDepartment instanceof CrewDepartment
                ) {
                    return [
                        $leftDepartment->getSortOrder(),
                        mb_strtolower((string) $leftDepartment->getName()),
                    ] <=> [
                        $rightDepartment->getSortOrder(),
                        mb_strtolower((string) $rightDepartment->getName()),
                    ];
                }

                if ($leftDepartment instanceof CrewDepartment) {
                    return -1;
                }

                if ($rightDepartment instanceof CrewDepartment) {
                    return 1;
                }

                return 0;
            }
        );

        return [
            'expectedCount' => $expectedCount,
            'completedCount' => $completedCount,
            'pendingCount' => $pendingCount,
            'notApplicableCount' => $notApplicableCount,
            'status' => $this->aggregateStatus(
                $expectedCount,
                $completedCount,
                $pendingCount,
            ),
            'departments' => array_values($groups),
        ];
    }

    private function departmentKey(?CrewDepartment $department): string
    {
        if (!$department instanceof CrewDepartment) {
            return 'unassigned';
        }

        return null !== $department->getId()
            ? 'department-'.$department->getId()
            : 'department-object-'.spl_object_id($department);
    }

    private function aggregateStatus(
        int $expectedCount,
        int $completedCount,
        int $pendingCount,
    ): string {
        if (0 === $expectedCount) {
            return BgosCompletionResult::STATUS_NO_DATA;
        }

        if ($completedCount === $expectedCount) {
            return BgosCompletionResult::STATUS_COMPLETE;
        }

        if ($pendingCount > 0) {
            return BgosCompletionResult::STATUS_PENDING;
        }

        return BgosCompletionResult::STATUS_NO_DATA;
    }
}
