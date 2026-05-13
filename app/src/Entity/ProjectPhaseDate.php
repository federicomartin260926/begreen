<?php

namespace App\Entity;

use App\Repository\ProjectPhaseDateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProjectPhaseDateRepository::class)]
class ProjectPhaseDate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\Choice(choices: ['actividad', 'preproduccion', 'postproduccion'])]
    private ?string $phase = null;

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'phaseDates')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    public function getId(): ?int { return $this->id; }

    public function getPhase(): ?string { return $this->phase; }
    public function setPhase(string $phase): static { $this->phase = $phase; return $this; }

    public function getStartDate(): ?\DateTimeInterface { return $this->startDate; }
    public function setStartDate(\DateTimeInterface $startDate): static { $this->startDate = $startDate; return $this; }

    public function getEndDate(): ?\DateTimeInterface { return $this->endDate; }
    public function setEndDate(\DateTimeInterface $endDate): static { $this->endDate = $endDate; return $this; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): static { $this->project = $project; return $this; }
    
    public function __toString(): string
    {
        return $this->getPhase() . ' (' . $this->getStartDate()?->format('Y-m-d') . ' - ' . $this->getEndDate()?->format('Y-m-d') . ')';
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->phase !== 'postproduccion') {
            if (!$this->startDate) {
                $context->buildViolation('La fecha de inicio es obligatoria para esta fase.')
                    ->atPath('startDate')
                    ->addViolation();
            }

            if (!$this->endDate) {
                $context->buildViolation('La fecha de fin es obligatoria para esta fase.')
                    ->atPath('endDate')
                    ->addViolation();
            }
        }
    }

}
