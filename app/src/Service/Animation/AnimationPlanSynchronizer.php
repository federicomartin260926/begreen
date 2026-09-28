<?php

namespace App\Service\Animation;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Enum\ProjectCatalog;

final readonly class AnimationPlanSynchronizer
{
    public function __construct(private AnimationProjectMeasureResolver $measureResolver)
    {
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
        foreach ($plan->getPlanMeasures() as $planMeasure) {
            $catalogId = $planMeasure->getMeasure()?->getCatalogId();
            if (null !== $catalogId && !isset($existingByCatalogId[$catalogId])) {
                $existingByCatalogId[$catalogId] = $planMeasure;
            }
        }

        $eligiblePlanMeasures = [];
        foreach ($eligibleMeasures as $measure) {
            $catalogId = $measure->getCatalogId();
            if (null === $catalogId) {
                throw new \LogicException('Una medida Animation elegible no tiene catalogId.');
            }

            $planMeasure = $existingByCatalogId[$catalogId] ?? null;
            if (!$planMeasure instanceof PlanMeasure) {
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
