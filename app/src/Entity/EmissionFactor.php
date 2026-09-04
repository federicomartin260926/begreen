<?php

namespace App\Entity;

use App\Repository\EmissionFactorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: EmissionFactorRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_emission_factor_category_functional_year',
    columns: ['category_key', 'functional_key', 'year'],
)]
#[UniqueEntity(fields: ['categoryKey', 'functionalKey', 'year'])]
class EmissionFactor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $categoryKey;

    #[ORM\Column(length: 64)]
    private string $functionalKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $criteria = [];

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column(type: 'decimal', precision: 24, scale: 18, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 100)]
    private string $unit;

    #[ORM\Column(length: 100)]
    private string $source;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceDetail = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategoryKey(): string
    {
        return $this->categoryKey;
    }

    public function setCategoryKey(string $categoryKey): self
    {
        $this->categoryKey = $categoryKey;

        return $this;
    }

    public function getFunctionalKey(): string
    {
        return $this->functionalKey;
    }

    public function setFunctionalKey(string $functionalKey): self
    {
        $this->functionalKey = $functionalKey;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /** @param array<string, mixed> $criteria */
    public function setCriteria(array $criteria): self
    {
        $this->criteria = $criteria;

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): self
    {
        $this->year = $year;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getSourceDetail(): ?string
    {
        return $this->sourceDetail;
    }

    public function setSourceDetail(?string $sourceDetail): self
    {
        $this->sourceDetail = $sourceDetail;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }
}
