<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosCrewTransportParticipant;
use App\Entity\BgosCrewTransportSegment;
use App\Entity\CrewMember;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BgosCrewTransportJourneyManager
{
    public function __construct(
        private BgosCrewMobilityValidator $mobilityValidator,
        private BgosCrewTransportEmissionSynchronizer $emissionSynchronizer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array{
     *     position?: int,
     *     origin: string,
     *     destination: string,
     *     originLatitude?: ?string,
     *     originLongitude?: ?string,
     *     destinationLatitude?: ?string,
     *     destinationLongitude?: ?string,
     *     distanceKm?: ?string,
     *     distanceSource?: ?string,
     *     participants: list<array{crewMember: CrewMember, role: string}>
     * }> $segments
     */
    public function create(
        Project $project,
        \DateTimeInterface $date,
        string $mode,
        ?string $vehicleType,
        ?string $fuel,
        ?string $thermalFuel,
        array $segments,
    ): BgosCrewTransportJourney {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $project,
            $date,
            $mode,
            $vehicleType,
            $fuel,
            $thermalFuel,
            $segments,
        ): BgosCrewTransportJourney {
            [$mode, $vehicleType, $fuel, $thermalFuel] = $this->normalizeMobility(
                $mode,
                $vehicleType,
                $fuel,
                $thermalFuel,
            );
            $normalizedSegments = $this->buildSegments($project, $segments);

            $journey = (new BgosCrewTransportJourney())
                ->setProject($project)
                ->setDate($date)
                ->setMode($mode)
                ->setVehicleType($vehicleType)
                ->setFuel($fuel)
                ->setThermalFuel($thermalFuel);

            foreach ($normalizedSegments as $segment) {
                $journey->addSegment($segment);
            }

            $entityManager->persist($journey);
            $this->emissionSynchronizer->synchronize($journey);
            $entityManager->flush();

            return $journey;
        });
    }

    /**
     * @param list<array{
     *     position?: int,
     *     origin: string,
     *     destination: string,
     *     originLatitude?: ?string,
     *     originLongitude?: ?string,
     *     destinationLatitude?: ?string,
     *     destinationLongitude?: ?string,
     *     distanceKm?: ?string,
     *     distanceSource?: ?string,
     *     participants: list<array{crewMember: CrewMember, role: string}>
     * }> $segments
     */
    public function update(
        Project $project,
        BgosCrewTransportJourney $journey,
        \DateTimeInterface $date,
        string $mode,
        ?string $vehicleType,
        ?string $fuel,
        ?string $thermalFuel,
        array $segments,
    ): BgosCrewTransportJourney {
        $this->assertJourneyBelongsToProject($journey, $project);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $project,
            $journey,
            $date,
            $mode,
            $vehicleType,
            $fuel,
            $thermalFuel,
            $segments,
        ): BgosCrewTransportJourney {
            [$mode, $vehicleType, $fuel, $thermalFuel] = $this->normalizeMobility(
                $mode,
                $vehicleType,
                $fuel,
                $thermalFuel,
            );
            $normalizedSegments = $this->buildSegments($project, $segments);

            $journey
                ->setDate($date)
                ->setMode($mode)
                ->setVehicleType($vehicleType)
                ->setFuel($fuel)
                ->setThermalFuel($thermalFuel);

            $removedSegments = $this->synchronizeSegments($journey, $normalizedSegments);
            $this->emissionSynchronizer->synchronize($journey, $removedSegments);
            $entityManager->flush();

            return $journey;
        });
    }

    public function remove(Project $project, BgosCrewTransportJourney $journey): void
    {
        $this->assertJourneyBelongsToProject($journey, $project);

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($journey): void {
            $this->emissionSynchronizer->remove($journey);
            $entityManager->remove($journey);
            $entityManager->flush();
        });
    }

    /**
     * @return array{string, ?string, ?string, ?string}
     */
    private function normalizeMobility(
        string $mode,
        ?string $vehicleType,
        ?string $fuel,
        ?string $thermalFuel,
    ): array {
        $mode = $this->mobilityValidator->normalize($mode);
        $vehicleType = $this->mobilityValidator->normalize($vehicleType);
        $fuel = $this->mobilityValidator->normalize($fuel);
        $thermalFuel = $this->mobilityValidator->normalize($thermalFuel);

        if (null === $mode) {
            throw new \InvalidArgumentException('Journey mode is required.');
        }

        $this->mobilityValidator->assertSupported(
            $mode,
            $vehicleType,
            $fuel,
            $thermalFuel,
        );

        return [$mode, $vehicleType, $fuel, $thermalFuel];
    }

    /**
     * @param list<array{
     *     position?: int,
     *     origin: string,
     *     destination: string,
     *     originLatitude?: ?string,
     *     originLongitude?: ?string,
     *     destinationLatitude?: ?string,
     *     destinationLongitude?: ?string,
     *     distanceKm?: ?string,
     *     distanceSource?: ?string,
     *     participants: list<array{crewMember: CrewMember, role: string}>
     * }> $segments
     *
     * @return list<BgosCrewTransportSegment>
     */
    private function buildSegments(Project $project, array $segments): array
    {
        if ([] === $segments) {
            throw new \InvalidArgumentException(
                'A BGoS crew transport journey requires at least one segment.',
            );
        }

        $normalizedSegments = [];

        foreach (array_values($segments) as $position => $segmentData) {
            $origin = trim($segmentData['origin'] ?? '');
            $destination = trim($segmentData['destination'] ?? '');

            if ('' === $origin || '' === $destination) {
                throw new \InvalidArgumentException(
                    'Every BGoS crew transport segment requires an origin and destination.',
                );
            }

            $distanceKm = $this->normalizeOptionalString($segmentData['distanceKm'] ?? null);
            if (null !== $distanceKm && (!is_numeric($distanceKm) || (float) $distanceKm < 0)) {
                throw new \InvalidArgumentException(
                    'BGoS crew transport segment distance must be zero or greater.',
                );
            }

            $distanceSource = $this->normalizeOptionalString($segmentData['distanceSource'] ?? null);
            if (
                null !== $distanceSource
                && !in_array($distanceSource, BgosCrewTransportSegment::DISTANCE_SOURCES, true)
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported BGoS crew transport distance source "%s".',
                    $distanceSource,
                ));
            }

            $segment = (new BgosCrewTransportSegment())
                ->setPosition($position)
                ->setOrigin($origin)
                ->setDestination($destination)
                ->setOriginLatitude($this->normalizeOptionalString($segmentData['originLatitude'] ?? null))
                ->setOriginLongitude($this->normalizeOptionalString($segmentData['originLongitude'] ?? null))
                ->setDestinationLatitude($this->normalizeOptionalString($segmentData['destinationLatitude'] ?? null))
                ->setDestinationLongitude($this->normalizeOptionalString($segmentData['destinationLongitude'] ?? null))
                ->setDistanceKm($distanceKm)
                ->setDistanceSource($distanceSource);

            $this->addParticipants(
                $project,
                $segment,
                $segmentData['participants'] ?? [],
            );
            $normalizedSegments[] = $segment;
        }

        return $normalizedSegments;
    }

    /**
     * @param list<array{crewMember: CrewMember, role: string}> $participants
     */
    private function addParticipants(
        Project $project,
        BgosCrewTransportSegment $segment,
        array $participants,
    ): void {
        if ([] === $participants) {
            throw new \InvalidArgumentException(
                'Every BGoS crew transport segment requires at least one participant.',
            );
        }

        $seenCrewMembers = [];
        $driverCount = 0;

        foreach ($participants as $participantData) {
            $crewMember = $participantData['crewMember'] ?? null;
            if (!$crewMember instanceof CrewMember) {
                throw new \InvalidArgumentException(
                    'Every BGoS crew transport participant requires a crew member.',
                );
            }

            if ($crewMember->getProject() !== $project) {
                throw new \InvalidArgumentException(
                    'Every journey participant must belong to the journey project.',
                );
            }

            $crewMemberKey = $this->crewMemberKey($crewMember);

            if (isset($seenCrewMembers[$crewMemberKey])) {
                throw new \InvalidArgumentException(
                    'A crew member cannot participate twice in the same journey segment.',
                );
            }
            $seenCrewMembers[$crewMemberKey] = true;

            $role = $participantData['role'] ?? '';
            if (!is_string($role) || !in_array($role, BgosCrewTransportParticipant::ROLES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported BGoS crew transport participant role "%s".',
                    is_scalar($role) ? (string) $role : get_debug_type($role),
                ));
            }

            if (BgosCrewTransportParticipant::ROLE_DRIVER === $role && ++$driverCount > 1) {
                throw new \InvalidArgumentException(
                    'A journey segment cannot have more than one crew member as driver.',
                );
            }

            $segment->addParticipant(
                (new BgosCrewTransportParticipant())
                    ->setCrewMember($crewMember)
                    ->setRole($role),
            );
        }
    }

    /**
     * @param list<BgosCrewTransportSegment> $normalizedSegments
     * @return list<BgosCrewTransportSegment>
     */
    private function synchronizeSegments(
        BgosCrewTransportJourney $journey,
        array $normalizedSegments,
    ): array {
        $existingByPosition = [];
        foreach ($journey->getSegments() as $segment) {
            $existingByPosition[$segment->getPosition()] = $segment;
        }

        foreach ($normalizedSegments as $normalizedSegment) {
            $position = $normalizedSegment->getPosition();
            $segment = $existingByPosition[$position] ?? new BgosCrewTransportSegment();

            if (!isset($existingByPosition[$position])) {
                $journey->addSegment($segment);
            }
            unset($existingByPosition[$position]);

            $segment
                ->setPosition($position)
                ->setOrigin($normalizedSegment->getOrigin())
                ->setDestination($normalizedSegment->getDestination())
                ->setOriginLatitude($normalizedSegment->getOriginLatitude())
                ->setOriginLongitude($normalizedSegment->getOriginLongitude())
                ->setDestinationLatitude($normalizedSegment->getDestinationLatitude())
                ->setDestinationLongitude($normalizedSegment->getDestinationLongitude())
                ->setDistanceKm($normalizedSegment->getDistanceKm())
                ->setDistanceSource($normalizedSegment->getDistanceSource());

            $this->synchronizeParticipants(
                $segment,
                $normalizedSegment->getParticipants()->toArray(),
            );
        }

        foreach ($existingByPosition as $obsoleteSegment) {
            $journey->removeSegment($obsoleteSegment);
        }

        return array_values($existingByPosition);
    }

    /** @param list<BgosCrewTransportParticipant> $normalizedParticipants */
    private function synchronizeParticipants(
        BgosCrewTransportSegment $segment,
        array $normalizedParticipants,
    ): void {
        $existingByCrewMember = [];
        foreach ($segment->getParticipants() as $participant) {
            $crewMember = $participant->getCrewMember();
            if ($crewMember instanceof CrewMember) {
                $existingByCrewMember[$this->crewMemberKey($crewMember)] = $participant;
            }
        }

        foreach ($normalizedParticipants as $normalizedParticipant) {
            $crewMember = $normalizedParticipant->getCrewMember();
            if (!$crewMember instanceof CrewMember) {
                throw new \LogicException(
                    'Normalized BGoS crew transport participant has no crew member.',
                );
            }

            $crewMemberKey = $this->crewMemberKey($crewMember);
            $participant = $existingByCrewMember[$crewMemberKey]
                ?? new BgosCrewTransportParticipant();

            if (!isset($existingByCrewMember[$crewMemberKey])) {
                $segment->addParticipant($participant);
            }
            unset($existingByCrewMember[$crewMemberKey]);

            $participant
                ->setCrewMember($crewMember)
                ->setRole($normalizedParticipant->getRole());
        }

        foreach ($existingByCrewMember as $obsoleteParticipant) {
            $segment->removeParticipant($obsoleteParticipant);
        }
    }

    private function crewMemberKey(CrewMember $crewMember): string
    {
        return null !== $crewMember->getId()
            ? 'id:'.$crewMember->getId()
            : 'object:'.spl_object_id($crewMember);
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function assertJourneyBelongsToProject(
        BgosCrewTransportJourney $journey,
        Project $project,
    ): void {
        if ($journey->getProject() !== $project) {
            throw new \InvalidArgumentException(
                'The BGoS crew transport journey does not belong to the expected project.',
            );
        }
    }

}
