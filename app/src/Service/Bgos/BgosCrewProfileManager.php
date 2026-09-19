<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewProfile;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Repository\BgosCrewProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BgosCrewProfileManager
{
    public function __construct(
        private BgosCrewProfileRepository $profileRepository,
        private BgosCrewMobilityValidator $mobilityValidator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(
        CrewMember $crewMember,
        ?CrewMemberAssignment $defaultAssignment,
        ?string $defaultOrigin,
        ?string $defaultMode,
        ?string $defaultVehicleType,
        ?string $defaultFuel,
        ?string $defaultThermalFuel,
    ): BgosCrewProfile {
        if (
            null !== $defaultAssignment
            && $defaultAssignment->getCrewMember() !== $crewMember
        ) {
            throw new \InvalidArgumentException(
                'The default assignment must belong to the crew member.'
            );
        }

        $this->mobilityValidator->assertSupported(
            $defaultMode,
            $defaultVehicleType,
            $defaultFuel,
            $defaultThermalFuel,
        );

        $profile = $this->profileRepository->findOneByCrewMember($crewMember)
            ?? (new BgosCrewProfile())->setCrewMember($crewMember);

        $profile
            ->setDefaultAssignment($defaultAssignment)
            ->setDefaultOrigin($this->mobilityValidator->normalize($defaultOrigin))
            ->setDefaultMode($this->mobilityValidator->normalize($defaultMode))
            ->setDefaultVehicleType($this->mobilityValidator->normalize($defaultVehicleType))
            ->setDefaultFuel($this->mobilityValidator->normalize($defaultFuel))
            ->setDefaultThermalFuel($this->mobilityValidator->normalize($defaultThermalFuel));

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }
}
