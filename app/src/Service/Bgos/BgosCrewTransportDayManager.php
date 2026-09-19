<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewTransportDay;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Repository\BgosCrewProfileRepository;
use App\Repository\BgosCrewTransportDayRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BgosCrewTransportDayManager
{
    public function __construct(
        private BgosCrewTransportDayRepository $dayRepository,
        private BgosCrewProfileRepository $profileRepository,
        private BgosCrewTransportDayFactory $factory,
        private BgosCrewMobilityValidator $mobilityValidator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function ensure(
        CrewMember $crewMember,
        \DateTimeInterface $date,
    ): BgosCrewTransportDay {
        $existing = $this->dayRepository->findOneByCrewMemberAndDate(
            $crewMember,
            $date,
        );

        if ($existing instanceof BgosCrewTransportDay) {
            return $existing;
        }

        $day = $this->factory->create(
            $crewMember,
            $date,
            $this->profileRepository->findOneByCrewMember($crewMember),
        );

        $this->entityManager->persist($day);
        $this->entityManager->flush();

        return $day;
    }

    public function update(
        BgosCrewTransportDay $day,
        string $status,
        ?CrewMemberAssignment $assignment,
        ?string $origin,
        ?string $mode,
        ?string $vehicleType,
        ?string $fuel,
        ?string $thermalFuel,
    ): BgosCrewTransportDay {
        $crewMember = $day->getCrewMember();

        if (!$crewMember instanceof CrewMember) {
            throw new \LogicException(
                'BGoS crew transport day has no crew member.'
            );
        }

        if (
            null !== $assignment
            && $assignment->getCrewMember() !== $crewMember
        ) {
            throw new \InvalidArgumentException(
                'The daily assignment must belong to the crew member.'
            );
        }

        $this->mobilityValidator->assertSupported(
            $mode,
            $vehicleType,
            $fuel,
            $thermalFuel,
        );

        $day
            ->setStatus($status)
            ->setCrewAssignment($assignment)
            ->setOrigin($this->mobilityValidator->normalize($origin))
            ->setMode($this->mobilityValidator->normalize($mode))
            ->setVehicleType($this->mobilityValidator->normalize($vehicleType))
            ->setFuel($this->mobilityValidator->normalize($fuel))
            ->setThermalFuel($this->mobilityValidator->normalize($thermalFuel));

        $this->entityManager->flush();

        return $day;
    }

    public function remove(BgosCrewTransportDay $day): void
    {
        $this->entityManager->remove($day);
        $this->entityManager->flush();
    }
}
