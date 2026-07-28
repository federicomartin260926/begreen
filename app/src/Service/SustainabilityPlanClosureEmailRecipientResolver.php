<?php

namespace App\Service;

use App\Entity\CrewMember;
use App\Entity\Project;
use App\Repository\CrewMemberRepository;

final class SustainabilityPlanClosureEmailRecipientResolver
{
    public function __construct(private readonly CrewMemberRepository $crewMemberRepository)
    {
    }

    /**
     * @param mixed[] $rawIds
     * @return CrewMember[]
     */
    public function resolve(Project $project, array $rawIds): array
    {
        $selectedIds = [];
        foreach ($rawIds as $rawId) {
            if ((!is_int($rawId) && !is_string($rawId)) || !ctype_digit((string) $rawId) || (int) $rawId <= 0) {
                throw new \InvalidArgumentException('Invalid crew member identifier.');
            }

            $selectedIds[(int) $rawId] = (int) $rawId;
        }
        $selectedIds = array_values($selectedIds);

        if ($selectedIds === []) {
            return [];
        }

        $members = $this->crewMemberRepository->findBy([
            'project' => $project,
            'id' => $selectedIds,
        ]);

        if (count($members) !== count($selectedIds)) {
            throw new \InvalidArgumentException('A selected crew member does not belong to the active project.');
        }

        return array_values(array_filter(
            $members,
            static fn (mixed $member): bool => $member instanceof CrewMember
        ));
    }
}
