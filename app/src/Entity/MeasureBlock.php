<?php

namespace App\Entity;

use App\Repository\MeasureBlockRepository;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MeasureBlockRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(
    name: 'measure_block',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_measure_block_protocol_code', columns: ['protocol_id', 'code']),
    ]
)]
class MeasureBlock
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Protocol $protocol = null;

    #[ORM\OneToMany(mappedBy: 'measureBlock', targetEntity: Measure::class)]
    private Collection $measures;

    #[ORM\Column(length: 120)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hasScreeningQuestion = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $screeningQuestion = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    public function __construct()
    {
        $this->measures = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProtocol(): ?Protocol
    {
        return $this->protocol;
    }

    public function setProtocol(?Protocol $protocol): self
    {
        $this->protocol = $protocol;
        return $this;
    }

    /** @return Collection<int, Measure> */
    public function getMeasures(): Collection
    {
        return $this->measures;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): self
    {
        $this->sortOrder = $sortOrder ?? 0;
        return $this;
    }

    public function hasScreeningQuestion(): bool
    {
        return $this->hasScreeningQuestion;
    }

    public function setHasScreeningQuestion(bool $hasScreeningQuestion): self
    {
        $this->hasScreeningQuestion = $hasScreeningQuestion;
        return $this;
    }

    public function getScreeningQuestion(): ?string
    {
        return $this->screeningQuestion;
    }

    public function setScreeningQuestion(?string $screeningQuestion): self
    {
        $this->screeningQuestion = $screeningQuestion;
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

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
