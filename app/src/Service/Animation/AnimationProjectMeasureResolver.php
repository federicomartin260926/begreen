<?php

namespace App\Service\Animation;

use App\Entity\Measure;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Service\ProjectFeatureGate;

final readonly class AnimationProjectMeasureResolver
{
    public function __construct(
        private AnimationMeasureProvider $measureProvider,
        private AnimationConfigurationFactory $configurationFactory,
        private AnimationMeasureSelector $selector,
        private ProjectFeatureGate $featureGate,
    ) {
    }

    /** @return list<Measure> */
    public function resolve(Project $project, Protocol $protocol, ?string $tier = null): array
    {
        $this->assertAnimationContext($project, $protocol);
        $tier ??= $this->featureGate->getTier($project, CommercialPhase::ELABORATION);

        return $this->resolveFromMeasures(
            $project,
            $protocol,
            $this->measureProvider->forProtocol($protocol),
            $tier,
        );
    }

    public function supports(Project $project): bool
    {
        return 'rodaje' === $project->getType()
            && ProjectCatalog::FILMING_GENRE_ANIMATION === $project->getFilmingGenre();
    }

    /** @return list<int> */
    public function resolvePersistedIds(Project $project, Protocol $protocol, ?string $tier = null): array
    {
        return array_map(
            static function (Measure $measure): int {
                $id = $measure->getId();
                if (null === $id) {
                    throw new \LogicException('Una medida Animation elegible no está persistida.');
                }

                return $id;
            },
            $this->resolve($project, $protocol, $tier),
        );
    }

    /** @param list<Measure> $measures @return list<Measure> */
    public function resolveFromMeasures(Project $project, Protocol $protocol, array $measures, string $tier): array
    {
        $this->assertAnimationContext($project, $protocol);
        $configuration = $this->configurationFactory->fromProject($project, $tier);
        $byCatalogId = [];
        $selectorCatalog = [];

        foreach ($measures as $measure) {
            if (!$measure instanceof Measure || $measure->getProtocol()?->getCode() !== AnimationCatalogImporter::PROTOCOL_CODE) {
                throw new \LogicException('El catálogo Animation contiene una medida de otro protocolo.');
            }

            $catalogId = $measure->getCatalogId();
            $metadata = $measure->getAnimationMetadata();
            if (null === $catalogId || null === $metadata) {
                throw new \LogicException('Una medida Animation no tiene catalogId o metadata persistida.');
            }

            $byCatalogId[$catalogId] = $measure;
            $selectorCatalog[] = $metadata->toAnimationMeasure();
        }

        $selected = $this->selector->select($configuration, $selectorCatalog);

        return array_map(
            static fn (AnimationMeasure $measure): Measure => $byCatalogId[$measure->id],
            $selected,
        );
    }

    public function isEligible(Project $project, Measure $measure): bool
    {
        $protocol = $measure->getProtocol();
        if (!$protocol instanceof Protocol || $protocol->getCode() !== AnimationCatalogImporter::PROTOCOL_CODE) {
            return false;
        }

        return [] !== $this->resolveFromMeasures(
            $project,
            $protocol,
            [$measure],
            $this->featureGate->getTier($project, CommercialPhase::ELABORATION),
        );
    }

    private function assertAnimationContext(Project $project, Protocol $protocol): void
    {
        if (!$this->supports($project)) {
            throw new \LogicException('El proyecto no es Animation.');
        }

        if (AnimationCatalogImporter::PROTOCOL_CODE !== $protocol->getCode()) {
            throw new \LogicException('Un proyecto Animation requiere el protocolo Be Green My Animation.');
        }
    }
}
