<?php

namespace App\Entity;

use App\Repository\CrewMemberRepository;
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Position $position = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Department $department = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Email(message: 'Por favor ingresa un email válido')]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\ManyToOne(inversedBy: 'crewMembers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    // --- Validador de coherencia ---
    #[Assert\Callback]
    public function validateConsistency(ExecutionContextInterface $ctx): void
    {
        $pos = $this->getPosition();
        $dep = $this->getDepartment();

        // Si no hay cargo o el cargo no tiene departamento asociado, no hay nada que validar aquí
        if (!$pos || !$pos->getDepartment()) {
            return;
        }

        // Si hay departamento elegido y no coincide con el del cargo => error en 'position'
        if ($dep && $dep->getId() !== $pos->getDepartment()->getId()) {
            $ctx->buildViolation('El cargo seleccionado pertenece al departamento "{{ deptOfPos }}".')
                ->setParameter('{{ deptOfPos }}', $pos->getDepartment()->getName() ?? 'desconocido')
                ->atPath('position')
                ->addViolation();
            return;
        }

        // (Opcional) Si quieres además validar el tipo de proyecto:
        // - Solo si todo lo anterior está en orden.
        $project = $this->getProject();
        if ($project && $project->getType()) {
            $deptOfPos = $pos->getDepartment();
            $pt = $deptOfPos?->getProjectType(); // 'rodaje'|'evento'|null
            if ($pt && $pt !== $project->getType()) {
                $ctx->buildViolation('El cargo pertenece a un departamento de tipo "{{ deptType }}", distinto al tipo del proyecto.')
                    ->setParameter('{{ deptType }}', $pt)
                    ->atPath('position')
                    ->addViolation();
            }
        }
    }


    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }

    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = $lastName; return $this; }

    public function getPosition(): ?Position { return $this->position; }
    public function setPosition(?Position $position): self { $this->position = $position; return $this; }

    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(?Department $department): self { $this->department = $department; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone; return $this; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): static { $this->project = $project; return $this; }

    public function __toString(): string
    {
        return $this->name . ' ' . $this->lastName;
    }
}
