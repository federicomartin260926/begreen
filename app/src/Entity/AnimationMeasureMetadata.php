<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Service\Animation\AnimationMeasure;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'animation_measure_metadata')]
class AnimationMeasureMetadata
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'animationMetadata')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Measure $measure = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'boolean')]
    private bool $forceIfTechniqueMatches = false;

    #[ORM\Column(type: 'boolean')]
    private bool $forceIfShooting = false;

    #[ORM\Column(type: 'boolean')]
    private bool $notApplicableAllowed = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $operationalCondition = null;

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $planCompatibility = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $techniqueCompatibility = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $structureCompatibility = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $processingLevelCompatibility = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $processingInfrastructureCompatibility = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $shootingCompatibility = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $aiCompatibility = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $interactiveCompatibility = [];

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $expectedImpact = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $effortCost = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $complexity = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMeasure(): ?Measure
    {
        return $this->measure;
    }

    public function setMeasure(?Measure $measure): self
    {
        if ($this->measure === $measure) {
            return $this;
        }

        $previous = $this->measure;
        $this->measure = $measure;

        if (null !== $previous && $previous->getAnimationMetadata() === $this) {
            $previous->setAnimationMetadata(null);
        }

        if (null !== $measure && $measure->getAnimationMetadata() !== $this) {
            $measure->setAnimationMetadata($this);
        }

        return $this;
    }

    public function syncFrom(
        AnimationMeasure $source,
        ?string $expectedImpact,
        ?string $effortCost,
        ?string $complexity,
    ): self {
        $this->active = $source->active;
        $this->forceIfTechniqueMatches = $source->forceIfTechniqueMatches;
        $this->forceIfShooting = $source->forceIfShooting;
        $this->notApplicableAllowed = $source->notApplicableAllowed;
        $this->operationalCondition = $source->operationalCondition;
        $this->planCompatibility = $source->planCompatibility;
        $this->techniqueCompatibility = $source->techniqueCompatibility;
        $this->structureCompatibility = $source->structureCompatibility;
        $this->processingLevelCompatibility = $source->processingLevelCompatibility;
        $this->processingInfrastructureCompatibility = $source->processingInfrastructureCompatibility;
        $this->shootingCompatibility = $source->shootingCompatibility;
        $this->aiCompatibility = $source->aiCompatibility;
        $this->interactiveCompatibility = $source->interactiveCompatibility;
        $this->expectedImpact = $expectedImpact;
        $this->effortCost = $effortCost;
        $this->complexity = $complexity;

        return $this;
    }

    public function toAnimationMeasure(): AnimationMeasure
    {
        $catalogId = $this->measure?->getCatalogId();
        if (null === $catalogId || '' === $catalogId) {
            throw new LogicException('La metadata Animation requiere una medida con catalogId.');
        }

        return new AnimationMeasure(
            id: $catalogId,
            active: $this->active,
            visualOrder: $this->measure->getSortOrder(),
            planCompatibility: $this->planCompatibility,
            forceIfTechniqueMatches: $this->forceIfTechniqueMatches,
            forceIfShooting: $this->forceIfShooting,
            notApplicableAllowed: $this->notApplicableAllowed,
            operationalCondition: $this->operationalCondition,
            techniqueCompatibility: $this->techniqueCompatibility,
            structureCompatibility: $this->structureCompatibility,
            processingLevelCompatibility: $this->processingLevelCompatibility,
            processingInfrastructureCompatibility: $this->processingInfrastructureCompatibility,
            shootingCompatibility: $this->shootingCompatibility,
            aiCompatibility: $this->aiCompatibility,
            interactiveCompatibility: $this->interactiveCompatibility,
        );
    }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function isForceIfTechniqueMatches(): bool { return $this->forceIfTechniqueMatches; }
    public function isForceIfShooting(): bool { return $this->forceIfShooting; }
    public function isNotApplicableAllowed(): bool { return $this->notApplicableAllowed; }
    public function getOperationalCondition(): ?string { return $this->operationalCondition; }
    /** @return array<string, bool> */
    public function getPlanCompatibility(): array { return $this->planCompatibility; }
    /** @return array<string, bool> */
    public function getTechniqueCompatibility(): array { return $this->techniqueCompatibility; }
    /** @return array<string, bool> */
    public function getStructureCompatibility(): array { return $this->structureCompatibility; }
    /** @return array<string, bool> */
    public function getProcessingLevelCompatibility(): array { return $this->processingLevelCompatibility; }
    /** @return array<string, bool> */
    public function getProcessingInfrastructureCompatibility(): array { return $this->processingInfrastructureCompatibility; }
    /** @return array<string, bool> */
    public function getShootingCompatibility(): array { return $this->shootingCompatibility; }
    /** @return array<string, bool> */
    public function getAiCompatibility(): array { return $this->aiCompatibility; }
    /** @return array<string, bool> */
    public function getInteractiveCompatibility(): array { return $this->interactiveCompatibility; }
    public function getExpectedImpact(): ?string { return $this->expectedImpact; }
    public function getEffortCost(): ?string { return $this->effortCost; }
    public function getComplexity(): ?string { return $this->complexity; }
}
