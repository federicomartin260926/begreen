<?php

namespace App\Service\Animation;

use InvalidArgumentException;

final class AnimationMeasureSelector
{
    /**
     * @param list<AnimationMeasure> $dataset
     *
     * @return list<AnimationMeasure>
     */
    public function select(AnimationConfiguration $configuration, array $dataset): array
    {
        $configuration->assertComplete();
        $selected = [];

        foreach ($dataset as $measure) {
            if (!$measure instanceof AnimationMeasure) {
                throw new InvalidArgumentException('El dataset contiene un elemento que no es AnimationMeasure.');
            }
            if (!$measure->active || isset($selected[$measure->id])) {
                continue;
            }

            $techniqueMatches = $this->anyCompatible($configuration->techniques(), $measure->techniqueCompatibility);
            $compatible = $techniqueMatches
                && ($measure->structureCompatibility[$configuration->structure()] ?? false)
                && ($measure->shootingCompatibility[$configuration->effectiveShooting() ? 'RODAJE_SI' : 'RODAJE_NO'] ?? false)
                && $this->anyCompatible($configuration->processingInfrastructures(), $measure->processingInfrastructureCompatibility)
                && ($measure->processingLevelCompatibility[$configuration->processingLevel()] ?? false)
                && ($measure->aiCompatibility[$configuration->usesAi() ? 'IA_SI' : 'IA_NO'] ?? false)
                && ($measure->interactiveCompatibility[$configuration->isInteractive() ? 'INTERACTIVO_SI' : 'INTERACTIVO_NO'] ?? false);

            if (!$compatible) {
                continue;
            }

            $scoreMatches = $measure->planCompatibility[AnimationConfigurationCatalog::PLANS[$configuration->plan()]] ?? false;
            $techniqueException = $measure->forceIfTechniqueMatches && $techniqueMatches;
            $shootingException = $measure->forceIfShooting && true === $configuration->effectiveShooting();

            if ($scoreMatches || $techniqueException || $shootingException) {
                $selected[$measure->id] = $measure;
            }
        }

        $result = array_values($selected);
        usort($result, static fn (AnimationMeasure $a, AnimationMeasure $b): int => $a->visualOrder <=> $b->visualOrder);

        return $result;
    }

    /** @param list<string> $selected @param array<string, bool> $compatibility */
    private function anyCompatible(array $selected, array $compatibility): bool
    {
        foreach ($selected as $code) {
            if ($compatibility[$code] ?? false) {
                return true;
            }
        }

        return false;
    }
}
