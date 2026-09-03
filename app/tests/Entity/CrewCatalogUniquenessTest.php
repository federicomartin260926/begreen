<?php

namespace App\Tests\Entity;

use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewPosition;
use App\Entity\Department;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

final class CrewCatalogUniquenessTest extends TestCase
{
    public function testSameDepartmentNameIsAllowedInDifferentScopesButRejectedWithinOneScope(): void
    {
        $ormConstraint = $this->classAttribute(CrewDepartment::class, ORM\UniqueConstraint::class);
        $validationConstraint = $this->classAttribute(CrewDepartment::class, UniqueEntity::class);

        self::assertSame(['scope', 'name'], $ormConstraint->columns);
        self::assertSame(['scope', 'name'], $validationConstraint->fields);

        $filming = (new CrewDepartment())->setName('Producción')->setScope(CrewDepartment::SCOPE_FILMING);
        $event = (new CrewDepartment())->setName('Producción')->setScope(CrewDepartment::SCOPE_EVENT);

        self::assertSame($filming->getName(), $event->getName());
        self::assertNotSame($filming->getScope(), $event->getScope());
    }

    public function testSamePositionNameIsAllowedInDifferentDepartmentsButRejectedWithinOneDepartment(): void
    {
        $ormConstraint = $this->classAttribute(CrewPosition::class, ORM\UniqueConstraint::class);
        $validationConstraint = $this->classAttribute(CrewPosition::class, UniqueEntity::class);

        self::assertSame(['crew_department_id', 'name'], $ormConstraint->columns);
        self::assertSame(['crewDepartment', 'name'], $validationConstraint->fields);

        $production = (new CrewDepartment())->setName('Producción')->setScope(CrewDepartment::SCOPE_FILMING);
        $direction = (new CrewDepartment())->setName('Dirección')->setScope(CrewDepartment::SCOPE_FILMING);
        $productionAssistant = (new CrewPosition())->setName('Ayudante')->setCrewDepartment($production);
        $directionAssistant = (new CrewPosition())->setName('Ayudante')->setCrewDepartment($direction);

        self::assertSame($productionAssistant->getName(), $directionAssistant->getName());
        self::assertNotSame($productionAssistant->getCrewDepartment(), $directionAssistant->getCrewDepartment());
    }

    public function testCrewMemberAssignmentsAreCascadeValidated(): void
    {
        $property = new \ReflectionProperty(CrewMember::class, 'assignments');

        self::assertCount(1, $property->getAttributes(Assert\Valid::class));
    }

    public function testCrewDepartmentSupportsMultipleCompatibleMeasureDepartmentsWithoutDuplicates(): void
    {
        $crewDepartment = (new CrewDepartment())
            ->setName('Departamento compuesto')
            ->setScope(CrewDepartment::SCOPE_EVENT);
        $technical = (new Department())->setName('Técnica');
        $staging = (new Department())->setName('Montaje');

        $crewDepartment
            ->addCompatibleMeasureDepartment($technical)
            ->addCompatibleMeasureDepartment($technical)
            ->addCompatibleMeasureDepartment($staging);

        self::assertSame([$technical, $staging], $crewDepartment->getCompatibleMeasureDepartments()->toArray());

        $crewDepartment->removeCompatibleMeasureDepartment($technical);

        self::assertSame([$staging], array_values($crewDepartment->getCompatibleMeasureDepartments()->toArray()));
    }

    private function classAttribute(string $class, string $attribute): object
    {
        $attributes = (new \ReflectionClass($class))->getAttributes($attribute);

        self::assertCount(1, $attributes);

        return $attributes[0]->newInstance();
    }
}
