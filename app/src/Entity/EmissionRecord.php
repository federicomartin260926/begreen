<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class EmissionRecord
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: ProjectPhaseDate::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ProjectPhaseDate $phase;

    #[ORM\ManyToOne(targetEntity: EmissionActivity::class)]
    #[ORM\JoinColumn(nullable: false)] // en DB sigue siendo NOT NULL
    private ?EmissionActivity $activity = null;

    #[ORM\Column(type: 'float')]
    private float $amount;

    #[ORM\Column(type: 'float')]
    private float $emission;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $calculationDetails = null;

    private ?string $subCategory = null;

    private ?string $electricityMethod = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;
        return $this;
    }

    public function getPhase(): ProjectPhaseDate
    {
        return $this->phase;
    }

    public function setPhase(ProjectPhaseDate $phase): self
    {
        $this->phase = $phase;
        return $this;
    }

    public function getActivity(): ?EmissionActivity
    {
        return $this->activity;
    }

    public function setActivity(?EmissionActivity $activity): self
    {
        $this->activity = $activity;
        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getEmission(): float
    {
        return $this->emission;
    }

    public function setEmission(float $emission): self
    {
        $this->emission = $emission;
        return $this;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(\DateTimeImmutable $registeredAt): self
    {
        $this->registeredAt = $registeredAt;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCalculationDetails(): ?string
    {
        return $this->calculationDetails;
    }

    public function setCalculationDetails(?string $calculationDetails): self
    {
        $this->calculationDetails = $calculationDetails;
        return $this;
    }

    public function getSubCategory(): ?string
    {
        return $this->subCategory;
    }

    public function setSubCategory(?string $subCategory): void
    {
        $this->subCategory = $subCategory;
    }

    public function getElectricityMethod(): ?string
    {
        return $this->electricityMethod;
    }

    public function setElectricityMethod(?string $electricityMethod): void
    {
        $this->electricityMethod = $electricityMethod;
    }
}
