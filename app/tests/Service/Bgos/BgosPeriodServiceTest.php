<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosCrewTransportDay;
use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosCrewTransportParticipant;
use App\Entity\BgosCrewTransportSegment;
use App\Entity\CrewMember;
use App\Entity\Project;
use App\Service\Bgos\BgosPeriodService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BgosPeriodServiceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testBuildCombinesManualTrackingAndJourneyParticipantsByPersonDay(): void
    {
        $project = (new Project())
            ->setName('BGoS period journey tracking')
            ->setType('rodaje')
            ->setCountry('ES');
        $ana = $this->member($project, 'Ana');
        $luis = $this->member($project, 'Luis');
        $marta = $this->member($project, 'Marta');
        $project->addCrewMember($ana)->addCrewMember($luis)->addCrewMember($marta);
        $this->entityManager->persist($project);

        $this->entityManager->persist($this->journey($project, '2026-09-23', [[$ana, $luis], [$ana]]));
        $this->entityManager->persist($this->journey($project, '2026-09-24', [[$ana]]));
        $this->entityManager->persist(
            (new BgosCrewTransportDay())
                ->setCrewMember($marta)
                ->setDate(new \DateTimeImmutable('2026-09-24'))
                ->setStatus(BgosCrewTransportDay::STATUS_PENDING),
        );
        $this->entityManager->flush();

        $period = self::getContainer()->get(BgosPeriodService::class)->build(
            $project,
            new \DateTimeImmutable('2026-09-23'),
            new \DateTimeImmutable('2026-09-24'),
            new \DateTimeImmutable('2026-09-24'),
        );
        $tracking = $this->peopleTracking($period);

        self::assertSame(4, $tracking['expectedCount']);
        self::assertSame(3, $tracking['completedCount']);
        self::assertSame(1, $tracking['pendingCount']);
        self::assertEqualsCanonicalizing(
            [$ana->getId(), $luis->getId(), $marta->getId()],
            $tracking['trackedMemberIds'],
        );
    }

    private function member(Project $project, string $name): CrewMember
    {
        return (new CrewMember())->setProject($project)->setName($name);
    }

    /** @param list<list<CrewMember>> $segmentMembers */
    private function journey(Project $project, string $date, array $segmentMembers): BgosCrewTransportJourney
    {
        $journey = (new BgosCrewTransportJourney())
            ->setProject($project)
            ->setDate(new \DateTimeImmutable($date))
            ->setMode('car');
        foreach ($segmentMembers as $position => $members) {
            $segment = (new BgosCrewTransportSegment())
                ->setPosition($position)
                ->setOrigin('A')
                ->setDestination('B');
            foreach ($members as $member) {
                $segment->addParticipant(
                    (new BgosCrewTransportParticipant())
                        ->setCrewMember($member)
                        ->setRole(BgosCrewTransportParticipant::ROLE_PASSENGER),
                );
            }
            $journey->addSegment($segment);
        }

        return $journey;
    }

    /** @param array<string, mixed> $period
     *  @return array<string, mixed>
     */
    private function peopleTracking(array $period): array
    {
        foreach ($period['categories'] as $category) {
            if ('transport' !== $category['key']) {
                continue;
            }
            foreach ($category['subcategories'] as $subcategory) {
                if ('people' === $subcategory['key']) {
                    return $subcategory['crewTracking'];
                }
            }
        }

        self::fail('Transport people tracking was not found.');
    }
}
