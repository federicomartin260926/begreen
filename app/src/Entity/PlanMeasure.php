<?php

namespace App\Entity;

use App\Repository\PlanMeasureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\ManyToOne(inversedBy: 'planMeasures')]
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

    #[ORM\ManyToMany(targetEntity: CrewMember::class)]
    #[ORM\JoinTable(name: 'plan_measure_responsibles')]
    private Collection $responsibleCrewMembers;

    // AHORA nullable para permitir "sin responder"
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $isApplicable = null;

    #[ORM\Column(length: 20, options: ['default' => 'manual'])]
    private string $applicabilitySource = 'manual';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SustainabilityPlanBlockAnswer $blockSkipAnswer = null;

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
    private ?string $executionIncident = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $internalNotes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $evidence = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $evidenceMetadata = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    // En la gamificación actual actúa como hito persistente de la primera aceptación afirmativa.
    private ?\DateTimeImmutable $firstDecisionAnsweredAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $criticalGamificationHandledAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $verification = false;

    public function __construct()
    {
        $this->responsibleCrewMembers = new ArrayCollection();
    }

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

    public function hasPrimaryDecision(): bool
    {
        return $this->isApplicable === false
            || ($this->isApplicable === true && $this->willImplement !== null);
    }

    public function getPrimaryDecision(): ?string
    {
        if ($this->isApplicable === false) {
            return 'na';
        }

        if ($this->isApplicable !== true || $this->willImplement === null) {
            return null;
        }

        return $this->willImplement ? 'true' : 'false';
    }

    public function getFirstDecisionAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->firstDecisionAnsweredAt;
    }

    public function markFirstDecisionAnswered(?\DateTimeImmutable $answeredAt = null): static
    {
        $this->firstDecisionAnsweredAt ??= $answeredAt ?? new \DateTimeImmutable();

        return $this;
    }

    public function getCriticalGamificationHandledAt(): ?\DateTimeImmutable
    {
        return $this->criticalGamificationHandledAt;
    }

    public function markCriticalGamificationHandled(?\DateTimeImmutable $handledAt = null): static
    {
        $this->criticalGamificationHandledAt ??= $handledAt ?? new \DateTimeImmutable();

        return $this;
    }

    // isApplicable tri-estado
    public function isApplicable(): ?bool { return $this->isApplicable; }
    public function setIsApplicable(?bool $applicable): static { $this->isApplicable = $applicable; return $this; }

    public function getApplicabilitySource(): string
    {
        return $this->applicabilitySource;
    }

    public function setApplicabilitySource(string $applicabilitySource): static
    {
        $this->applicabilitySource = $applicabilitySource;
        return $this;
    }

    public function markAsManual(): static
    {
        $this->applicabilitySource = 'manual';
        $this->blockSkipAnswer = null;

        return $this;
    }

    public function markAsBlockSkipped(SustainabilityPlanBlockAnswer $blockSkipAnswer): static
    {
        $this->applicabilitySource = 'block_skip';
        $this->blockSkipAnswer = $blockSkipAnswer;

        return $this;
    }

    public function getBlockSkipAnswer(): ?SustainabilityPlanBlockAnswer
    {
        return $this->blockSkipAnswer;
    }

    public function setBlockSkipAnswer(?SustainabilityPlanBlockAnswer $blockSkipAnswer): static
    {
        $this->blockSkipAnswer = $blockSkipAnswer;
        return $this;
    }

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

    public function hasActionTaken(): bool
    {
        return trim((string) $this->actionTaken) !== '';
    }

    public function getObservations(): ?string { return $this->observations; }
    public function setObservations(?string $observations): self { $this->observations = $observations; return $this; }

    public function getExecutionIncident(): ?string { return $this->executionIncident; }
    public function setExecutionIncident(?string $executionIncident): self { $this->executionIncident = $executionIncident; return $this; }

    public function getInternalNotes(): ?string { return $this->internalNotes; }
    public function setInternalNotes(?string $internalNotes): self { $this->internalNotes = $internalNotes; return $this; }

    public function getEvidence(): ?string { return $this->evidence; }
    public function setEvidence(?string $evidence): self { $this->evidence = $evidence; return $this; }

    /**
     * @return string[]
     */
    /**
     * Persisted evidence paths stored as newline-separated text in the database.
     * This does not validate filesystem existence.
     *
     * @return string[]
     */
    public function getEvidencePaths(): array
    {
        $paths = array_filter(array_map(
            'trim',
            preg_split('/\R/u', (string) $this->evidence) ?: []
        ));

        return array_values($paths);
    }

    public function hasEvidence(): bool
    {
        return $this->getEvidencePaths() !== [];
    }

    public function canBeMarkedAsImplemented(): bool
    {
        return $this->hasActionTaken() && $this->hasEvidence();
    }

    public function normalizeImplementedState(): bool
    {
        if ($this->implemented === true && !$this->canBeMarkedAsImplemented()) {
            $this->implemented = false;

            return true;
        }

        return false;
    }

    /**
     * @return array<string, string>|null
     */
    public function getEvidenceMetadata(): ?array
    {
        return $this->evidenceMetadata;
    }

    /**
     * @param array<string, string>|null $evidenceMetadata
     */
    public function setEvidenceMetadata(?array $evidenceMetadata): self
    {
        $normalized = [];
        foreach ($evidenceMetadata ?? [] as $path => $sourceCode) {
            $path = trim((string) $path);
            $sourceCode = trim((string) $sourceCode);
            if ($path === '' || $sourceCode === '') {
                continue;
            }
            $normalized[$path] = $sourceCode;
        }

        $this->evidenceMetadata = $normalized !== [] ? $normalized : null;

        return $this;
    }

    public function getEvidenceSourceCodeForPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $this->evidenceMetadata === null) {
            return null;
        }

        $code = $this->evidenceMetadata[$path] ?? null;

        return is_string($code) && trim($code) !== '' ? $code : null;
    }

    public function setEvidenceSourceCodeForPath(string $path, ?string $sourceCode): self
    {
        $path = trim($path);
        if ($path === '') {
            return $this;
        }

        $metadata = $this->evidenceMetadata ?? [];
        $sourceCode = trim((string) $sourceCode);

        if ($sourceCode === '') {
            unset($metadata[$path]);
        } else {
            $metadata[$path] = $sourceCode;
        }

        $this->evidenceMetadata = $metadata !== [] ? $metadata : null;

        return $this;
    }

    public function removeEvidenceSourceCodeForPath(string $path): self
    {
        return $this->setEvidenceSourceCodeForPath($path, null);
    }

    public function isVerification(): bool { return $this->verification; }
    public function setVerification(bool $verification): self { $this->verification = $verification; return $this; }

    /** @return Collection<int, CrewMember> */
    public function getResponsibleCrewMembers(): Collection
    {
        return $this->responsibleCrewMembers;
    }

    public function addResponsibleCrewMember(CrewMember $crewMember): self
    {
        if (!$this->responsibleCrewMembers->contains($crewMember)) {
            $this->responsibleCrewMembers->add($crewMember);
        }

        return $this;
    }

    public function removeResponsibleCrewMember(CrewMember $crewMember): self
    {
        $this->responsibleCrewMembers->removeElement($crewMember);

        return $this;
    }
}
