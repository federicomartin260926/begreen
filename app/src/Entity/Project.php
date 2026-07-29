<?php

namespace App\Entity;

use App\Enum\ProjectCatalog;
use App\Enum\CommercialPhase;
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

    public const DEFAULT_EMISSION_SOURCE_NAME = 'MITECO';
    public const EMISSION_SOURCE_NAMES = [
        self::DEFAULT_EMISSION_SOURCE_NAME,
        'DEFRA',
    ];

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

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectCompany::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $projectCompanies;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectFundingSource::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $projectFundingSources;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectSubscription::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $subscriptions;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectBillingDocument::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $billingDocuments;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: CrewMember::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $crewMembers;

    #[ORM\Column(type: 'string', length: 100, options: ['default' => self::DEFAULT_EMISSION_SOURCE_NAME])]
    private string $emissionSourceName = self::DEFAULT_EMISSION_SOURCE_NAME;

    // === Rodaje: tipo + género dependiente del tipo ===
    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Choice(choices: ProjectCatalog::FILMING_TYPES)]
    private ?string $filmingType = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $filmingGenre = null;

    // === Evento ===
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $eventTypePrimary = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $eventModality = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $eventAttendeesCount = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mainLocation = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $eventOnlineConnections = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ProjectCatalog::ECO_MANAGER_STATUSES)]
    private ?string $ecoManagerStatus = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $presupuesto = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    private ?int $episodios = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    private ?int $duracionEpisodio = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Assert\All([
        new Assert\Choice(choices: ProjectCatalog::DISTRIBUTION_MEDIA),
    ])]
    private ?array $distributionMedia = [];

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->projectMemberships = new ArrayCollection();
        $this->phaseDates = new ArrayCollection();
        $this->projectCompanies = new ArrayCollection();
        $this->projectFundingSources = new ArrayCollection();
        $this->crewMembers = new ArrayCollection();
        $this->billingDocuments = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
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

    /** @return Collection<int, ProjectCompany> */
    public function getProjectCompanies(): Collection
    {
        return $this->projectCompanies;
    }

    public function addProjectCompany(ProjectCompany $company): static
    {
        if (!$this->projectCompanies->contains($company)) {
            $this->projectCompanies->add($company);
            $company->setProject($this);
        }

        return $this;
    }

    public function removeProjectCompany(ProjectCompany $company): static
    {
        if ($this->projectCompanies->removeElement($company) && $company->getProject() === $this) {
            $company->setProject(null);
        }

        return $this;
    }

    /** @return Collection<int, ProjectFundingSource> */
    public function getProjectFundingSources(): Collection
    {
        return $this->projectFundingSources;
    }

    public function addProjectFundingSource(ProjectFundingSource $source): static
    {
        if (!$this->projectFundingSources->contains($source)) {
            $this->projectFundingSources->add($source);
            $source->setProject($this);
        }

        return $this;
    }

    public function removeProjectFundingSource(ProjectFundingSource $source): static
    {
        if ($this->projectFundingSources->removeElement($source) && $source->getProject() === $this) {
            $source->setProject(null);
        }

        return $this;
    }

    public function addPhaseDate(ProjectPhaseDate $phaseDate): static
    {
        if (!$this->phaseDates->contains($phaseDate)) {
            $this->phaseDates[] = $phaseDate;
            $phaseDate->setProject($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, ProjectSubscription>
     */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function getSubscriptionForPhase(CommercialPhase $phase): ?ProjectSubscription
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->getPhase() === $phase) {
                return $subscription;
            }
        }

        return null;
    }

    public function addSubscription(ProjectSubscription $subscription): self
    {
        foreach ($this->subscriptions as $existing) {
            if ($existing->getPhase() === $subscription->getPhase() && $existing !== $subscription) {
                throw new \InvalidArgumentException(sprintf(
                    'Project already has a subscription for phase "%s".',
                    $subscription->getPhase()->value
                ));
            }
        }

        if (!$this->subscriptions->contains($subscription)) {
            $this->subscriptions->add($subscription);
            if ($subscription->getProject() !== $this) {
                $subscription->setProject($this);
            }
        }

        return $this;
    }

    public function removeSubscription(ProjectSubscription $subscription): self
    {
        if ($this->subscriptions->removeElement($subscription) && $subscription->getProject() === $this) {
            $subscription->setProject(null);
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
        if (!in_array($emissionSourceName, self::EMISSION_SOURCE_NAMES, true)) {
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

    // Evento
    public function getEventTypePrimary(): ?string { return $this->eventTypePrimary; }
    public function setEventTypePrimary(?string $eventTypePrimary): self { $this->eventTypePrimary = $eventTypePrimary; return $this; }

    public function getEventModality(): ?string { return $this->eventModality; }
    public function setEventModality(?string $eventModality): self { $this->eventModality = $eventModality; return $this; }

    public function getEventAttendeesCount(): ?int { return $this->eventAttendeesCount; }
    public function setEventAttendeesCount(?int $eventAttendeesCount): self { $this->eventAttendeesCount = $eventAttendeesCount; return $this; }

    public function getMainLocation(): ?string { return $this->mainLocation; }
    public function setMainLocation(?string $mainLocation): self { $this->mainLocation = $mainLocation !== null ? trim($mainLocation) : null; return $this; }

    public function getEventOnlineConnections(): ?int { return $this->eventOnlineConnections; }
    public function setEventOnlineConnections(?int $eventOnlineConnections): self { $this->eventOnlineConnections = $eventOnlineConnections; return $this; }

    public function getEcoManagerStatus(): ?string { return $this->ecoManagerStatus; }
    public function setEcoManagerStatus(?string $ecoManagerStatus): self { $this->ecoManagerStatus = $ecoManagerStatus; return $this; }

    public function getPresupuesto(): ?string { return $this->presupuesto; }
    public function setPresupuesto(?string $presupuesto): self { $this->presupuesto = $presupuesto !== null ? str_replace(',', '.', trim($presupuesto)) : null; return $this; }

    public function getDistributionMedia(): array { return $this->distributionMedia ?? []; }
    public function setDistributionMedia(array $distributionMedia): self
    {
        $this->distributionMedia = array_values(array_filter(
            array_map('strval', $distributionMedia),
            static fn (string $value): bool => ProjectCatalog::isDistributionMedia($value)
        ));

        return $this;
    }

    public function getEpisodios(): ?int { return $this->episodios; }
    public function setEpisodios(?int $episodios): self { $this->episodios = $episodios; return $this; }

    public function getDuracionEpisodio(): ?int { return $this->duracionEpisodio; }
    public function setDuracionEpisodio(?int $duracionEpisodio): self { $this->duracionEpisodio = $duracionEpisodio; return $this; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function normalizeState(): void
    {
        $this->mainLocation = $this->normalizeNullableString($this->mainLocation);
        $this->ecoManagerStatus = $this->normalizeNullableString($this->ecoManagerStatus);
        $this->presupuesto = $this->normalizeNullableString($this->presupuesto);

        if ($this->type !== 'rodaje') {
            $this->filmingType = null;
            $this->filmingGenre = null;
            $this->distributionMedia = [];
            $this->episodios = null;
            $this->duracionEpisodio = null;
        } else {
            $this->distributionMedia = array_values(array_filter(
                $this->distributionMedia ?? [],
                static fn (string $value): bool => ProjectCatalog::isDistributionMedia($value)
            ));

            if (!in_array($this->filmingType, ['tv_series', 'tv_program'], true)) {
                $this->episodios = null;
                $this->duracionEpisodio = null;
            }
        }

        if ($this->type !== 'evento') {
            $this->eventTypePrimary = null;
            $this->eventModality = null;
            $this->eventAttendeesCount = null;
            $this->eventOnlineConnections = null;
        } else {
            if ($this->eventModality === 'presencial') {
                $this->eventOnlineConnections = null;
            } elseif ($this->eventModality === 'virtual') {
                $this->eventAttendeesCount = null;
            } elseif ($this->eventModality !== 'hibrido') {
                $this->eventAttendeesCount = null;
                $this->eventOnlineConnections = null;
            }
        }

        $this->syncCollectionPositions($this->projectCompanies);
        $this->syncCollectionPositions($this->projectFundingSources);
    }

    #[Assert\Callback]
    public function validateConditionalFields(ExecutionContextInterface $context): void
    {
        if ($this->type === 'rodaje') {
            if ($this->filmingType === null || $this->filmingType === '') {
                $context->buildViolation('backend.projects.form.validation.filming_type_required')
                    ->atPath('filmingType')
                    ->addViolation();
            }

            if ($this->distributionMedia === [] || $this->distributionMedia === null) {
                $context->buildViolation('backend.projects.form.validation.distribution_media_required')
                    ->atPath('distributionMedia')
                    ->addViolation();
            }

            if (in_array($this->filmingType, ['tv_series', 'tv_program'], true)) {
                if ($this->episodios === null || $this->episodios < 1) {
                    $context->buildViolation('backend.projects.form.validation.episodes_required')
                        ->atPath('episodios')
                        ->addViolation();
                }

                if ($this->duracionEpisodio === null || $this->duracionEpisodio < 1) {
                    $context->buildViolation('backend.projects.form.validation.episode_duration_required')
                        ->atPath('duracionEpisodio')
                        ->addViolation();
                }
            }
        }

        if ($this->type === 'evento') {
            if ($this->eventTypePrimary === null || $this->eventTypePrimary === '') {
                $context->buildViolation('backend.projects.form.validation.event_type_primary_required')
                    ->atPath('eventTypePrimary')
                    ->addViolation();
            }

            if ($this->eventModality === null || $this->eventModality === '') {
                $context->buildViolation('backend.projects.form.validation.event_modality_required')
                    ->atPath('eventModality')
                    ->addViolation();
                return;
            }

            if ($this->eventModality === 'presencial' && $this->eventAttendeesCount === null) {
                $context->buildViolation('backend.projects.form.validation.attendees_required')
                    ->atPath('eventAttendeesCount')
                    ->addViolation();
            }

            if ($this->eventModality === 'virtual' && $this->eventOnlineConnections === null) {
                $context->buildViolation('backend.projects.form.validation.online_connections_required')
                    ->atPath('eventOnlineConnections')
                    ->addViolation();
            }

            if ($this->eventModality === 'hibrido') {
                if ($this->eventAttendeesCount === null) {
                    $context->buildViolation('backend.projects.form.validation.attendees_required')
                        ->atPath('eventAttendeesCount')
                        ->addViolation();
                }

                if ($this->eventOnlineConnections === null) {
                    $context->buildViolation('backend.projects.form.validation.online_connections_required')
                        ->atPath('eventOnlineConnections')
                        ->addViolation();
                }
            }
        }

        $this->validateFundingSources($context);
    }

    public function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function syncCollectionPositions(Collection $collection): void
    {
        $position = 1;
        foreach ($collection as $item) {
            if (method_exists($item, 'setPosition')) {
                $item->setPosition($position);
            }
            $position++;
        }
    }

    private function validateFundingSources(ExecutionContextInterface $context): void
    {
        $sources = $this->projectFundingSources->toArray();
        if ($sources === []) {
            return;
        }

        $total = 0;
        foreach ($sources as $index => $source) {
            if (!$source instanceof ProjectFundingSource) {
                continue;
            }

            $hundredths = $source->getPercentageHundredths();
            if ($hundredths === null) {
                continue;
            }

            $total += $hundredths;
        }

        if ($total !== 10000) {
            $formatted = number_format($total / 100, 2, ',', '.');
            $context->buildViolation('backend.projects.form.validation.funding_total_invalid')
                ->atPath('projectFundingSources')
                ->setParameter('{{ total }}', $formatted)
                ->addViolation();
        }
    }
}
