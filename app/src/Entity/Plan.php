<?php

namespace App\Entity;

use App\Repository\PlanRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Plan
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 32, options: ['default' => 'incompleto'])]
    private string $status = 'incompleto';

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $statusChangedAt = null;

    #[ORM\OneToMany(mappedBy: 'plan', targetEntity: PlanMeasure::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $planMeasures;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Protocol $protocol = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $customMeasures = null;


    public function __construct()
    {
        $this->planMeasures = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): static { $this->project = $project; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getStatus(): string
    {
        return $this->status;
    }
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusChangedAt(): ?\DateTimeInterface
    {
        return $this->statusChangedAt;
    }
    public function setStatusChangedAt(?\DateTimeInterface $date): static
    {
        $this->statusChangedAt = $date;
        return $this;
    }

    public function getPlanMeasures(): Collection { return $this->planMeasures; }

    public function addPlanMeasure(PlanMeasure $planMeasure): static
    {
        if (!$this->planMeasures->contains($planMeasure)) {
            $this->planMeasures[] = $planMeasure;
            $planMeasure->setPlan($this);
        }
        return $this;
    }

    public function removePlanMeasure(PlanMeasure $planMeasure): static
    {
        if ($this->planMeasures->removeElement($planMeasure)) {
            if ($planMeasure->getPlan() === $this) {
                $planMeasure->setPlan(null);
            }
        }
        return $this;
    }

    public function getProtocol(): ?Protocol { return $this->protocol; }
    public function setProtocol(?Protocol $protocol): static { $this->protocol = $protocol; return $this; }

    public function getCustomMeasures(): ?string
    {
        return $this->customMeasures;
    }

    public function setCustomMeasures(?string $customMeasures): static
    {
        $this->customMeasures = $customMeasures;
        return $this;
    }
}
