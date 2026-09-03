<?php

namespace App\Entity;

use App\Repository\CrewDepartmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CrewDepartmentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_crew_department_scope_name', columns: ['scope', 'name'])]
#[UniqueEntity(fields: ['scope', 'name'], errorPath: 'name', message: 'Ya existe un departamento con este nombre en el mismo ámbito.')]
#[Gedmo\TranslationEntity(class: \Gedmo\Translatable\Entity\Translation::class)]
class CrewDepartment
{
    public const SCOPE_FILMING = 'rodaje';
    public const SCOPE_EVENT = 'evento';
    public const SCOPE_ANIMATION = 'animacion';

    public const SCOPES = [
        self::SCOPE_FILMING,
        self::SCOPE_EVENT,
        self::SCOPE_ANIMATION,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::SCOPES)]
    private ?string $scope = null;

    /** @var Collection<int, CrewPosition> */
    #[ORM\OneToMany(mappedBy: 'crewDepartment', targetEntity: CrewPosition::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $positions;

    /** @var Collection<int, Department> */
    #[ORM\ManyToMany(targetEntity: Department::class)]
    #[ORM\JoinTable(name: 'crew_department_measure_department')]
    #[ORM\JoinColumn(name: 'crew_department_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'department_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $compatibleMeasureDepartments;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
        $this->compatibleMeasureDepartments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(string $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    /** @return Collection<int, CrewPosition> */
    public function getPositions(): Collection
    {
        return $this->positions;
    }

    public function addPosition(CrewPosition $position): self
    {
        if (!$this->positions->contains($position)) {
            $this->positions->add($position);
            $position->setCrewDepartment($this);
        }

        return $this;
    }

    public function removePosition(CrewPosition $position): self
    {
        if ($this->positions->removeElement($position) && $position->getCrewDepartment() === $this) {
            $position->setCrewDepartment(null);
        }

        return $this;
    }

    /** @return Collection<int, Department> */
    public function getCompatibleMeasureDepartments(): Collection
    {
        return $this->compatibleMeasureDepartments;
    }

    public function addCompatibleMeasureDepartment(Department $department): self
    {
        if (!$this->compatibleMeasureDepartments->contains($department)) {
            $this->compatibleMeasureDepartments->add($department);
        }

        return $this;
    }

    public function removeCompatibleMeasureDepartment(Department $department): self
    {
        $this->compatibleMeasureDepartments->removeElement($department);

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
