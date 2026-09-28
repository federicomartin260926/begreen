<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\Protocol;
use App\Enum\ProjectCatalog;
use App\Repository\ProtocolRepository;
use App\Service\Animation\AnimationCatalogImporter;

final readonly class ProtocolAvailabilityResolver
{
    public function __construct(private ProtocolRepository $protocolRepository)
    {
    }

    /** @return list<Protocol> */
    public function getAvailableProtocols(Project $project): array
    {
        return array_values(array_filter(
            $this->protocolRepository->findForProjectType((string) $project->getType()),
            fn (Protocol $protocol): bool => $this->isAvailable($project, $protocol),
        ));
    }

    public function isAvailable(Project $project, Protocol $protocol): bool
    {
        $isAnimationProject = 'rodaje' === $project->getType()
            && ProjectCatalog::FILMING_GENRE_ANIMATION === $project->getFilmingGenre();
        $isAnimationProtocol = AnimationCatalogImporter::PROTOCOL_CODE === $protocol->getCode();

        if ($isAnimationProject || $isAnimationProtocol) {
            return $isAnimationProject && $isAnimationProtocol;
        }

        return $protocol->getType() === $project->getType() || Protocol::TYPE_AMBOS === $protocol->getType();
    }
}
