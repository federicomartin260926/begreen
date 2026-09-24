<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewTransportDay;
use App\Entity\BgosCrewTransportJourney;
use App\Entity\CrewDepartment;
use App\Entity\CrewMember;

final class BgosCrewTransportCompletionService
{
    /**
     * @param iterable<BgosCrewTransportDay> $days
     * @param iterable<BgosCrewTransportJourney> $journeys
     *
     * @return array{
     *     expectedCount:int,
     *     completedCount:int,
     *     pendingCount:int,
     *     notApplicableCount:int,
     *     status:string,
     *     trackedMemberIds:list<int>,
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
    public function summarize(iterable $days, iterable $journeys = []): array
    {
        $expectedCount = 0;
        $completedCount = 0;
        $pendingCount = 0;
        $notApplicableCount = 0;
        $groups = [];
        $entries = [];
        $trackedMemberIds = [];

        foreach ($days as $day) {
            $member = $day->getCrewMember();
            $date = $day->getDate();
            if (!$member instanceof CrewMember || !$date instanceof \DateTimeImmutable) {
                continue;
            }

            $department = $day->getCrewAssignment()?->getCrewDepartment();
            $entries[$this->personDayKey($member, $date)] = [
                'day' => $day,
                'department' => $department,
                'status' => $day->getStatus(),
            ];
            if (null !== $member->getId()) {
                $trackedMemberIds[$member->getId()] = true;
            }
        }

        foreach ($journeys as $journey) {
            $date = $journey->getDate();
            if (!$date instanceof \DateTimeImmutable) {
                continue;
            }

            foreach ($journey->getSegments() as $segment) {
                foreach ($segment->getParticipants() as $participant) {
                    $member = $participant->getCrewMember();
                    if (!$member instanceof CrewMember) {
                        continue;
                    }

                    $key = $this->personDayKey($member, $date);
                    $entries[$key] ??= [
                        'day' => null,
                        'department' => null,
                        'status' => BgosCrewTransportDay::STATUS_RESOLVED,
                    ];
                    $entries[$key]['status'] = BgosCrewTransportDay::STATUS_RESOLVED;
                    if (null !== $member->getId()) {
                        $trackedMemberIds[$member->getId()] = true;
                    }
                }
            }
        }

        foreach ($entries as $entry) {
            $department = $entry['department'];
            $groupKey = $this->departmentKey($department);

            $groups[$groupKey] ??= [
                'department' => $department,
                'expectedCount' => 0,
                'completedCount' => 0,
                'pendingCount' => 0,
                'notApplicableCount' => 0,
                'days' => [],
            ];

            if ($entry['day'] instanceof BgosCrewTransportDay) {
                $groups[$groupKey]['days'][] = $entry['day'];
            }

            if (BgosCrewTransportDay::STATUS_NOT_APPLICABLE === $entry['status']) {
                ++$notApplicableCount;
                ++$groups[$groupKey]['notApplicableCount'];

                continue;
            }

            ++$expectedCount;
            ++$groups[$groupKey]['expectedCount'];

            if (BgosCrewTransportDay::STATUS_RESOLVED === $entry['status']) {
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
            'trackedMemberIds' => array_map('intval', array_keys($trackedMemberIds)),
            'departments' => array_values($groups),
        ];
    }

    private function personDayKey(CrewMember $member, \DateTimeImmutable $date): string
    {
        $memberKey = null !== $member->getId()
            ? 'id-'.$member->getId()
            : 'object-'.spl_object_id($member);

        return $memberKey.'|'.$date->format('Y-m-d');
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
