<?php

namespace App\Service\Animation;

final readonly class AnimationMeasure
{
    /**
     * @param array<string, bool> $planCompatibility
     * @param array<string, bool> $techniqueCompatibility
     * @param array<string, bool> $structureCompatibility
     * @param array<string, bool> $processingLevelCompatibility
     * @param array<string, bool> $processingInfrastructureCompatibility
     * @param array<string, bool> $shootingCompatibility
     * @param array<string, bool> $aiCompatibility
     * @param array<string, bool> $interactiveCompatibility
     */
    public function __construct(
        public string $id,
        public bool $active,
        public int $visualOrder,
        public array $planCompatibility,
        public bool $forceIfTechniqueMatches,
        public bool $forceIfShooting,
        public bool $notApplicableAllowed,
        public ?string $operationalCondition,
        public array $techniqueCompatibility,
        public array $structureCompatibility,
        public array $processingLevelCompatibility,
        public array $processingInfrastructureCompatibility,
        public array $shootingCompatibility,
        public array $aiCompatibility,
        public array $interactiveCompatibility,
    ) {
    }
}
