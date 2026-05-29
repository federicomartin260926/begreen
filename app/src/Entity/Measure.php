<?php

namespace App\Entity;

use App\Repository\MeasureRepository;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
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

    #[ORM\ManyToMany(targetEntity: Department::class)]
    #[ORM\JoinTable(name: 'measure_departments')]
    private Collection $departments;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Protocol $protocol = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ods $ods = null;

    #[ORM\ManyToMany(targetEntity: Ods::class)]
    #[ORM\JoinTable(name: 'measure_ods_items')]
    private Collection $odsItems;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?EsG $esg = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Scope $scope = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?CategoryGhg $categoryGhg = null;

    #[ORM\ManyToOne(targetEntity: MeasureBlock::class, inversedBy: 'measures')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MeasureBlock $measureBlock = null;

    #[ORM\ManyToMany(targetEntity: ImpactArea::class)]
    #[ORM\JoinTable(name: 'measure_impact_areas')]
    private Collection $impactAreas;

    #[ORM\ManyToMany(targetEntity: TripleBalanceAxis::class)]
    #[ORM\JoinTable(name: 'measure_triple_balance_axes')]
    private Collection $tripleBalanceAxes;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $implementation = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 300, nullable: true)]
    private ?string $verificationSources = null;

    #[ORM\OneToMany(mappedBy: "measure", targetEntity: PlanMeasure::class)]
    private Collection $planMeasures;

    #[ORM\OneToMany(mappedBy: "measure", targetEntity: MeasureVerificationSource::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $verificationSourceLinks;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $mandatory = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'La puntuación debe estar entre {{ min }} y {{ max }}.')]
    private ?int $score = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sourceRow = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $importHash = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $importVersion = null;

    public function __construct()
    {
        $this->departments = new ArrayCollection();
        $this->odsItems = new ArrayCollection();
        $this->impactAreas = new ArrayCollection();
        $this->tripleBalanceAxes = new ArrayCollection();
        $this->planMeasures = new ArrayCollection();
        $this->verificationSourceLinks = new ArrayCollection();
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

    /** @return Collection<int, Department> */
    public function getDepartments(): Collection
    {
        return $this->departments;
    }

    public function addDepartment(Department $department): self
    {
        if (!$this->departments->contains($department)) {
            $this->departments->add($department);
        }
        return $this;
    }

    public function removeDepartment(Department $department): self
    {
        $this->departments->removeElement($department);
        return $this;
    }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    public function getProtocol(): ?Protocol { return $this->protocol; }
    public function setProtocol(?Protocol $protocol): static { $this->protocol = $protocol; return $this; }

    public function getOds(): ?Ods { return $this->ods; }
    public function setOds(?Ods $ods): static { $this->ods = $ods; return $this; }

    /** @return Collection<int, Ods> */
    public function getOdsItems(): Collection
    {
        return $this->odsItems;
    }

    public function addOdsItem(Ods $ods): self
    {
        if (!$this->odsItems->contains($ods)) {
            $this->odsItems->add($ods);
        }
        return $this;
    }

    public function removeOdsItem(Ods $ods): self
    {
        $this->odsItems->removeElement($ods);
        return $this;
    }

    public function getEsg(): ?EsG { return $this->esg; }
    public function setEsg(?EsG $esg): static { $this->esg = $esg; return $this; }

    public function getScope(): ?Scope { return $this->scope; }
    public function setScope(?Scope $scope): static { $this->scope = $scope; return $this; }

    public function getCategoryGhg(): ?CategoryGhg { return $this->categoryGhg; }
    public function setCategoryGhg(?CategoryGhg $categoryGhg): static { $this->categoryGhg = $categoryGhg; return $this; }

    public function getMeasureBlock(): ?MeasureBlock
    {
        return $this->measureBlock;
    }

    public function setMeasureBlock(?MeasureBlock $measureBlock): self
    {
        $this->measureBlock = $measureBlock;
        return $this;
    }

    /** @return Collection<int, ImpactArea> */
    public function getImpactAreas(): Collection
    {
        return $this->impactAreas;
    }

    public function addImpactArea(ImpactArea $impactArea): self
    {
        if (!$this->impactAreas->contains($impactArea)) {
            $this->impactAreas->add($impactArea);
        }
        return $this;
    }

    public function removeImpactArea(ImpactArea $impactArea): self
    {
        $this->impactAreas->removeElement($impactArea);
        return $this;
    }

    /** @return Collection<int, TripleBalanceAxis> */
    public function getTripleBalanceAxes(): Collection
    {
        return $this->tripleBalanceAxes;
    }

    public function addTripleBalanceAxis(TripleBalanceAxis $axis): self
    {
        if (!$this->tripleBalanceAxes->contains($axis)) {
            $this->tripleBalanceAxes->add($axis);
        }
        return $this;
    }

    public function removeTripleBalanceAxis(TripleBalanceAxis $axis): self
    {
        $this->tripleBalanceAxes->removeElement($axis);
        return $this;
    }

    public function getImplementation(): ?string { return $this->implementation; }

    public function setImplementation(?string $implementation): static { $this->implementation = $implementation; return $this; }

    public function getVerificationSources(): ?string { return $this->verificationSources; }

    public function setVerificationSources(?string $verificationSources): static { $this->verificationSources = $verificationSources; return $this; }

    /** @return Department[] */
    public function getResolvedDepartments(): array
    {
        if (!$this->departments->isEmpty()) {
            return array_values($this->departments->toArray());
        }

        return $this->department ? [$this->department] : [];
    }

    /** @return Ods[] */
    public function getResolvedOdsItems(): array
    {
        if (!$this->odsItems->isEmpty()) {
            return array_values($this->odsItems->toArray());
        }

        return $this->ods ? [$this->ods] : [];
    }

    /** @return ImpactArea[] */
    public function getResolvedImpactAreas(): array
    {
        return array_values($this->impactAreas->toArray());
    }

    /** @return TripleBalanceAxis[] */
    public function getResolvedTripleBalanceAxes(): array
    {
        return array_values($this->tripleBalanceAxes->toArray());
    }

    /** @return MeasureVerificationSource[] */
    public function getResolvedVerificationSourceLinks(): array
    {
        $links = $this->verificationSourceLinks->toArray();
        usort($links, static fn (MeasureVerificationSource $left, MeasureVerificationSource $right): int => $left->getPriority() <=> $right->getPriority());

        return $links;
    }

    public function getPrimaryDepartment(): ?Department
    {
        return $this->getResolvedDepartments()[0] ?? null;
    }

    public function getPrimaryOds(): ?Ods
    {
        return $this->getResolvedOdsItems()[0] ?? null;
    }

    public function getVerificationSourcesSummary(): ?string
    {
        if ($this->verificationSourceLinks->isEmpty()) {
            return $this->verificationSources;
        }

        $parts = [];
        foreach ($this->getResolvedVerificationSourceLinks() as $link) {
            $source = $link->getVerificationSource();
            if (!$source) {
                continue;
            }

            $parts[] = sprintf('%d. %s', $link->getPriority(), $this->normalizeVerificationSourceName((string) $source->getName()));
        }

        return $parts !== [] ? implode(' | ', $parts) : ($this->verificationSources ?? null);
    }

    private function normalizeVerificationSourceName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (preg_match('/^\s*\d+\s*[\.\)\-:]\s*(.+)$/u', $name, $matches)) {
            return trim($matches[1]);
        }

        return $name;
    }

    /** @return Collection<int, MeasureVerificationSource> */
    public function getVerificationSourceLinks(): Collection
    {
        return $this->verificationSourceLinks;
    }

    public function addVerificationSourceLink(MeasureVerificationSource $link): self
    {
        if (!$this->verificationSourceLinks->contains($link)) {
            $this->verificationSourceLinks->add($link);
            $link->setMeasure($this);
        }
        return $this;
    }

    public function removeVerificationSourceLink(MeasureVerificationSource $link): self
    {
        if ($this->verificationSourceLinks->removeElement($link)) {
            if ($link->getMeasure() === $this) {
                $link->setMeasure(null);
            }
        }
        return $this;
    }

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

    public function getSourceRow(): ?int
    {
        return $this->sourceRow;
    }

    public function setSourceRow(?int $sourceRow): self
    {
        $this->sourceRow = $sourceRow;
        return $this;
    }

    public function getImportHash(): ?string
    {
        return $this->importHash;
    }

    public function setImportHash(?string $importHash): self
    {
        $this->importHash = $importHash;
        return $this;
    }

    public function getImportVersion(): ?string
    {
        return $this->importVersion;
    }

    public function setImportVersion(?string $importVersion): self
    {
        $this->importVersion = $importVersion;
        return $this;
    }

    #[Assert\Callback]
    public function validateMeasureBlockProtocol(ExecutionContextInterface $context): void
    {
        if ($this->measureBlock === null || $this->protocol === null) {
            return;
        }

        if ($this->measureBlock->getProtocol()?->getId() !== $this->protocol->getId()) {
            $context
                ->buildViolation('El bloque de la medida debe pertenecer al mismo protocolo.')
                ->atPath('measureBlock')
                ->addViolation();
        }
    }

}
