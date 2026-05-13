<?php

namespace App\Entity;

use App\Repository\DepartmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[Gedmo\TranslationEntity(class: \Gedmo\Translatable\Entity\Translation::class)]
class Department
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 80, nullable: true, unique: true)]
    private ?string $code = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length:100)]
    private ?string $name = null;

    // 'rodaje' | 'evento' | null (genérico)
    #[ORM\Column(length:20, nullable:true)]
    private ?string $projectType = null;

    public function getId(): ?int { return $this->id; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): self { $this->code = $code; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getProjectType(): ?string { return $this->projectType; }
    public function setProjectType(?string $projectType): self { $this->projectType = $projectType; return $this; }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
