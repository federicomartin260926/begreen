<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'final_report_content')]
class FinalReportContent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /**
     * Selected PlanMeasure IDs and optional editorial descriptions.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $featuredMeasures = [];

    /**
     * Editorial references to existing project image documents.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $photos = [];

    #[ORM\Column(nullable: true)]
    private ?int $coverDocumentId = null;

    /**
     * Section text and editorial origin (manual / ai).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $manualTexts = [];

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

    public function getFeaturedMeasures(): array
    {
        return $this->featuredMeasures;
    }

    public function setFeaturedMeasures(array $featuredMeasures): self
    {
        $this->featuredMeasures = $featuredMeasures;

        return $this;
    }

    public function getPhotos(): array
    {
        return $this->photos;
    }

    public function setPhotos(array $photos): self
    {
        $this->photos = $photos;

        return $this;
    }

    public function getCoverDocumentId(): ?int
    {
        return $this->coverDocumentId;
    }

    public function setCoverDocumentId(?int $coverDocumentId): self
    {
        $this->coverDocumentId = $coverDocumentId;

        return $this;
    }

    public function getManualTexts(): array
    {
        return $this->manualTexts;
    }

    public function setManualTexts(array $manualTexts): self
    {
        $this->manualTexts = $manualTexts;

        return $this;
    }
}
