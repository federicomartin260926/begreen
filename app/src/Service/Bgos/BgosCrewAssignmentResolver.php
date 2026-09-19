<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewProfile;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;

final class BgosCrewAssignmentResolver
{
    public function resolve(
        CrewMember $crewMember,
        ?BgosCrewProfile $profile = null,
    ): ?CrewMemberAssignment {
        $configured = $profile?->getDefaultAssignment();

        if (
            $configured instanceof CrewMemberAssignment
            && $configured->getCrewMember() === $crewMember
        ) {
            return $configured;
        }

        $assignments = $crewMember->getAssignments();

        if (1 !== $assignments->count()) {
            return null;
        }

        $assignment = $assignments->first();

        return $assignment instanceof CrewMemberAssignment
            ? $assignment
            : null;
    }
}
