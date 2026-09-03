<?php

namespace App\Entity;

use App\Repository\CrewMemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CrewMemberRepository::class)]
class CrewMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Email(message: 'Por favor ingresa un email válido')]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\ManyToOne(inversedBy: 'crewMembers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    /** @var Collection<int, CrewMemberAssignment> */
    #[ORM\OneToMany(mappedBy: 'crewMember', targetEntity: CrewMemberAssignment::class, cascade: ['persist'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $assignments;

    public function __construct()
    {
        $this->assignments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }

    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = $lastName; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone; return $this; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): static { $this->project = $project; return $this; }

    /** @return Collection<int, CrewMemberAssignment> */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    public function addAssignment(CrewMemberAssignment $assignment): self
    {
        if (!$this->assignments->contains($assignment)) {
            $this->assignments->add($assignment);
            $assignment->setCrewMember($this);
        }

        return $this;
    }

    public function hasAssignment(
        CrewDepartment $department,
        ?CrewPosition $position
    ): bool {
        foreach ($this->assignments as $assignment) {
            if (
                $this->isSameCrewCatalogEntity($assignment->getCrewDepartment(), $department)
                && $this->isSameCrewCatalogEntity($assignment->getCrewPosition(), $position)
            ) {
                return true;
            }
        }

        return false;
    }

    #[Assert\Callback]
    public function validateAssignmentUniqueness(ExecutionContextInterface $context): void
    {
        $seenAssignments = [];

        foreach ($this->assignments as $index => $assignment) {
            foreach ($seenAssignments as $seenAssignment) {
                if (!$this->isSameAssignment($assignment, $seenAssignment)) {
                    continue;
                }

                $context->buildViolation('No se puede repetir exactamente el mismo departamento y cargo.')
                    ->atPath(sprintf('assignments[%s].crewPosition', $index))
                    ->addViolation();

                continue 2;
            }

            $seenAssignments[] = $assignment;
        }
    }

    private function isSameAssignment(
        CrewMemberAssignment $left,
        CrewMemberAssignment $right
    ): bool {
        return null !== $left->getCrewDepartment()
            && $this->isSameCrewCatalogEntity($left->getCrewDepartment(), $right->getCrewDepartment())
            && $this->isSameCrewCatalogEntity($left->getCrewPosition(), $right->getCrewPosition());
    }

    private function isSameCrewCatalogEntity(
        CrewDepartment|CrewPosition|null $left,
        CrewDepartment|CrewPosition|null $right
    ): bool {
        if ($left === $right) {
            return true;
        }

        if (
            null === $left
            || null === $right
            || ($left instanceof CrewDepartment) !== ($right instanceof CrewDepartment)
        ) {
            return false;
        }

        return null !== $left->getId() && $left->getId() === $right->getId();
    }

    public function removeAssignment(CrewMemberAssignment $assignment): self
    {
        if ($this->assignments->removeElement($assignment) && $assignment->getCrewMember() === $this) {
            $assignment->setCrewMember(null);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name . ' ' . $this->lastName;
    }
}
