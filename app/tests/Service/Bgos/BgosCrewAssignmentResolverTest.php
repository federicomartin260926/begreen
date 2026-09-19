<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosCrewProfile;
use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Service\Bgos\BgosCrewAssignmentResolver;
use PHPUnit\Framework\TestCase;

final class BgosCrewAssignmentResolverTest extends TestCase
{
    public function testUsesOnlyAssignmentAutomatically(): void
    {
        $member = (new CrewMember())->setName('Ana');
        $assignment = $this->assignment($member, 'Producción');

        $resolver = new BgosCrewAssignmentResolver();

        self::assertSame($assignment, $resolver->resolve($member));
    }

    public function testUsesConfiguredAssignmentWhenThereAreSeveral(): void
    {
        $member = (new CrewMember())->setName('Ana');
        $this->assignment($member, 'Producción');
        $camera = $this->assignment($member, 'Cámara');

        $profile = (new BgosCrewProfile())
            ->setCrewMember($member)
            ->setDefaultAssignment($camera);

        $resolver = new BgosCrewAssignmentResolver();

        self::assertSame($camera, $resolver->resolve($member, $profile));
    }

    public function testDoesNotGuessWhenThereAreSeveralAssignments(): void
    {
        $member = (new CrewMember())->setName('Ana');
        $this->assignment($member, 'Producción');
        $this->assignment($member, 'Cámara');

        $resolver = new BgosCrewAssignmentResolver();

        self::assertNull($resolver->resolve($member));
    }

    private function assignment(
        CrewMember $member,
        string $departmentName,
    ): CrewMemberAssignment {
        $department = (new CrewDepartment())
            ->setName($departmentName)
            ->setScope(CrewDepartment::SCOPE_FILMING);

        $assignment = (new CrewMemberAssignment())
            ->setCrewDepartment($department);

        $member->addAssignment($assignment);

        return $assignment;
    }
}
