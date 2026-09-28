<?php

namespace App\Service\Animation;

use App\Entity\Measure;
use App\Entity\Project;
use App\Enum\ProjectCatalog;

final class AnimationNotApplicableValidator
{
    public function allows(Project $project, Measure $measure): bool
    {
        $isAnimationProject = 'rodaje' === $project->getType()
            && ProjectCatalog::FILMING_GENRE_ANIMATION === $project->getFilmingGenre();
        $isAnimationMeasure = AnimationCatalogImporter::PROTOCOL_CODE === $measure->getProtocol()?->getCode();

        if (!$isAnimationProject && !$isAnimationMeasure) {
            return true;
        }

        return $isAnimationProject
            && $isAnimationMeasure
            && true === $measure->getAnimationMetadata()?->isNotApplicableAllowed();
    }
}
