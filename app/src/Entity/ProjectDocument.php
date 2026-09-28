<?php

namespace App\Entity;

use App\Enum\ProjectDocumentCatalog;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class ProjectDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'projectDocuments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ProjectDocumentCatalog::class, 'allTypeCodes'])]
    private string $type = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $otherType = null;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: [
        ProjectDocumentCatalog::KIND_FILE,
        ProjectDocumentCatalog::KIND_LINK,
    ])]
    private string $kind = ProjectDocumentCatalog::KIND_FILE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $storedName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $sizeBytes = null;

    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'])]
    private ?string $url = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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
        $this->type = trim($type);

        return $this;
    }

    public function getOtherType(): ?string
    {
        return $this->otherType;
    }

    public function setOtherType(?string $otherType): self
    {
        $otherType = null === $otherType ? null : trim($otherType);
        $this->otherType = '' === $otherType ? null : $otherType;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function isFile(): bool
    {
        return ProjectDocumentCatalog::KIND_FILE === $this->kind;
    }

    public function isLink(): bool
    {
        return ProjectDocumentCatalog::KIND_LINK === $this->kind;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): self
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getStoredName(): ?string
    {
        return $this->storedName;
    }

    public function setStoredName(?string $storedName): self
    {
        $this->storedName = $storedName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(?int $sizeBytes): self
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $url = null === $url ? null : trim($url);
        $this->url = '' === $url ? null : $url;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function clearFileMetadata(): self
    {
        $this->originalName = null;
        $this->storedName = null;
        $this->mimeType = null;
        $this->sizeBytes = null;

        return $this;
    }
}
