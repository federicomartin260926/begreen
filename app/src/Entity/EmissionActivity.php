<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\EmissionActivityRepository;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: EmissionActivityRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\TranslationEntity(class: \Gedmo\Translatable\Entity\Translation::class)]
class EmissionActivity
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 255)]
    private string $name;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 100)]
    private string $unit; // Ej: "km", "litros", "kWh"

    #[ORM\Column(type: 'float')]
    private float $emissionFactor; // kg CO₂e por unidad

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $calculationCode = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Category $category = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $subcategory = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?CategoryGhg $categoryGhg = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?EmissionSource $emissionSource = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): self
    {
        $this->unit = $unit;
        return $this;
    }

    public function getEmissionFactor(): float
    {
        return $this->emissionFactor;
    }

    public function setEmissionFactor(float $emissionFactor): self
    {
        $this->emissionFactor = $emissionFactor;
        return $this;
    }

    public function getCalculationCode(): ?string
    {
        return $this->calculationCode;
    }

    public function setCalculationCode(?string $calculationCode): self
    {
        $this->calculationCode = $calculationCode;
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

    public function getSubcategory(): ?string
    {
        return $this->subcategory;
    }

    public function setSubcategory(?string $subcategory): self
    {
        $this->subcategory = $subcategory;
        return $this;
    }

    public function getCategoryGhg(): ?CategoryGhg
    {
        return $this->categoryGhg;
    }

    public function setCategoryGhg(?CategoryGhg $categoryGhg): self
    {
        $this->categoryGhg = $categoryGhg;
        return $this;
    }

    public function getEmissionSource(): ?EmissionSource
    {
        return $this->emissionSource;
    }

    public function setEmissionSource(?EmissionSource $emissionSource): self
    {
        $this->emissionSource = $emissionSource;
        return $this;
    }

    public function __toString(): string
    {
        return $this->name . ' (' . $this->unit . ')';
    }
}
