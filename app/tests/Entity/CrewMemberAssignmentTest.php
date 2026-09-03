<?php

namespace App\Tests\Entity;

use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Entity\CrewPosition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

final class CrewMemberAssignmentTest extends TestCase
{
    public function testAcceptsPositionFromAssignmentDepartment(): void
    {
        $department = (new CrewDepartment())
            ->setName('Producción')
            ->setScope(CrewDepartment::SCOPE_FILMING);
        $position = (new CrewPosition())
            ->setName('Jefe/a de producción')
            ->setCrewDepartment($department);
        $assignment = (new CrewMemberAssignment())
            ->setCrewDepartment($department)
            ->setCrewPosition($position);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $assignment->validatePositionDepartment($context);
    }

    public function testRejectsPositionFromAnotherDepartment(): void
    {
        $assignmentDepartment = (new CrewDepartment())
            ->setName('Producción')
            ->setScope(CrewDepartment::SCOPE_FILMING);
        $positionDepartment = (new CrewDepartment())
            ->setName('Dirección')
            ->setScope(CrewDepartment::SCOPE_FILMING);
        $position = (new CrewPosition())
            ->setName('Director/a')
            ->setCrewDepartment($positionDepartment);
        $assignment = (new CrewMemberAssignment())
            ->setCrewDepartment($assignmentDepartment)
            ->setCrewPosition($position);

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->expects(self::once())
            ->method('atPath')
            ->with('crewPosition')
            ->willReturnSelf();
        $builder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('El cargo seleccionado no pertenece al departamento de la asignación.')
            ->willReturn($builder);

        $assignment->validatePositionDepartment($context);
    }

    public function testCrewMemberSynchronizesAssignmentOwningSide(): void
    {
        $member = new CrewMember();
        $assignment = new CrewMemberAssignment();

        $member->addAssignment($assignment);

        self::assertTrue($member->getAssignments()->contains($assignment));
        self::assertSame($member, $assignment->getCrewMember());

        $member->removeAssignment($assignment);

        self::assertFalse($member->getAssignments()->contains($assignment));
        self::assertNull($assignment->getCrewMember());
    }

    public function testAllowsTwoDifferentPositionsFromSameDepartment(): void
    {
        $department = $this->department('Producción');
        $coordinator = $this->position('Coordinador/a', $department);
        $assistant = $this->position('Ayudante', $department);
        $member = new CrewMember();
        $member->addAssignment($this->assignment($department, $coordinator));
        $member->addAssignment($this->assignment($department, $assistant));

        self::assertTrue($member->hasAssignment($department, $coordinator));
        self::assertTrue($member->hasAssignment($department, $assistant));

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $member->validateAssignmentUniqueness($context);
    }

    public function testAllowsAssignmentsFromDifferentDepartments(): void
    {
        $production = $this->department('Producción');
        $direction = $this->department('Dirección');
        $member = new CrewMember();
        $member->addAssignment($this->assignment($production, $this->position('Coordinador/a', $production)));
        $member->addAssignment($this->assignment($direction, $this->position('Ayudante', $direction)));

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $member->validateAssignmentUniqueness($context);
    }

    public function testRejectsExactDuplicateAssignment(): void
    {
        $department = $this->department('Producción');
        $position = $this->position('Coordinador/a', $department);
        $member = new CrewMember();
        $member->addAssignment($this->assignment($department, $position));
        $member->addAssignment($this->assignment($department, $position));

        $member->validateAssignmentUniqueness($this->duplicateViolationContext('assignments[1].crewPosition'));
    }

    public function testRejectsDuplicateDepartmentAssignmentsWithoutPosition(): void
    {
        $department = $this->department('Producción');
        $member = new CrewMember();
        $member->addAssignment($this->assignment($department));
        $member->addAssignment($this->assignment($department));

        self::assertTrue($member->hasAssignment($department, null));

        $member->validateAssignmentUniqueness($this->duplicateViolationContext('assignments[1].crewPosition'));
    }

    private function duplicateViolationContext(string $path): ExecutionContextInterface
    {
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->expects(self::once())->method('atPath')->with($path)->willReturnSelf();
        $builder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('No se puede repetir exactamente el mismo departamento y cargo.')
            ->willReturn($builder);

        return $context;
    }

    private function department(string $name): CrewDepartment
    {
        return (new CrewDepartment())->setName($name)->setScope(CrewDepartment::SCOPE_FILMING);
    }

    private function position(string $name, CrewDepartment $department): CrewPosition
    {
        return (new CrewPosition())->setName($name)->setCrewDepartment($department);
    }

    private function assignment(CrewDepartment $department, ?CrewPosition $position = null): CrewMemberAssignment
    {
        return (new CrewMemberAssignment())
            ->setCrewDepartment($department)
            ->setCrewPosition($position);
    }
}
