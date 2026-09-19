<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewProfile;
use App\Entity\CrewMember;
use App\Entity\Project;
use App\Repository\BgosCrewProfileRepository;

final readonly class BgosCrewRosterService
{
    public function __construct(
        private BgosCrewProfileRepository $profileRepository,
        private BgosCrewAssignmentResolver $assignmentResolver,
    ) {
    }

    /**
     * @return list<array{
     *     member:CrewMember,
     *     profile:?BgosCrewProfile,
     *     effectiveAssignment:mixed,
     *     requiresAssignmentSelection:bool
     * }>
     */
    public function build(Project $project): array
    {
        $profiles = [];

        foreach ($this->profileRepository->findByProject($project) as $profile) {
            $memberId = $profile->getCrewMember()?->getId();

            if (null !== $memberId) {
                $profiles[$memberId] = $profile;
            }
        }

        $members = $project->getCrewMembers()->toArray();

        usort(
            $members,
            static fn (CrewMember $left, CrewMember $right): int =>
                strcasecmp(
                    trim((string) $left->getName().' '.(string) $left->getLastName()),
                    trim((string) $right->getName().' '.(string) $right->getLastName()),
                )
        );

        $rows = [];

        foreach ($members as $member) {
            $profile = null !== $member->getId()
                ? ($profiles[$member->getId()] ?? null)
                : null;

            $effectiveAssignment = $this->assignmentResolver->resolve(
                $member,
                $profile,
            );

            $rows[] = [
                'member' => $member,
                'profile' => $profile,
                'effectiveAssignment' => $effectiveAssignment,
                'requiresAssignmentSelection' =>
                    $member->getAssignments()->count() > 1
                    && null === $effectiveAssignment,
            ];
        }

        return $rows;
    }
}
