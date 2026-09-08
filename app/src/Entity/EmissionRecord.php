<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class EmissionRecord
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING_DATA = 'PENDING_DATA';
    public const STATUS_CALCULATED = 'CALCULATED';
    public const STATUS_NOT_AUTOMATICALLY_CALCULABLE = 'NOT_AUTOMATICALLY_CALCULABLE';
    public const STATUS_CLOSED = 'CLOSED';

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

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Category $category = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $amount = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $emission = null;

    #[ORM\Column(length: 40, options: ['default' => self::STATUS_CALCULATED])]
    private string $status = self::STATUS_CALCULATED;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $calculationDetails = null;

    /** @var Collection<int, EmissionRecordAttachment> */
    // Intentionally no cascade persist: duplicating a record must never copy its attachments.
    #[ORM\OneToMany(mappedBy: 'emissionRecord', targetEntity: EmissionRecordAttachment::class)]
    private Collection $attachments;

    private ?string $subCategory = null;


    public function __construct()
    {
        $this->attachments = new ArrayCollection();
    }

    /** @return Collection<int, EmissionRecordAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(EmissionRecordAttachment $attachment): self
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setEmissionRecord($this);
        }

        return $this;
    }

    public function removeAttachment(EmissionRecordAttachment $attachment): self
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

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

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getEffectiveCategory(): ?Category
    {
        return $this->category;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(?float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getEmission(): ?float
    {
        return $this->emission;
    }

    public function setEmission(?float $emission): self
    {
        $this->emission = $emission;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING_DATA,
            self::STATUS_CALCULATED,
            self::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
            self::STATUS_CLOSED,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported emission record status "%s".', $status));
        }

        $this->status = $status;

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
}
