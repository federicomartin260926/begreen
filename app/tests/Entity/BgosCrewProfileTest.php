<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BgosCrewProfile;
use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class BgosCrewProfileTest extends TestCase
{
    public function testDefaultAssignmentMayBelongToCrewMember(): void
    {
        $member = (new CrewMember())->setName('Ana');
        $department = (new CrewDepartment())
            ->setName('Producción')
            ->setScope(CrewDepartment::SCOPE_FILMING);

        $assignment = (new CrewMemberAssignment())
            ->setCrewMember($member)
            ->setCrewDepartment($department);

        $profile = (new BgosCrewProfile())
            ->setCrewMember($member)
            ->setDefaultAssignment($assignment)
            ->setDefaultOrigin('Madrid')
            ->setDefaultMode('car')
            ->setDefaultVehicleType('diesel');

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validate($profile);

        self::assertCount(0, $violations);
        self::assertSame('Madrid', $profile->getDefaultOrigin());
        self::assertSame('car', $profile->getDefaultMode());
        self::assertSame('diesel', $profile->getDefaultVehicleType());
    }

    public function testDefaultAssignmentMustBelongToCrewMember(): void
    {
        $member = (new CrewMember())->setName('Ana');
        $otherMember = (new CrewMember())->setName('Luis');

        $department = (new CrewDepartment())
            ->setName('Producción')
            ->setScope(CrewDepartment::SCOPE_FILMING);

        $assignment = (new CrewMemberAssignment())
            ->setCrewMember($otherMember)
            ->setCrewDepartment($department);

        $profile = (new BgosCrewProfile())
            ->setCrewMember($member)
            ->setDefaultAssignment($assignment);

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validate($profile);

        self::assertCount(1, $violations);
        self::assertSame('defaultAssignment', $violations[0]->getPropertyPath());
    }
}
