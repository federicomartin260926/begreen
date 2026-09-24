<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosCrewTransportParticipant;
use App\Entity\BgosCrewTransportSegment;
use App\Entity\CrewMember;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Service\Bgos\BgosCrewMobilityValidator;
use App\Service\Bgos\BgosCrewTransportEmissionSynchronizer;
use App\Service\Bgos\BgosCrewTransportJourneyManager;
use App\Service\Emission\Transport\TransportUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BgosCrewTransportJourneyManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private BgosCrewTransportEmissionSynchronizer&MockObject $emissionSynchronizer;
    private BgosCrewTransportJourneyManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(
            fn (callable $operation): mixed => $operation($this->entityManager),
        );
        $this->emissionSynchronizer = $this->createMock(BgosCrewTransportEmissionSynchronizer::class);
        $this->manager = new BgosCrewTransportJourneyManager(
            new BgosCrewMobilityValidator(new TransportUiCatalog()),
            $this->emissionSynchronizer,
            $this->entityManager,
        );
    }

    public function testCreatesSimpleJourneyWithSegmentAndParticipant(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');
        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $journey = $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            ' car ',
            null,
            null,
            null,
            [$this->segment('Madrid', 'Toledo', [$this->participant($member, 'driver')])],
        );

        self::assertSame($project, $journey->getProject());
        self::assertSame('car', $journey->getMode());
        self::assertCount(1, $journey->getSegments());
        self::assertCount(1, $journey->getSegments()->first()->getParticipants());
        self::assertSame($member, $journey->getSegments()->first()->getParticipants()->first()->getCrewMember());
    }

    public function testNormalizesSegmentPositionsIgnoringInputValues(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');

        $journey = $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [
                $this->segment('A', 'B', [$this->participant($member, 'driver')], 40),
                $this->segment('B', 'C', [$this->participant($member, 'passenger')], 3),
            ],
        );

        self::assertSame(
            [0, 1],
            array_map(
                static fn (BgosCrewTransportSegment $segment): int => $segment->getPosition(),
                $journey->getSegments()->toArray(),
            ),
        );
    }

    public function testCreatesSharedRouteWithDifferentParticipantsPerSegment(): void
    {
        $project = new Project();
        $ana = $this->member($project, 'Ana');
        $luis = $this->member($project, 'Luis');

        $journey = $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [
                $this->segment('A', 'B', [
                    $this->participant($ana, 'driver'),
                    $this->participant($luis, 'passenger'),
                ]),
                $this->segment('B', 'C', [
                    $this->participant($luis, 'driver'),
                ]),
            ],
        );

        $segments = $journey->getSegments()->toArray();
        self::assertCount(2, $segments[0]->getParticipants());
        self::assertSame($luis, $segments[1]->getParticipants()->first()->getCrewMember());
        self::assertSame(BgosCrewTransportParticipant::ROLE_DRIVER, $segments[1]->getParticipants()->first()->getRole());
    }

    public function testRejectsCrewMemberFromAnotherProject(): void
    {
        $project = new Project();
        $otherMember = $this->member(new Project(), 'Luis');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must belong to the journey project');

        $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [$this->segment('A', 'B', [$this->participant($otherMember, 'passenger')])],
        );
    }

    public function testRejectsDuplicateParticipantInSegment(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot participate twice');

        $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [$this->segment('A', 'B', [
                $this->participant($member, 'driver'),
                $this->participant($member, 'passenger'),
            ])],
        );
    }

    public function testRejectsMoreThanOneDriverInSegment(): void
    {
        $project = new Project();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more than one');

        $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [$this->segment('A', 'B', [
                $this->participant($this->member($project, 'Ana'), 'driver'),
                $this->participant($this->member($project, 'Luis'), 'driver'),
            ])],
        );
    }

    public function testAllowsSegmentWithoutCrewMemberDriver(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');

        $journey = $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'long_distance_train',
            null,
            null,
            null,
            [$this->segment('Madrid', 'Barcelona', [
                $this->participant($member, 'passenger'),
            ])],
        );

        self::assertSame(
            BgosCrewTransportParticipant::ROLE_PASSENGER,
            $journey->getSegments()->first()->getParticipants()->first()->getRole(),
        );
    }

    public function testRejectsSegmentWithoutParticipants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one participant');

        $this->manager->create(
            new Project(),
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [$this->segment('A', 'B', [])],
        );
    }

    public function testRejectsJourneyWithoutSegments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one segment');

        $this->manager->create(
            new Project(),
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [],
        );
    }

    public function testRejectsBlankSegmentEndpoints(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an origin and destination');

        $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [$this->segment('  ', 'B', [$this->participant($member, 'driver')])],
        );
    }

    public function testRejectsNegativeDistance(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');
        $segment = $this->segment('A', 'B', [$this->participant($member, 'driver')]);
        $segment['distanceKm'] = '-0.001';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('distance must be zero or greater');

        $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [$segment],
        );
    }

    public function testRejectsUnsupportedDistanceSource(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');
        $segment = $this->segment('A', 'B', [$this->participant($member, 'driver')]);
        $segment['distanceSource'] = 'calculated';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported BGoS crew transport distance source');

        $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'car',
            null,
            null,
            null,
            [$segment],
        );
    }

    public function testUpdateSynchronizesSegmentsAndParticipantsKeepingIdentity(): void
    {
        $project = new Project();
        $ana = $this->member($project, 'Ana');
        $luis = $this->member($project, 'Luis');
        $journey = $this->journey($project, $ana);
        $oldSegment = $journey->getSegments()->first();
        $oldParticipant = $oldSegment->getParticipants()->first();
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->manager->update(
            $project,
            $journey,
            new \DateTimeImmutable('2026-09-24'),
            'car',
            null,
            null,
            null,
            [
                $this->segment('A', 'B', [$this->participant($ana, 'driver')]),
                $this->segment('B', 'C', [$this->participant($luis, 'passenger')]),
            ],
        );

        self::assertSame($journey, $result);
        self::assertCount(2, $journey->getSegments());
        self::assertSame($oldSegment, $journey->getSegments()->first());
        self::assertSame($oldParticipant, $journey->getSegments()->first()->getParticipants()->first());
        self::assertSame('2026-09-24', $journey->getDate()?->format('Y-m-d'));
        self::assertSame($luis, $journey->getSegments()->last()->getParticipants()->first()->getCrewMember());
    }

    public function testRejectsUpdateForJourneyFromAnotherProject(): void
    {
        $journeyProject = new Project();
        $member = $this->member($journeyProject, 'Ana');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');

        $this->manager->update(
            new Project(),
            $this->journey($journeyProject, $member),
            new \DateTimeImmutable('2026-09-24'),
            'car',
            null,
            null,
            null,
            [$this->segment('A', 'B', [$this->participant($member, 'driver')])],
        );
    }

    public function testRejectsRemovalForJourneyFromAnotherProject(): void
    {
        $journeyProject = new Project();
        $member = $this->member($journeyProject, 'Ana');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');

        $this->manager->remove(
            new Project(),
            $this->journey($journeyProject, $member),
        );
    }

    public function testUpdateSynchronizesJourneyWithLinkedEmissionRecord(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');
        $journey = $this->journey($project, $member);
        $journey->getSegments()->first()->setEmissionRecord(new EmissionRecord());
        $this->emissionSynchronizer->expects(self::once())
            ->method('synchronize')
            ->with($journey, []);

        $this->manager->update(
            $project,
            $journey,
            new \DateTimeImmutable('2026-09-24'),
            'car',
            null,
            null,
            null,
            [$this->segment('A', 'B', [$this->participant($member, 'driver')])],
        );
    }

    public function testRemovalSynchronizesJourneyWithLinkedEmissionRecord(): void
    {
        $project = new Project();
        $journey = $this->journey($project, $this->member($project, 'Ana'));
        $journey->getSegments()->first()->setEmissionRecord(new EmissionRecord());
        $this->emissionSynchronizer->expects(self::once())
            ->method('remove')
            ->with($journey);

        $this->manager->remove($project, $journey);
    }

    public function testRemovesJourneyWithoutEmissionRecord(): void
    {
        $project = new Project();
        $journey = $this->journey($project, $this->member($project, 'Ana'));
        $this->entityManager->expects(self::once())->method('remove')->with($journey);
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->remove($project, $journey);
    }

    public function testInvalidMobilityIsRejectedByExistingValidator(): void
    {
        $project = new Project();
        $member = $this->member($project, 'Ana');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported BGoS crew transport mode');

        $this->manager->create(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            'freight_van',
            null,
            null,
            null,
            [$this->segment('A', 'B', [$this->participant($member, 'driver')])],
        );
    }

    /**
     * @param list<array{crewMember: CrewMember, role: string}> $participants
     *
     * @return array{
     *     position: int,
     *     origin: string,
     *     destination: string,
     *     distanceKm: string,
     *     distanceSource: string,
     *     participants: list<array{crewMember: CrewMember, role: string}>
     * }
     */
    private function segment(
        string $origin,
        string $destination,
        array $participants,
        int $position = 0,
    ): array {
        return [
            'position' => $position,
            'origin' => $origin,
            'destination' => $destination,
            'distanceKm' => '10.500',
            'distanceSource' => BgosCrewTransportSegment::DISTANCE_SOURCE_MANUAL,
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

    private function journey(Project $project, CrewMember $member): BgosCrewTransportJourney
    {
        $segment = (new BgosCrewTransportSegment())
            ->setPosition(0)
            ->setOrigin('Old origin')
            ->setDestination('Old destination');
        $segment->addParticipant(
            (new BgosCrewTransportParticipant())
                ->setCrewMember($member)
                ->setRole(BgosCrewTransportParticipant::ROLE_DRIVER),
        );

        $journey = (new BgosCrewTransportJourney())
            ->setProject($project)
            ->setDate(new \DateTimeImmutable('2026-09-23'))
            ->setMode('car');
        $journey->addSegment($segment);

        return $journey;
    }
}
