<?php

namespace App\Entity;

use App\Repository\MeasureRepository;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: MeasureRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(
    name: 'measure',
    indexes: [
        new ORM\Index(name: 'idx_measure_protocol',      columns: ['protocol_id']),
        new ORM\Index(name: 'idx_measure_category',      columns: ['category_id']),
        new ORM\Index(name: 'idx_measure_department',    columns: ['department_id']),
        new ORM\Index(name: 'idx_measure_category_ghg',  columns: ['category_ghg_id'])
    ]
)]
#[Gedmo\TranslationEntity(class: \Gedmo\Translatable\Entity\Translation::class)]
class Measure
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 1000)]
    private ?string $name = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $nameReview = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Department $department = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Protocol $protocol = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ods $ods = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?EsG $esg = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Scope $scope = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?CategoryGhg $categoryGhg = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $implementation = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 300, nullable: true)]
    private ?string $verificationSources = null;

    #[ORM\OneToMany(mappedBy: "measure", targetEntity: PlanMeasure::class)]
    private Collection $planMeasures;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $mandatory = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'La puntuación debe estar entre {{ min }} y {{ max }}.')]
    private ?int $score = null;

    public function __construct()
    {
        $this->planMeasures = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getNameReview(): ?string { return $this->nameReview; }
    public function setNameReview(?string $nameReview): static { $this->nameReview = $nameReview; return $this; }

    /**
     * Conveniencia: devuelve pasado si existe, si no el nombre original.
     */
    public function getDisplayNameForReview(): string
    {
        return $this->nameReview ?: ($this->name ?? '');
    }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(?Department $department): static { $this->department = $department; return $this; }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    public function getProtocol(): ?Protocol { return $this->protocol; }
    public function setProtocol(?Protocol $protocol): static { $this->protocol = $protocol; return $this; }

    public function getOds(): ?Ods { return $this->ods; }
    public function setOds(?Ods $ods): static { $this->ods = $ods; return $this; }

    public function getEsg(): ?EsG { return $this->esg; }
    public function setEsg(?EsG $esg): static { $this->esg = $esg; return $this; }

    public function getScope(): ?Scope { return $this->scope; }
    public function setScope(?Scope $scope): static { $this->scope = $scope; return $this; }

    public function getCategoryGhg(): ?CategoryGhg { return $this->categoryGhg; }
    public function setCategoryGhg(?CategoryGhg $categoryGhg): static { $this->categoryGhg = $categoryGhg; return $this; }

    public function getImplementation(): ?string { return $this->implementation; }

    public function setImplementation(?string $implementation): static { $this->implementation = $implementation; return $this; }

    public function getVerificationSources(): ?string { return $this->verificationSources; }

    public function setVerificationSources(?string $verificationSources): static { $this->verificationSources = $verificationSources; return $this; }

     /**
     * @return Collection|PlanMeasure[]
     */
    public function getPlanMeasures(): Collection
    {
        return $this->planMeasures;
    }

    public function addPlanMeasure(PlanMeasure $planMeasure): self
    {
        if (!$this->planMeasures->contains($planMeasure)) {
            $this->planMeasures[] = $planMeasure;
            $planMeasure->setMeasure($this);
        }
        return $this;
    }

    public function removePlanMeasure(PlanMeasure $planMeasure): self
    {
        if ($this->planMeasures->removeElement($planMeasure)) {
            // set the owning side to null (unless already changed)
            if ($planMeasure->getMeasure() === $this) {
                $planMeasure->setMeasure(null);
            }
        }
        return $this;
    }

    public function isMandatory(): bool { return $this->mandatory; }
    public function setMandatory(bool $mandatory): self { $this->mandatory = $mandatory; return $this; }

    public function getScore(): ?int { return $this->score; }
    public function setScore(?int $score): self { $this->score = $score; return $this; }

}
