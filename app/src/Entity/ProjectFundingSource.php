<?php

namespace App\Entity;

use App\Enum\ProjectCatalog;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Context\ExecutionContext;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class ProjectFundingSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'projectFundingSources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ProjectCatalog::PROJECT_FUNDING_SOURCE_TYPES)]
    private string $type = 'production_company';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Assert\NotBlank]
    private ?string $percentage = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getPercentage(): ?string
    {
        return $this->percentage;
    }

    public function setPercentage(?string $percentage): self
    {
        $this->percentage = $percentage === null ? null : str_replace(',', '.', trim($percentage));

        return $this;
    }

    public function getPercentageHundredths(): ?int
    {
        if ($this->percentage === null || $this->percentage === '') {
            return null;
        }

        return self::decimalToHundredths($this->percentage);
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = $position;

        return $this;
    }

    #[Assert\Callback]
    public function validatePercentage(ExecutionContext $context): void
    {
        $value = $this->percentage;
        if ($value === null || $value === '') {
            return;
        }

        $minorUnits = self::decimalToHundredths($value);
        if ($minorUnits === null) {
            $context->buildViolation('backend.projects.form.validation.percentage_invalid')
                ->atPath('percentage')
                ->addViolation();

            return;
        }

        if ($minorUnits <= 0) {
            $context->buildViolation('backend.projects.form.validation.percentage_gt_zero')
                ->atPath('percentage')
                ->addViolation();
        }

        if ($minorUnits > 10000) {
            $context->buildViolation('backend.projects.form.validation.percentage_lte_hundred')
                ->atPath('percentage')
                ->addViolation();
        }
    }

    private static function decimalToHundredths(string $value): ?int
    {
        $normalized = str_replace(',', '.', trim($value));
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        [$integer, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $decimal = str_pad($decimal, 2, '0');

        return ((int) $integer * 100) + (int) substr($decimal, 0, 2);
    }
}
