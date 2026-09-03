<?php

namespace App\Service;

use App\Entity\CrewDepartment;
use App\Entity\Project;
use App\Enum\ProjectCatalog;

final class CrewCatalogScopeResolver
{
    public function resolve(Project $project): string
    {
        return match ($project->getType()) {
            'evento' => CrewDepartment::SCOPE_EVENT,
            'rodaje' => $project->getFilmingGenre() === ProjectCatalog::FILMING_GENRE_ANIMATION
                ? CrewDepartment::SCOPE_ANIMATION
                : CrewDepartment::SCOPE_FILMING,
            default => throw new \InvalidArgumentException('Project must have a supported type to resolve the crew catalog scope.'),
        };
    }
}
