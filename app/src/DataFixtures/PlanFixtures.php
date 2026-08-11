<?php

namespace App\DataFixtures;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Repository\MeasureRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class PlanFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly MeasureRepository $measureRepository,
    ) {
    }

    public static function getGroups(): array
    {
        return ['plans', 'demo'];
    }

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
            MeasureFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $demoEvidencePath = $this->ensureDemoEvidence();

        foreach (FrancProjectPlanData::PROJECTS as $projectName => $definition) {
            $this->seedFrancPlan(
                $manager,
                $projectName,
                $definition['protocolCode'],
                $definition['measures'],
                $demoEvidencePath,
            );
        }

        $manager->flush();
    }

    /**
     * @param list<array{
     *     sortOrder: int,
     *     name: string,
     *     isApplicable: ?bool,
     *     willImplement: ?bool,
     *     implemented: ?bool,
     *     isCritical: ?bool,
     *     observations: ?string,
     *     actionTaken: ?string,
     *     executionIncident: ?string,
     *     hasEvidence: bool
     * }> $measureDefinitions
     */
    private function seedFrancPlan(
        ObjectManager $manager,
        string $projectName,
        string $protocolCode,
        array $measureDefinitions,
        string $demoEvidencePath,
    ): void {
        /** @var Project|null $project */
        $project = $manager->getRepository(Project::class)->findOneBy(['name' => $projectName]);
        if (!$project instanceof Project) {
            throw new \RuntimeException(sprintf('Project not found for Franc plan seed: %s', $projectName));
        }

        /** @var Protocol|null $protocol */
        $protocol = $manager->getRepository(Protocol::class)->findOneBy(['code' => $protocolCode]);
        if (!$protocol instanceof Protocol) {
            throw new \RuntimeException(sprintf('Protocol not found for Franc plan seed: %s', $protocolCode));
        }

        /** @var Plan|null $plan */
        $plan = $manager->getRepository(Plan::class)->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            $plan = (new Plan())
                ->setProject($project)
                ->setUser($project->getUser() ?? null);
            $manager->persist($plan);
        } else {
            $this->resetPlan($plan);
        }

        $plan
            ->setProject($project)
            ->setUser($project->getUser() ?? null)
            ->setProtocol($protocol)
            ->setStatus('completo')
            ->markCustomMeasuresCompleted()
            ->setStatusChangedAt(new \DateTimeImmutable())
            ->setCustomMeasures(null);

        $catalogMeasures = $this->measureRepository->getCatalogMeasuresForProtocol($project, $protocol);
        $measureIndex = $this->indexMeasures($catalogMeasures);

        foreach ($measureDefinitions as $definition) {
            $measure = $measureIndex[$definition['sortOrder']] ?? null;

            // Las fixtures deben respetar el catálogo contratado del proyecto.
            // Una definición de FrancProjectPlanData fuera del tier actual no debe
            // materializarse como PlanMeasure.
            if (!$measure instanceof Measure) {
                continue;
            }

            $planMeasure = (new PlanMeasure())
                ->setMeasure($measure)
                ->setIsApplicable($definition['isApplicable'])
                ->setWillImplement($definition['willImplement'])
                ->setImplemented($definition['implemented'])
                ->setIsCritical($definition['isCritical'])
                ->setObservations($definition['observations'])
                ->setActionTaken($definition['actionTaken'])
                ->setExecutionIncident($definition['executionIncident'])
                ->setEvidence($definition['hasEvidence'] ? $demoEvidencePath : null);

            $planMeasure->markAsManual();
            $plan->addPlanMeasure($planMeasure);
            $manager->persist($planMeasure);
        }
    }

    /**
     * @param iterable<Measure> $measures
     * @return array<int, Measure>
     */
    private function indexMeasures(iterable $measures): array
    {
        $index = [];

        foreach ($measures as $measure) {
            if (!$measure instanceof Measure) {
                continue;
            }

            $sortOrder = (int) $measure->getSortOrder();
            if (isset($index[$sortOrder])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate catalog measure sort order %d for measures "%s" and "%s".',
                    $sortOrder,
                    $index[$sortOrder]->getName(),
                    $measure->getName(),
                ));
            }

            $index[$sortOrder] = $measure;
        }

        return $index;
    }

    private function ensureDemoEvidence(): string
    {
        $publicPath = '/uploads/evidences/demo/implemented-measure.png';
        $absolutePath = dirname(__DIR__, 2).'/public'.$publicPath;
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create demo evidence directory "%s".', $directory));
        }

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8WQAAAABJRU5ErkJggg==',
            true,
        );

        if ($png === false || file_put_contents($absolutePath, $png) === false) {
            throw new \RuntimeException(sprintf('Unable to create demo evidence "%s".', $absolutePath));
        }

        return $publicPath;
    }

    private function resetPlan(Plan $plan): void
    {
        foreach (clone $plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure instanceof PlanMeasure) {
                $plan->removePlanMeasure($planMeasure);
            }
        }

        foreach (clone $plan->getBlockAnswers() as $blockAnswer) {
            $plan->removeBlockAnswer($blockAnswer);
        }
    }
}
