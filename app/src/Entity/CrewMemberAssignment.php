<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\UniqueConstraint(
    name: 'uniq_crew_member_assignment',
    columns: ['crew_member_id', 'crew_department_id', 'crew_position_id']
)]
#[UniqueEntity(
    fields: ['crewMember', 'crewDepartment', 'crewPosition'],
    message: 'Esta asignación de departamento y cargo ya existe para el miembro.',
    errorPath: 'crewPosition',
    ignoreNull: false
)]
class CrewMemberAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?CrewMember $crewMember = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?CrewDepartment $crewDepartment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CrewPosition $crewPosition = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCrewMember(): ?CrewMember
    {
        return $this->crewMember;
    }

    public function setCrewMember(?CrewMember $crewMember): self
    {
        $this->crewMember = $crewMember;

        return $this;
    }

    public function getCrewDepartment(): ?CrewDepartment
    {
        return $this->crewDepartment;
    }

    public function setCrewDepartment(?CrewDepartment $crewDepartment): self
    {
        $this->crewDepartment = $crewDepartment;

        return $this;
    }

    public function getCrewPosition(): ?CrewPosition
    {
        return $this->crewPosition;
    }

    public function setCrewPosition(?CrewPosition $crewPosition): self
    {
        $this->crewPosition = $crewPosition;

        return $this;
    }

    #[Assert\Callback]
    public function validatePositionDepartment(ExecutionContextInterface $context): void
    {
        if ($this->crewPosition === null || $this->crewDepartment === null) {
            return;
        }

        if ($this->crewPosition->getCrewDepartment() !== $this->crewDepartment) {
            $context->buildViolation('El cargo seleccionado no pertenece al departamento de la asignación.')
                ->atPath('crewPosition')
                ->addViolation();
        }
    }
}
