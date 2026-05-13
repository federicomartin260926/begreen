<?php

namespace App\Entity;

use App\Repository\PlanMeasureRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanMeasureRepository::class)]
class PlanMeasure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'planMeasures')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Plan $plan = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Measure $measure = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ods $ods = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Scope $scope = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?EsG $esg = null;

    // AHORA nullable para permitir "sin responder"
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $isApplicable = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $willImplement = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $implemented = null;

    // NUEVOS CAMPOS
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $isCritical = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $criticalReason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $actionTaken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observations = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $evidence = null;

    #[ORM\Column(type: 'boolean')]
    private bool $verification = false;

    public function getId(): ?int { return $this->id; }

    public function getPlan(): ?Plan { return $this->plan; }
    public function setPlan(?Plan $plan): static { $this->plan = $plan; return $this; }

    public function getMeasure(): ?Measure { return $this->measure; }
    public function setMeasure(?Measure $measure): static { $this->measure = $measure; return $this; }

    public function getOds(): ?Ods { return $this->ods; }
    public function setOds(?Ods $ods): static { $this->ods = $ods; return $this; }

    public function getScope(): ?Scope { return $this->scope; }
    public function setScope(?Scope $scope): static { $this->scope = $scope; return $this; }

    public function getEsg(): ?EsG { return $this->esg; }
    public function setEsg(?EsG $esg): static { $this->esg = $esg; return $this; }

    // isApplicable tri-estado
    public function isApplicable(): ?bool { return $this->isApplicable; }
    public function setIsApplicable(?bool $applicable): static { $this->isApplicable = $applicable; return $this; }

    public function willImplement(): ?bool { return $this->willImplement; }
    public function setWillImplement(?bool $implement): static { $this->willImplement = $implement; return $this; }

    public function isImplemented(): ?bool { return $this->implemented; }
    public function setImplemented(?bool $implemented): static { $this->implemented = $implemented; return $this; }

    // NUEVOS getters/setters
    public function isCritical(): ?bool { return $this->isCritical; }
    public function setIsCritical(?bool $critical): static { $this->isCritical = $critical; return $this; }

    public function getCriticalReason(): ?string { return $this->criticalReason; }
    public function setCriticalReason(?string $reason): static { $this->criticalReason = $reason; return $this; }

    public function getActionTaken(): ?string { return $this->actionTaken; }
    public function setActionTaken(?string $actionTaken): self { $this->actionTaken = $actionTaken; return $this; }

    public function getObservations(): ?string { return $this->observations; }
    public function setObservations(?string $observations): self { $this->observations = $observations; return $this; }

    public function getEvidence(): ?string { return $this->evidence; }
    public function setEvidence(?string $evidence): self { $this->evidence = $evidence; return $this; }

    public function isVerification(): bool { return $this->verification; }
    public function setVerification(bool $verification): self { $this->verification = $verification; return $this; }
}
