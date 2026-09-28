<?php

namespace App\Service\Animation;

use App\Entity\AnimationProjectConfiguration;
use App\Entity\Project;
use App\Enum\ProjectCatalog;

final class AnimationProjectConfigurationUpdater
{
    public function copyProjectConfiguration(Project $source, Project $target): bool
    {
        if ('rodaje' !== $source->getType()
            || ProjectCatalog::FILMING_GENRE_ANIMATION !== $source->getFilmingGenre()) {
            return true;
        }

        $sourceConfiguration = $source->getAnimationConfiguration();
        if (null === $sourceConfiguration) {
            return false;
        }

        $this->updateProject(
            $target,
            $sourceConfiguration->getTechniques(),
            $sourceConfiguration->getStructure(),
            $sourceConfiguration->getShootingAnswered(),
            $sourceConfiguration->getProcessingLevel(),
            $sourceConfiguration->getProcessingInfrastructures(),
            $sourceConfiguration->getUsesAi(),
        );

        return true;
    }

    /**
     * @param list<string> $techniques
     * @param list<string> $processingInfrastructures
     */
    public function updateProject(
        Project $project,
        array $techniques,
        ?string $structure,
        ?bool $shootingAnswered,
        ?string $processingLevel,
        array $processingInfrastructures,
        ?bool $usesAi,
    ): ?AnimationProjectConfiguration {
        if ('rodaje' !== $project->getType()
            || ProjectCatalog::FILMING_GENRE_ANIMATION !== $project->getFilmingGenre()) {
            return $project->getAnimationConfiguration();
        }

        $configuration = $project->getAnimationConfiguration()
            ?? (new AnimationProjectConfiguration())->setProject($project);

        return $this->update(
            $configuration,
            $techniques,
            $structure,
            $shootingAnswered,
            $processingLevel,
            $processingInfrastructures,
            $usesAi,
        );
    }

    /**
     * Applies one wizard transition. Partial configuration is valid.
     *
     * @param list<string> $techniques
     * @param list<string> $processingInfrastructures
     */
    public function update(
        AnimationProjectConfiguration $configuration,
        array $techniques,
        ?string $structure,
        ?bool $shootingAnswered,
        ?string $processingLevel,
        array $processingInfrastructures,
        ?bool $usesAi,
    ): AnimationProjectConfiguration {
        $normalized = new AnimationConfiguration(
            techniques: $techniques,
            structure: $structure,
            shootingAnswered: $shootingAnswered,
            processingInfrastructures: $processingInfrastructures,
            processingLevel: $processingLevel,
            usesAi: $usesAi,
            distribution: null,
            plan: null,
        );

        $configuration
            ->setTechniques($normalized->techniques())
            ->setStructure($normalized->structure())
            ->setProcessingLevel($normalized->processingLevel())
            ->setProcessingInfrastructures($normalized->processingInfrastructures())
            ->setUsesAi($normalized->usesAi());

        $isForced = $normalized->shootingIsForced();
        $configuration->setShootingAnswered($isForced ? null : $shootingAnswered);

        return $configuration;
    }
}
