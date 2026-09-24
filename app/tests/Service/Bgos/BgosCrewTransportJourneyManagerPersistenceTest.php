<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosCrewTransportParticipant;
use App\Entity\BgosCrewTransportSegment;
use App\Entity\Category;
use App\Entity\CrewMember;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Bgos\BgosCrewTransportJourneyManager;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BgosCrewTransportJourneyManagerPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private BgosCrewTransportJourneyManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        $this->entityManager = $container->get('doctrine')->getManager();
        if (!$this->connection->createSchemaManager()->tablesExist([
            'bgos_crew_transport_journey',
            'bgos_crew_transport_segment',
            'bgos_crew_transport_participant',
        ])) {
            self::markTestSkipped(
                'The configured test database does not have the #63 Block 1 schema.',
            );
        }

        $this->manager = $container->get(BgosCrewTransportJourneyManager::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testUpdateSynchronizesPersistedAggregateWithoutUniqueViolations(): void
    {
        $project = (new Project())
            ->setName('BGoS journey persistence test')
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType('short')
            ->setDistributionMedia(['cinema']);
        $ana = $this->member($project, 'Ana');
        $luis = $this->member($project, 'Luis');
        $carla = $this->member($project, 'Carla');
        $project
            ->addCrewMember($ana)
            ->addCrewMember($luis)
            ->addCrewMember($carla);

        $this->entityManager->persist($project);
        $this->prepareEmissionContext($project);
        $this->entityManager->flush();

        $journey = $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            'petrol',
            null,
            null,
            [
                $this->segment('Madrid', 'Toledo', [
                    $this->participant($ana, BgosCrewTransportParticipant::ROLE_DRIVER),
                    $this->participant($luis, BgosCrewTransportParticipant::ROLE_PASSENGER),
                ], 30),
                $this->segment('Toledo', 'Madrid', [
                    $this->participant($luis, BgosCrewTransportParticipant::ROLE_PASSENGER),
                ], 70),
            ],
        );

        $journeyId = $journey->getId();
        $keptSegment = $journey->getSegments()->first();
        $removedSegment = $journey->getSegments()->last();
        $keptParticipant = $keptSegment->getParticipants()->first();
        $removedParticipant = $keptSegment->getParticipants()->last();
        $keptSegmentId = $keptSegment->getId();
        $removedSegmentId = $removedSegment->getId();
        $keptParticipantId = $keptParticipant->getId();
        $removedParticipantId = $removedParticipant->getId();
        $keptEmissionId = $keptSegment->getEmissionRecord()?->getId();
        $removedEmissionId = $removedSegment->getEmissionRecord()?->getId();
        $anaId = $ana->getId();
        $carlaId = $carla->getId();
        self::assertNotNull($keptEmissionId);
        self::assertNotNull($removedEmissionId);
        self::assertCount(2, $keptSegment->getParticipants());
        self::assertCount(2, $this->entityManager->getRepository(EmissionRecord::class)->findBy(['project' => $project]));

        $this->entityManager->clear();
        $journey = $this->findJourney($journeyId);
        $project = $journey->getProject();
        self::assertInstanceOf(Project::class, $project);
        $ana = $this->findCrewMember($anaId);
        $carla = $this->findCrewMember($carlaId);

        $this->manager->update(
            $project,
            $journey,
            new \DateTimeImmutable('2026-09-24'),
            'car',
            'diesel',
            null,
            null,
            [$this->segment('Madrid centro', 'Toledo estación', [
                $this->participant($ana, BgosCrewTransportParticipant::ROLE_PASSENGER),
                $this->participant($carla, BgosCrewTransportParticipant::ROLE_DRIVER),
            ], 99, '75.250')],
        );

        $this->entityManager->clear();
        $journey = $this->findJourney($journeyId);
        $project = $journey->getProject();
        self::assertInstanceOf(Project::class, $project);
        $ana = $this->findCrewMember($anaId);
        $carla = $this->findCrewMember($carlaId);
        $segments = $journey->getSegments()->toArray();

        self::assertCount(1, $segments);
        self::assertSame($keptSegmentId, $segments[0]->getId());
        self::assertSame(0, $segments[0]->getPosition());
        self::assertSame('Madrid centro', $segments[0]->getOrigin());
        self::assertSame('Toledo estación', $segments[0]->getDestination());
        self::assertSame('75.250', $segments[0]->getDistanceKm());
        self::assertCount(2, $segments[0]->getParticipants());
        self::assertSame($keptEmissionId, $segments[0]->getEmissionRecord()?->getId());
        self::assertSame(75.25, $segments[0]->getEmissionRecord()?->getAmount());

        $participantsByMemberId = [];
        foreach ($segments[0]->getParticipants() as $participant) {
            $memberId = $participant->getCrewMember()?->getId();
            self::assertNotNull($memberId);
            self::assertArrayNotHasKey($memberId, $participantsByMemberId);
            $participantsByMemberId[$memberId] = $participant;
        }

        self::assertSame($keptParticipantId, $participantsByMemberId[$anaId]->getId());
        self::assertSame(BgosCrewTransportParticipant::ROLE_PASSENGER, $participantsByMemberId[$anaId]->getRole());
        self::assertSame(BgosCrewTransportParticipant::ROLE_DRIVER, $participantsByMemberId[$carlaId]->getRole());
        self::assertNull($this->entityManager->find(BgosCrewTransportSegment::class, $removedSegmentId));
        self::assertNull($this->entityManager->find(BgosCrewTransportParticipant::class, $removedParticipantId));
        self::assertNull($this->entityManager->find(EmissionRecord::class, $removedEmissionId));
        self::assertCount(1, $this->entityManager->getRepository(EmissionRecord::class)->findBy(['project' => $project]));

        $trainJourney = $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-24'),
            'long_distance_train',
            null,
            null,
            null,
            [
                $this->segment('Madrid', 'Barcelona', [
                    $this->participant($ana, BgosCrewTransportParticipant::ROLE_PASSENGER),
                    $this->participant($carla, BgosCrewTransportParticipant::ROLE_PASSENGER),
                ], 0, '100.000'),
                $this->segment('Barcelona', 'Girona', [
                    $this->participant($ana, BgosCrewTransportParticipant::ROLE_PASSENGER),
                ], 1, null),
            ],
        );
        $trainSegments = $trainJourney->getSegments()->toArray();
        $trainRecord = $trainSegments[0]->getEmissionRecord();
        self::assertInstanceOf(EmissionRecord::class, $trainRecord);
        self::assertSame(200.0, $trainRecord->getAmount());
        self::assertNull($trainSegments[1]->getEmissionRecord());
        $trainInput = (new TransportEmissionSnapshot())->decode((string) $trainRecord->getCalculationDetails());
        self::assertSame('passenger_distance', $trainInput->method);
        self::assertSame('200', $trainInput->activityValue);
        self::assertNull($trainInput->passengers);
        self::assertCount(2, $this->entityManager->getRepository(EmissionRecord::class)->findBy(['project' => $project]));

        $this->manager->remove($project, $journey);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(EmissionRecord::class, $keptEmissionId));
    }

    /**
     * @param list<array{crewMember: CrewMember, role: string}> $participants
     *
     * @return array{
     *     position: int,
     *     origin: string,
     *     destination: string,
     *     distanceKm: ?string,
     *     distanceSource: ?string,
     *     participants: list<array{crewMember: CrewMember, role: string}>
     * }
     */
    private function segment(
        string $origin,
        string $destination,
        array $participants,
        int $position,
        ?string $distanceKm = '70.000',
    ): array {
        return [
            'position' => $position,
            'origin' => $origin,
            'destination' => $destination,
            'distanceKm' => $distanceKm,
            'distanceSource' => null === $distanceKm
                ? null
                : BgosCrewTransportSegment::DISTANCE_SOURCE_MANUAL,
            'participants' => $participants,
        ];
    }

    /** @return array{crewMember: CrewMember, role: string} */
    private function participant(CrewMember $member, string $role): array
    {
        return ['crewMember' => $member, 'role' => $role];
    }

    private function member(Project $project, string $name): CrewMember
    {
        return (new CrewMember())
            ->setProject($project)
            ->setName($name);
    }

    private function findJourney(?int $id): BgosCrewTransportJourney
    {
        $journey = $this->entityManager->find(BgosCrewTransportJourney::class, $id);
        self::assertInstanceOf(BgosCrewTransportJourney::class, $journey);

        return $journey;
    }

    private function findCrewMember(?int $id): CrewMember
    {
        $member = $this->entityManager->find(CrewMember::class, $id);
        self::assertInstanceOf(CrewMember::class, $member);

        return $member;
    }

    private function prepareEmissionContext(Project $project): void
    {
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-12-31'));
        $this->entityManager->persist($phase);

        $category = $this->entityManager->getRepository(Category::class)->findOneBy(['name' => 'Transporte']);
        if (!$category instanceof Category) {
            $category = (new Category())->setName('Transporte');
            $this->entityManager->persist($category);
        }

        $date = new \DateTimeImmutable('2026-09-23');
        $this->persistFactor('BGOS_BLOCK5_CAR_PETROL', new TransportEmissionInput(
            'local', 'car', 'distance', 'ES', $date, $date, '1', 'km', vehicleType: 'petrol',
        ));
        $this->persistFactor('BGOS_BLOCK5_CAR_DIESEL', new TransportEmissionInput(
            'local', 'car', 'distance', 'ES', $date, $date, '1', 'km', vehicleType: 'diesel',
        ));
        $this->persistFactor('BGOS_BLOCK5_TRAIN', new TransportEmissionInput(
            'travel', 'long_distance_train', 'passenger_distance', 'ES', $date, $date, '1', 'passenger-km',
        ));
    }

    private function persistFactor(string $factorId, TransportEmissionInput $input): void
    {
        $mapper = new TransportFactorCriteriaMapper();
        $mapping = $mapper->map($input);
        self::assertNotNull($mapping);

        $factor = (new EmissionFactor())
            ->setCategoryKey('transport')
            ->setFunctionalKey((new EmissionFactorKeyGenerator())->generate($mapping->criteria))
            ->setFactorId($factorId)
            ->setCriteria($mapping->criteria)
            ->setActivityYear(2026)
            ->setYear(2026)
            ->setValue('0.5')
            ->setUnit($mapping->criteria['unit'])
            ->setSource('BGoS Block 5 test');
        $this->entityManager->persist($factor);
    }
}
