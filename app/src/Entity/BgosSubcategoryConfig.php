<?php

namespace App\Entity;

use App\Repository\BgosSubcategoryConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BgosSubcategoryConfigRepository::class)]
#[ORM\Table(name: 'bgos_subcategory_config')]
#[ORM\UniqueConstraint(
    name: 'uniq_bgos_subcategory_project_category_key',
    columns: ['project_id', 'category_key', 'subcategory_key']
)]
#[UniqueEntity(
    fields: ['project', 'categoryKey', 'subcategoryKey'],
    errorPath: 'subcategoryKey',
    message: 'Ya existe esta subcategoría BGoS para el proyecto y la categoría.'
)]
class BgosSubcategoryConfig
{
    public const FREQUENCY_NOT_APPLICABLE = 'not_applicable';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_PUNCTUAL = 'punctual';

    public const FREQUENCIES = [
        self::FREQUENCY_NOT_APPLICABLE,
        self::FREQUENCY_DAILY,
        self::FREQUENCY_PUNCTUAL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Project $project = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $categoryKey = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/')]
    private string $subcategoryKey = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $label = '';

    #[ORM\Column(type: 'boolean')]
    private bool $active = false;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::FREQUENCIES)]
    private string $preproductionFrequency = self::FREQUENCY_NOT_APPLICABLE;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::FREQUENCIES)]
    private string $activityFrequency = self::FREQUENCY_NOT_APPLICABLE;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::FREQUENCIES)]
    private string $postproductionFrequency = self::FREQUENCY_NOT_APPLICABLE;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getCategoryKey(): string
    {
        return $this->categoryKey;
    }

    public function setCategoryKey(string $categoryKey): self
    {
        $this->categoryKey = trim($categoryKey);

        return $this;
    }

    public function getSubcategoryKey(): string
    {
        return $this->subcategoryKey;
    }

    public function setSubcategoryKey(string $subcategoryKey): self
    {
        $this->subcategoryKey = trim($subcategoryKey);

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = trim($label);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getPreproductionFrequency(): string
    {
        return $this->preproductionFrequency;
    }

    public function setPreproductionFrequency(string $preproductionFrequency): self
    {
        $this->preproductionFrequency = $this->validateFrequency($preproductionFrequency);

        return $this;
    }

    public function getActivityFrequency(): string
    {
        return $this->activityFrequency;
    }

    public function setActivityFrequency(string $activityFrequency): self
    {
        $this->activityFrequency = $this->validateFrequency($activityFrequency);

        return $this;
    }

    public function getPostproductionFrequency(): string
    {
        return $this->postproductionFrequency;
    }

    public function setPostproductionFrequency(string $postproductionFrequency): self
    {
        $this->postproductionFrequency = $this->validateFrequency($postproductionFrequency);

        return $this;
    }

    private function validateFrequency(string $frequency): string
    {
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported BGoS frequency "%s".',
                $frequency
            ));
        }

        return $frequency;
    }
}
