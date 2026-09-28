<?php

namespace App\Service\Animation;

use App\Entity\Project;
use LogicException;

final class AnimationConfigurationFactory
{
    public function fromProject(Project $project, string $commercialPlan): AnimationConfiguration
    {
        $persisted = $project->getAnimationConfiguration();
        if (null === $persisted) {
            throw new LogicException('El proyecto no tiene configuración Animation persistida.');
        }

        $distribution = $project->getDistributionMedia();

        return new AnimationConfiguration(
            techniques: $persisted->getTechniques(),
            structure: $persisted->getStructure(),
            shootingAnswered: $persisted->getShootingAnswered(),
            processingInfrastructures: $persisted->getProcessingInfrastructures(),
            processingLevel: $persisted->getProcessingLevel(),
            usesAi: $persisted->getUsesAi(),
            distribution: [] === $distribution ? null : $distribution,
            plan: $commercialPlan,
        );
    }
}
