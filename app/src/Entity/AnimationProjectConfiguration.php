<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Service\Animation\AnimationConfigurationCatalog;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'animation_project_configuration')]
class AnimationProjectConfiguration
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'animationConfiguration')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $techniques = [];

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $structure = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $shootingAnswered = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $processingLevel = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $processingInfrastructures = [];

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $usesAi = null;

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
        if ($this->project === $project) {
            return $this;
        }

        $previous = $this->project;
        $this->project = $project;

        if (null !== $previous && $previous->getAnimationConfiguration() === $this) {
            $previous->setAnimationConfiguration(null);
        }

        if (null !== $project && $project->getAnimationConfiguration() !== $this) {
            $project->setAnimationConfiguration($this);
        }

        return $this;
    }

    /** @return list<string> */
    public function getTechniques(): array
    {
        return $this->techniques;
    }

    /** @param list<mixed> $techniques */
    public function setTechniques(array $techniques): self
    {
        $wasForced = $this->forcesShooting($this->techniques);
        $this->techniques = $this->normalizeCodes(
            $techniques,
            array_values(AnimationConfigurationCatalog::TECHNIQUES),
            'técnica',
        );

        if ($wasForced || $this->forcesShooting($this->techniques)) {
            $this->shootingAnswered = null;
        }

        return $this;
    }

    public function getStructure(): ?string
    {
        return $this->structure;
    }

    public function setStructure(?string $structure): self
    {
        $this->assertNullableCode($structure, array_values(AnimationConfigurationCatalog::STRUCTURES), 'estructura');
        $this->structure = $structure;

        return $this;
    }

    public function getShootingAnswered(): ?bool
    {
        return $this->shootingAnswered;
    }

    public function setShootingAnswered(?bool $shootingAnswered): self
    {
        $this->shootingAnswered = $this->forcesShooting($this->techniques) ? null : $shootingAnswered;

        return $this;
    }

    public function getProcessingLevel(): ?string
    {
        return $this->processingLevel;
    }

    public function setProcessingLevel(?string $processingLevel): self
    {
        $this->assertNullableCode(
            $processingLevel,
            array_values(AnimationConfigurationCatalog::PROCESSING_LEVELS),
            'nivel de procesamiento',
        );
        $this->processingLevel = $processingLevel;

        return $this;
    }

    /** @return list<string> */
    public function getProcessingInfrastructures(): array
    {
        return $this->processingInfrastructures;
    }

    /** @param list<mixed> $processingInfrastructures */
    public function setProcessingInfrastructures(array $processingInfrastructures): self
    {
        $this->processingInfrastructures = $this->normalizeCodes(
            $processingInfrastructures,
            array_values(AnimationConfigurationCatalog::PROCESSING_INFRASTRUCTURES),
            'infraestructura de procesamiento',
        );

        return $this;
    }

    public function getUsesAi(): ?bool
    {
        return $this->usesAi;
    }

    public function setUsesAi(?bool $usesAi): self
    {
        $this->usesAi = $usesAi;

        return $this;
    }

    /** @param list<mixed> $codes @param list<string> $knownCodes @return list<string> */
    private function normalizeCodes(array $codes, array $knownCodes, string $field): array
    {
        $selected = [];
        foreach ($codes as $code) {
            if (!is_string($code) || !in_array($code, $knownCodes, true)) {
                throw new InvalidArgumentException(sprintf('Código desconocido de %s.', $field));
            }
            $selected[$code] = true;
        }

        return array_values(array_filter(
            $knownCodes,
            static fn (string $knownCode): bool => isset($selected[$knownCode]),
        ));
    }

    /** @param list<string> $knownCodes */
    private function assertNullableCode(?string $code, array $knownCodes, string $field): void
    {
        if (null !== $code && !in_array($code, $knownCodes, true)) {
            throw new InvalidArgumentException(sprintf('Código desconocido de %s: "%s".', $field, $code));
        }
    }

    /** @param list<string> $techniques */
    private function forcesShooting(array $techniques): bool
    {
        return [] !== array_intersect($techniques, AnimationConfigurationCatalog::SHOOTING_FORCED_TECHNIQUES);
    }
}
