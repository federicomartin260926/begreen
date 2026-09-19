<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewProfile;
use App\Entity\BgosCrewTransportDay;
use App\Entity\CrewMember;

final readonly class BgosCrewTransportDayFactory
{
    public function __construct(
        private BgosCrewAssignmentResolver $assignmentResolver,
    ) {
    }

    public function create(
        CrewMember $crewMember,
        \DateTimeInterface $date,
        ?BgosCrewProfile $profile = null,
    ): BgosCrewTransportDay {
        $day = (new BgosCrewTransportDay())
            ->setCrewMember($crewMember)
            ->setDate($date)
            ->setCrewAssignment(
                $this->assignmentResolver->resolve($crewMember, $profile)
            );

        if ($profile?->getCrewMember() !== $crewMember) {
            return $day;
        }

        return $day
            ->setOrigin($profile->getDefaultOrigin())
            ->setMode($profile->getDefaultMode())
            ->setVehicleType($profile->getDefaultVehicleType())
            ->setFuel($profile->getDefaultFuel())
            ->setThermalFuel($profile->getDefaultThermalFuel());
    }
}
