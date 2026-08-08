<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[Gedmo\TranslationEntity(class: \Gedmo\Translatable\Entity\Translation::class)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabledInEmissionCalculator = true;

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function isEnabledInEmissionCalculator(): bool
    {
        return $this->enabledInEmissionCalculator;
    }

    public function setEnabledInEmissionCalculator(bool $enabledInEmissionCalculator): static
    {
        $this->enabledInEmissionCalculator = $enabledInEmissionCalculator;
        return $this;
    }
}
