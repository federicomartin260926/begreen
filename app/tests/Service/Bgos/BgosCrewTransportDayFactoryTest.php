<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosCrewProfile;
use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Service\Bgos\BgosCrewAssignmentResolver;
use App\Service\Bgos\BgosCrewTransportDayFactory;
use PHPUnit\Framework\TestCase;

final class BgosCrewTransportDayFactoryTest extends TestCase
{
    public function testCreatesDailySnapshotFromProfileDefaults(): void
    {
        $member = (new CrewMember())->setName('Ana');

        $department = (new CrewDepartment())
            ->setName('Producción')
            ->setScope(CrewDepartment::SCOPE_FILMING);

        $assignment = (new CrewMemberAssignment())
            ->setCrewDepartment($department);

        $member->addAssignment($assignment);

        $profile = (new BgosCrewProfile())
            ->setCrewMember($member)
            ->setDefaultAssignment($assignment)
            ->setDefaultOrigin('Madrid')
            ->setDefaultMode('car')
            ->setDefaultVehicleType('diesel')
            ->setDefaultFuel('diesel');

        $factory = new BgosCrewTransportDayFactory(
            new BgosCrewAssignmentResolver()
        );

        $day = $factory->create(
            $member,
            new \DateTimeImmutable('2026-09-18 19:30:00'),
            $profile,
        );

        self::assertSame($member, $day->getCrewMember());
        self::assertSame($assignment, $day->getCrewAssignment());
        self::assertSame('2026-09-18', $day->getDate()?->format('Y-m-d'));
        self::assertSame('Madrid', $day->getOrigin());
        self::assertSame('car', $day->getMode());
        self::assertSame('diesel', $day->getVehicleType());
        self::assertSame('diesel', $day->getFuel());
    }

    public function testDailySnapshotDoesNotChangeWhenProfileChangesLater(): void
    {
        $member = (new CrewMember())->setName('Ana');

        $profile = (new BgosCrewProfile())
            ->setCrewMember($member)
            ->setDefaultOrigin('Madrid')
            ->setDefaultMode('metro');

        $factory = new BgosCrewTransportDayFactory(
            new BgosCrewAssignmentResolver()
        );

        $day = $factory->create(
            $member,
            new \DateTimeImmutable('2026-09-18'),
            $profile,
        );

        $profile
            ->setDefaultOrigin('Toledo')
            ->setDefaultMode('car');

        self::assertSame('Madrid', $day->getOrigin());
        self::assertSame('metro', $day->getMode());
    }
}
