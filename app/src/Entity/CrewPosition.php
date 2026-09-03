<?php

namespace App\Entity;

use App\Repository\CrewPositionRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CrewPositionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_crew_position_department_name', columns: ['crew_department_id', 'name'])]
#[UniqueEntity(fields: ['crewDepartment', 'name'], errorPath: 'name', message: 'Ya existe un cargo con este nombre en el mismo departamento.')]
#[Gedmo\TranslationEntity(class: \Gedmo\Translatable\Entity\Translation::class)]
class CrewPosition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\ManyToOne(inversedBy: 'positions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?CrewDepartment $crewDepartment = null;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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

    public function getCrewDepartment(): ?CrewDepartment
    {
        return $this->crewDepartment;
    }

    public function setCrewDepartment(?CrewDepartment $crewDepartment): self
    {
        $this->crewDepartment = $crewDepartment;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
