<?php

namespace App\Service\Animation;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Enum\ProjectCatalog;
use App\Service\PlanMeasureDuplicateResolver;
use App\Service\PlanMeasureGetOrCreate;

final readonly class AnimationPlanSynchronizer
{
    public function __construct(
        private AnimationProjectMeasureResolver $measureResolver,
        private ?PlanMeasureGetOrCreate $planMeasureGetOrCreate = null,
    ) {
    }

    public function supports(Plan $plan, Project $project): bool
    {
        return 'rodaje' === $project->getType()
            && ProjectCatalog::FILMING_GENRE_ANIMATION === $project->getFilmingGenre()
            && AnimationCatalogImporter::PROTOCOL_CODE === $plan->getProtocol()?->getCode();
    }

    /** @return list<PlanMeasure> */
    public function synchronize(Plan $plan, Project $project): array
    {
        if (!$this->supports($plan, $project)) {
            throw new \LogicException('Plan y proyecto no forman un contexto Animation válido.');
        }

        $eligibleMeasures = $this->measureResolver->resolve($project, $plan->getProtocol());
        $existingByCatalogId = [];
        foreach (PlanMeasureDuplicateResolver::unique($plan->getPlanMeasures()) as $planMeasure) {
            $catalogId = $planMeasure->getMeasure()?->getCatalogId();
            if (null !== $catalogId && !isset($existingByCatalogId[$catalogId])) {
                $existingByCatalogId[$catalogId] = $planMeasure;
            }
        }

        $missingMeasures = [];
        foreach ($eligibleMeasures as $measure) {
            $catalogId = $measure->getCatalogId();
            if (null === $catalogId) {
                throw new \LogicException('Una medida Animation elegible no tiene catalogId.');
            }

            if (!isset($existingByCatalogId[$catalogId])) {
                $missingMeasures[] = $measure;
            }
        }

        if ($missingMeasures !== [] && $plan->getId() !== null && $this->planMeasureGetOrCreate !== null) {
            foreach ($this->planMeasureGetOrCreate->getOrCreateMany($plan, $missingMeasures) as $planMeasure) {
                $catalogId = $planMeasure->getMeasure()?->getCatalogId();
                if ($catalogId !== null) {
                    $existingByCatalogId[$catalogId] = $planMeasure;
                }
            }
        }

        $eligiblePlanMeasures = [];
        foreach ($eligibleMeasures as $measure) {
            $catalogId = $measure->getCatalogId();
            $planMeasure = $existingByCatalogId[$catalogId] ?? null;
            if (!$planMeasure instanceof PlanMeasure) {
                // Preserve the existing pure in-memory contract for new plans and
                // isolated unit consumers without a persistence service.
                $planMeasure = (new PlanMeasure())->setMeasure($measure);
                $plan->addPlanMeasure($planMeasure);
                $existingByCatalogId[$catalogId] = $planMeasure;
            }

            $eligiblePlanMeasures[] = $planMeasure;
        }

        return $eligiblePlanMeasures;
    }

    /** @return list<Measure> */
    public function resolveMeasuresForTier(Plan $plan, Project $project, string $tier): array
    {
        if (!$this->supports($plan, $project)) {
            throw new \LogicException('Plan y proyecto no forman un contexto Animation válido.');
        }

        return $this->measureResolver->resolve($project, $plan->getProtocol(), $tier);
    }
}
