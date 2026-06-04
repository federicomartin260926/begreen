<?php

namespace App\Entity;

use App\Repository\ProjectRepository;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Project
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 50)]
    #[Assert\Choice(choices: ['rodaje', 'evento'])]
    private ?string $type = null;

    #[ORM\Column(length: 2)]
    #[Assert\Country]
    private ?string $country = null;

    /** Usuario que creó el proyecto (creador) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: "user_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?User $user = null;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectMembership::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $projectMemberships;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectPhaseDate::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $phaseDates;

    #[ORM\OneToOne(mappedBy: 'project', targetEntity: ProjectSubscription::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private ?ProjectSubscription $subscription = null;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectBillingDocument::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $billingDocuments;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: CrewMember::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $crewMembers;

    #[ORM\Column(type: 'string', length: 100, options: ['default' => 'MITECO'])]
    private string $emissionSourceName = 'MITECO';

    /* ===================== NUEVOS CAMPOS ===================== */

    // === Rodaje: tipo + género dependiente del tipo ===
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $filmingType = null;   // feature|short|tv_series|tv_program

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $filmingGenre = null;  // ver comentario en setters

    // === Rodaje: checkboxes ===
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isLiveTv = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isAdvert = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isCorporateVideo = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isMusicVideo = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isOnlineContent = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isShooting = false;

    // === Evento ===
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $eventTypePrimary = null;  // Cultural|Deportivo|...

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $eventModality = null;     // Presencial|Virtual|Híbrido

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $eventAttendeesType = null; // Presencial|Virtual

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $eventAttendeesCount = null;

    // === Bloque “confuso” (textos comunes) ===
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $medio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $presupuesto = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cine = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fechas = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tvField = null; // “TV”

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $plataformasStreaming = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agencia = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $internet = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $redesSociales = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fotografia = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $radio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $episodios = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $duracionEpisodio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $productora = null;

    /* ========================================================= */

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->projectMemberships = new ArrayCollection();
        $this->phaseDates = new ArrayCollection();
        $this->crewMembers = new ArrayCollection();
        $this->billingDocuments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(string $country): static { $this->country = $country; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    /** @return Collection<int, ProjectMembership> */
    public function getProjectMemberships(): Collection
    {
        return $this->projectMemberships;
    }

    public function addProjectMembership(ProjectMembership $membership): static
    {
        if (!$this->projectMemberships->contains($membership)) {
            $this->projectMemberships->add($membership);
            $membership->setProject($this);
        }
        return $this;
    }

    public function removeProjectMembership(ProjectMembership $membership): static
    {
        if ($this->projectMemberships->removeElement($membership)) {
            if ($membership->getProject() === $this) {
                $membership->setProject(null);
            }
        }
        return $this;
    }

    /** Helper: lista de usuarios del proyecto */
    public function getUsers(): array
    {
        return array_map(
            fn(ProjectMembership $m) => $m->getUser(),
            $this->projectMemberships->toArray()
        );
    }

    /** @return Collection<int, ProjectPhaseDate> */
    public function getPhaseDates(): Collection
    {
        return $this->phaseDates;
    }

    public function addPhaseDate(ProjectPhaseDate $phaseDate): static
    {
        if (!$this->phaseDates->contains($phaseDate)) {
            $this->phaseDates[] = $phaseDate;
            $phaseDate->setProject($this);
        }
        return $this;
    }

    public function getSubscription(): ?ProjectSubscription
    {
        return $this->subscription;
    }

    public function setSubscription(?ProjectSubscription $subscription): self
    {
        $this->subscription = $subscription;
        if ($subscription && $subscription->getProject() !== $this) {
            $subscription->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, ProjectBillingDocument> */
    public function getBillingDocuments(): Collection
    {
        return $this->billingDocuments;
    }

    public function addBillingDocument(ProjectBillingDocument $billingDocument): static
    {
        if (!$this->billingDocuments->contains($billingDocument)) {
            $this->billingDocuments->add($billingDocument);
            $billingDocument->setProject($this);
        }

        return $this;
    }

    public function removeBillingDocument(ProjectBillingDocument $billingDocument): static
    {
        if ($this->billingDocuments->removeElement($billingDocument)) {
            if ($billingDocument->getProject() === $this) {
                $billingDocument->setProject(null);
            }
        }

        return $this;
    }

    public function removePhaseDate(ProjectPhaseDate $phaseDate): static
    {
        if ($this->phaseDates->removeElement($phaseDate)) {
            if ($phaseDate->getProject() === $this) {
                $phaseDate->setProject(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, CrewMember> */
    public function getCrewMembers(): Collection
    {
        return $this->crewMembers;
    }

    public function addCrewMember(CrewMember $crewMember): static
    {
        if (!$this->crewMembers->contains($crewMember)) {
            $this->crewMembers[] = $crewMember;
            $crewMember->setProject($this);
        }
        return $this;
    }

    public function removeCrewMember(CrewMember $crewMember): static
    {
        if ($this->crewMembers->removeElement($crewMember)) {
            if ($crewMember->getProject() === $this) {
                $crewMember->setProject(null);
            }
        }
        return $this;
    }

    public function getEmissionSourceName(): string
    {
        return $this->emissionSourceName;
    }

    public function setEmissionSourceName(string $emissionSourceName): self
    {
        if (!in_array($emissionSourceName, ['MITECO', 'DEFRA'], true)) {
            throw new \InvalidArgumentException('La fuente debe ser MITECO o DEFRA');
        }
        $this->emissionSourceName = $emissionSourceName;
        return $this;
    }

    /**
     * Devuelve la CLAVE de traducción de la fase según el tipo de proyecto.
     * Recuerda traducir en Twig con |trans.
     */
    public function getPhaseLabel(string $phase): string
    {
        $mapRodaje = [
            'preproduccion'  => 'backend.project.phase.preproduction',
            'actividad'      => 'backend.project.phase.shoot',
            'postproduccion' => 'backend.project.phase.postproduction',
        ];

        $mapEvento = [
            'preproduccion'  => 'backend.project.phase.setup',
            'actividad'      => 'backend.project.phase.event',
            'postproduccion' => 'backend.project.phase.teardown',
        ];

        $key = $this->type === 'rodaje'
            ? ($mapRodaje[$phase] ?? null)
            : ($mapEvento[$phase] ?? null);

        // Fallback genérico por si llega una fase desconocida
        return $key ?? ('backend.project.phase.' . $phase);
    }

    #[Assert\Callback]
    public function validatePhaseDates(ExecutionContextInterface $context): void
    {
        $orderedPhases = [
            'preproduccion' => 1,
            'montaje'       => 1,
            'actividad'     => 2,
            'postproduccion'=> 3,
            'desmontaje'    => 3,
        ];

        $dates = [];

        foreach ($this->getPhaseDates() as $i => $phaseDate) {
            $start = $phaseDate->getStartDate();
            $end   = $phaseDate->getEndDate();
            $phase = $phaseDate->getPhase();

            // Validar si start o end están vacíos
            if (!$start) {
                $context->buildViolation('backend.project.validation.start_required')
                    ->atPath("phaseDates[$i].startDate")
                    ->addViolation();
                continue;
            }

            if (!$end) {
                $context->buildViolation('backend.project.validation.end_required')
                    ->atPath("phaseDates[$i].endDate")
                    ->addViolation();
                continue;
            }

            // Validación de rango incorrecto
            if ($end < $start) {
                $context->buildViolation('backend.project.validation.end_before_start')
                    ->atPath("phaseDates[$i].endDate")
                    ->addViolation();
            }

            // Almacenar para orden y solapamiento
            $dates[] = [
                'index' => $i,
                'phase' => $phase,
                'start' => $start,
                'end'   => $end,
                'order' => $orderedPhases[$phase] ?? 99,
            ];
        }

        // Validación de solapamiento cronológico
        usort($dates, fn($a, $b) => $a['order'] <=> $b['order']);
        for ($j = 1; $j < count($dates); $j++) {
            if ($dates[$j]['start'] < $dates[$j - 1]['end']) {
                $context->buildViolation('backend.project.validation.overlap')
                    ->atPath("phaseDates[{$dates[$j]['index']}].startDate")
                    ->addViolation();
            }
        }
    }

    /* ===================== Getters/Setters nuevos ===================== */

    // Rodaje: tipo + género dependiente
    public function getFilmingType(): ?string { return $this->filmingType; }
    public function setFilmingType(?string $filmingType): self { $this->filmingType = $filmingType; return $this; }

    public function getFilmingGenre(): ?string { return $this->filmingGenre; }
    public function setFilmingGenre(?string $filmingGenre): self { $this->filmingGenre = $filmingGenre; return $this; }

    // Rodaje: checkboxes
    public function isLiveTv(): bool { return $this->isLiveTv; }
    public function setIsLiveTv(bool $isLiveTv): self { $this->isLiveTv = $isLiveTv; return $this; }

    public function isAdvert(): bool { return $this->isAdvert; }
    public function setIsAdvert(bool $isAdvert): self { $this->isAdvert = $isAdvert; return $this; }

    public function isCorporateVideo(): bool { return $this->isCorporateVideo; }
    public function setIsCorporateVideo(bool $isCorporateVideo): self { $this->isCorporateVideo = $isCorporateVideo; return $this; }

    public function isMusicVideo(): bool { return $this->isMusicVideo; }
    public function setIsMusicVideo(bool $isMusicVideo): self { $this->isMusicVideo = $isMusicVideo; return $this; }

    public function isOnlineContent(): bool { return $this->isOnlineContent; }
    public function setIsOnlineContent(bool $isOnlineContent): self { $this->isOnlineContent = $isOnlineContent; return $this; }

    public function isShooting(): bool { return $this->isShooting; }
    public function setIsShooting(bool $isShooting): self { $this->isShooting = $isShooting; return $this; }

    // Evento
    public function getEventTypePrimary(): ?string { return $this->eventTypePrimary; }
    public function setEventTypePrimary(?string $eventTypePrimary): self { $this->eventTypePrimary = $eventTypePrimary; return $this; }

    public function getEventModality(): ?string { return $this->eventModality; }
    public function setEventModality(?string $eventModality): self { $this->eventModality = $eventModality; return $this; }

    public function getEventAttendeesType(): ?string { return $this->eventAttendeesType; }
    public function setEventAttendeesType(?string $eventAttendeesType): self { $this->eventAttendeesType = $eventAttendeesType; return $this; }

    public function getEventAttendeesCount(): ?int { return $this->eventAttendeesCount; }
    public function setEventAttendeesCount(?int $eventAttendeesCount): self { $this->eventAttendeesCount = $eventAttendeesCount; return $this; }

    // Comunes (texto)
    public function getMedio(): ?string { return $this->medio; }
    public function setMedio(?string $medio): self { $this->medio = $medio; return $this; }

    public function getPresupuesto(): ?string { return $this->presupuesto; }
    public function setPresupuesto(?string $presupuesto): self { $this->presupuesto = $presupuesto; return $this; }

    public function getCine(): ?string { return $this->cine; }
    public function setCine(?string $cine): self { $this->cine = $cine; return $this; }

    public function getFechas(): ?string { return $this->fechas; }
    public function setFechas(?string $fechas): self { $this->fechas = $fechas; return $this; }

    public function getTvField(): ?string { return $this->tvField; }
    public function setTvField(?string $tvField): self { $this->tvField = $tvField; return $this; }

    public function getPlataformasStreaming(): ?string { return $this->plataformasStreaming; }
    public function setPlataformasStreaming(?string $plataformasStreaming): self { $this->plataformasStreaming = $plataformasStreaming; return $this; }

    public function getAgencia(): ?string { return $this->agencia; }
    public function setAgencia(?string $agencia): self { $this->agencia = $agencia; return $this; }

    public function getInternet(): ?string { return $this->internet; }
    public function setInternet(?string $internet): self { $this->internet = $internet; return $this; }

    public function getRedesSociales(): ?string { return $this->redesSociales; }
    public function setRedesSociales(?string $redesSociales): self { $this->redesSociales = $redesSociales; return $this; }

    public function getFotografia(): ?string { return $this->fotografia; }
    public function setFotografia(?string $fotografia): self { $this->fotografia = $fotografia; return $this; }

    public function getRadio(): ?string { return $this->radio; }
    public function setRadio(?string $radio): self { $this->radio = $radio; return $this; }

    public function getEpisodios(): ?string { return $this->episodios; }
    public function setEpisodios(?string $episodios): self { $this->episodios = $episodios; return $this; }

    public function getDuracionEpisodio(): ?string { return $this->duracionEpisodio; }
    public function setDuracionEpisodio(?string $duracionEpisodio): self { $this->duracionEpisodio = $duracionEpisodio; return $this; }

    public function getProductora(): ?string { return $this->productora; }
    public function setProductora(?string $productora): self { $this->productora = $productora; return $this; }
}
