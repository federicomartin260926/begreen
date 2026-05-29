<?php

namespace App\Entity;

use App\Repository\SustainabilityPlanBlockAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SustainabilityPlanBlockAnswerRepository::class)]
#[ORM\Table(
    name: 'sustainability_plan_block_answer',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_plan_block_answer', columns: ['sustainability_plan_id', 'measure_block_id']),
    ]
)]
class SustainabilityPlanBlockAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blockAnswers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Plan $sustainabilityPlan = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MeasureBlock $measureBlock = null;

    #[ORM\Column(type: 'boolean')]
    private bool $applies = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $answeredAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $answeredBy = null;

    public function __construct()
    {
        $this->answeredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSustainabilityPlan(): ?Plan
    {
        return $this->sustainabilityPlan;
    }

    public function setSustainabilityPlan(?Plan $sustainabilityPlan): self
    {
        $this->sustainabilityPlan = $sustainabilityPlan;

        return $this;
    }

    public function getMeasureBlock(): ?MeasureBlock
    {
        return $this->measureBlock;
    }

    public function setMeasureBlock(?MeasureBlock $measureBlock): self
    {
        $this->measureBlock = $measureBlock;

        return $this;
    }

    public function applies(): bool
    {
        return $this->applies;
    }

    public function setApplies(bool $applies): self
    {
        $this->applies = $applies;

        return $this;
    }

    public function getAnsweredAt(): \DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function setAnsweredAt(\DateTimeImmutable $answeredAt): self
    {
        $this->answeredAt = $answeredAt;

        return $this;
    }

    public function getAnsweredBy(): ?User
    {
        return $this->answeredBy;
    }

    public function setAnsweredBy(?User $answeredBy): self
    {
        $this->answeredBy = $answeredBy;

        return $this;
    }
}
