<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosCrewTransportParticipant;
use App\Entity\BgosCrewTransportSegment;
use App\Entity\CrewMember;
use App\Entity\Project;
use PHPUnit\Framework\TestCase;

final class BgosCrewTransportJourneyTest extends TestCase
{
    public function testJourneyKeepsProjectDateAndMobility(): void
    {
        $project = new Project();
        $journey = (new BgosCrewTransportJourney())
            ->setProject($project)
            ->setDate(new \DateTimeImmutable('2026-09-23 18:30:00'))
            ->setMode('car')
            ->setVehicleType('medium_car')
            ->setFuel('hybrid')
            ->setThermalFuel('petrol');

        self::assertSame($project, $journey->getProject());
        self::assertSame('2026-09-23 00:00:00', $journey->getDate()?->format('Y-m-d H:i:s'));
        self::assertSame('car', $journey->getMode());
        self::assertSame('medium_car', $journey->getVehicleType());
        self::assertSame('hybrid', $journey->getFuel());
        self::assertSame('petrol', $journey->getThermalFuel());
    }

    public function testJourneyAddsAndRemovesSegmentsKeepingBothSidesSynchronized(): void
    {
        $journey = new BgosCrewTransportJourney();
        $segment = new BgosCrewTransportSegment();

        $journey->addSegment($segment);

        self::assertTrue($journey->getSegments()->contains($segment));
        self::assertSame($journey, $segment->getJourney());

        $journey->removeSegment($segment);

        self::assertFalse($journey->getSegments()->contains($segment));
        self::assertNull($segment->getJourney());
    }

    public function testSegmentKeepsPositionRouteAndDistance(): void
    {
        $segment = (new BgosCrewTransportSegment())
            ->setPosition(2)
            ->setOrigin('Madrid')
            ->setDestination('Toledo')
            ->setDistanceKm('74.350')
            ->setDistanceSource(BgosCrewTransportSegment::DISTANCE_SOURCE_MANUAL);

        self::assertSame(2, $segment->getPosition());
        self::assertSame('Madrid', $segment->getOrigin());
        self::assertSame('Toledo', $segment->getDestination());
        self::assertSame('74.350', $segment->getDistanceKm());
        self::assertSame(BgosCrewTransportSegment::DISTANCE_SOURCE_MANUAL, $segment->getDistanceSource());
    }

    public function testSegmentAddsAndRemovesParticipantsKeepingBothSidesSynchronized(): void
    {
        $segment = new BgosCrewTransportSegment();
        $participant = new BgosCrewTransportParticipant();

        $segment->addParticipant($participant);

        self::assertTrue($segment->getParticipants()->contains($participant));
        self::assertSame($segment, $participant->getSegment());

        $segment->removeParticipant($participant);

        self::assertFalse($segment->getParticipants()->contains($participant));
        self::assertNull($participant->getSegment());
    }

    public function testParticipantKeepsCrewMemberAndSupportedRoles(): void
    {
        $crewMember = (new CrewMember())->setName('Ana');
        $participant = (new BgosCrewTransportParticipant())->setCrewMember($crewMember);

        self::assertSame($crewMember, $participant->getCrewMember());
        self::assertSame(
            [
                BgosCrewTransportParticipant::ROLE_DRIVER,
                BgosCrewTransportParticipant::ROLE_PASSENGER,
            ],
            BgosCrewTransportParticipant::ROLES,
        );

        foreach (BgosCrewTransportParticipant::ROLES as $role) {
            self::assertSame($role, $participant->setRole($role)->getRole());
        }
    }

    public function testParticipantRejectsUnsupportedRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BgosCrewTransportParticipant())->setRole('unknown');
    }
}
