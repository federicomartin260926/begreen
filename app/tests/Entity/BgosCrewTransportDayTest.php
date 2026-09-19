<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BgosCrewTransportDay;
use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class BgosCrewTransportDayTest extends TestCase
{
    public function testDefaultsToPendingAndNormalizesDate(): void
    {
        $member = (new CrewMember())->setName('Ana');

        $day = (new BgosCrewTransportDay())
            ->setCrewMember($member)
            ->setDate(new \DateTimeImmutable('2026-09-18 17:30:00'));

        self::assertSame(BgosCrewTransportDay::STATUS_PENDING, $day->getStatus());
        self::assertSame('2026-09-18 00:00:00', $day->getDate()?->format('Y-m-d H:i:s'));
    }

    public function testAcceptsSupportedStatuses(): void
    {
        $day = new BgosCrewTransportDay();

        foreach (BgosCrewTransportDay::STATUSES as $status) {
            self::assertSame($status, $day->setStatus($status)->getStatus());
        }
    }

    public function testRejectsUnsupportedStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BgosCrewTransportDay())->setStatus('unknown');
    }

    public function testCrewAssignmentMustBelongToCrewMember(): void
    {
        $member = (new CrewMember())->setName('Ana');
        $otherMember = (new CrewMember())->setName('Luis');

        $department = (new CrewDepartment())
            ->setName('Cámara')
            ->setScope(CrewDepartment::SCOPE_FILMING);

        $assignment = (new CrewMemberAssignment())
            ->setCrewMember($otherMember)
            ->setCrewDepartment($department);

        $day = (new BgosCrewTransportDay())
            ->setCrewMember($member)
            ->setDate(new \DateTimeImmutable('2026-09-18'))
            ->setCrewAssignment($assignment);

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validate($day);

        self::assertCount(1, $violations);
        self::assertSame('crewAssignment', $violations[0]->getPropertyPath());
    }
}
