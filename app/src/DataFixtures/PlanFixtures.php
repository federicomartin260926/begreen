<?php

namespace App\DataFixtures;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Repository\MeasureRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class PlanFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(private readonly MeasureRepository $measureRepository) {}

    public static function getGroups(): array { return ['plans', 'demo']; }
    public function getDependencies(): array { return [ProjectFixtures::class, MeasureFixtures::class]; }

    public function load(ObjectManager $manager): void
    {
        foreach (FrancProjectPlanData::PROJECTS as $projectName => $fixture) {
            $this->seedFrancPlan($manager, $projectName, $fixture);
        }
        $manager->flush();
    }

    /** @param array<string, mixed> $fixture */
    private function seedFrancPlan(ObjectManager $manager, string $projectName, array $fixture): void
    {
        $project = $manager->getRepository(Project::class)->findOneBy(['name' => $projectName]);
        if (!$project instanceof Project || $project->getCommercialTier() !== $fixture['tier']) {
            throw new \RuntimeException(sprintf('Project/tier not available for Franc plan seed: %s', $projectName));
        }
        $protocol = $manager->getRepository(Protocol::class)->findOneBy(['code' => $fixture['protocolCode']]);
        if (!$protocol instanceof Protocol) {
            throw new \RuntimeException(sprintf('Protocol not found for Franc plan seed: %s', $projectName));
        }
        $plan = $manager->getRepository(Plan::class)->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            $plan = (new Plan())->setProject($project)->setUser($project->getUser());
            $manager->persist($plan);
        } else {
            $this->resetPlan($plan);
        }
        $plan->setProject($project)
            ->setUser($project->getUser())
            ->setProtocol($protocol)
            ->setStatus('completo')
            ->markCustomMeasuresCompleted()
            ->setStatusChangedAt(new \DateTimeImmutable())
            ->setCustomMeasures(null);
        foreach ($fixture['presentedGamificationLevels'] as $level) {
            $plan->markGamificationLevelPresented($level);
        }

        $measureIndex = $this->indexMeasures($this->measureRepository->getCatalogMeasuresForProtocol($project, $protocol));
        $definitions = $fixture['measures'];
        if (count($measureIndex) !== count($definitions)) {
            throw new \RuntimeException(sprintf('Fixture catalog count mismatch for %s: expected %d, found %d',
                $projectName, count($definitions), count($measureIndex)));
        }
        $definedOrders = array_column($definitions, 'sortOrder');
        sort($definedOrders);
        $catalogOrders = array_keys($measureIndex);
        sort($catalogOrders);
        if ($definedOrders !== $catalogOrders) {
            throw new \RuntimeException(sprintf('Fixture catalog sort orders mismatch for %s', $projectName));
        }
        $blockAnswers = [];
        foreach ($fixture['blockAnswers'] as $blockDefinition) {
            $anchorOrder = $blockDefinition['anchorSortOrder'];
            $anchorMeasure = $measureIndex[$anchorOrder] ?? null;
            $block = $anchorMeasure?->getMeasureBlock();
            if ($block === null) {
                throw new \RuntimeException(sprintf('Missing fixture block anchor %s/%d', $projectName, $anchorOrder));
            }
            $answer = (new SustainabilityPlanBlockAnswer())
                ->setMeasureBlock($block)
                ->setApplies($blockDefinition['applies'])
                ->setAnsweredBy($project->getUser());
            $plan->addBlockAnswer($answer);
            $manager->persist($answer);
            $blockAnswers[$anchorOrder] = $answer;
        }
        foreach ($definitions as $definition) {
            $order = $definition['sortOrder'];
            $measure = $measureIndex[$order];
            if ($measure->getName() !== $definition['name']) {
                throw new \RuntimeException(sprintf('Fixture measure name mismatch: %s/%d', $projectName, $order));
            }
            $planMeasure = (new PlanMeasure())
                ->setMeasure($measure)
                ->setIsApplicable($definition['isApplicable'])
                ->setWillImplement($definition['willImplement'])
                ->setIsCritical($definition['isCritical'])
                ->setObservations($definition['observations'])
                // Elaboration only: no Implementation decisions/actions/incidents/evidence.
                ->setImplemented(null)
                ->setActionTaken(null)
                ->setExecutionIncident(null)
                ->setEvidence(null);
            if (array_key_exists('blockAnchorSortOrder', $definition)) {
                $anchorOrder = $definition['blockAnchorSortOrder'];
                $answer = $blockAnswers[$anchorOrder] ?? null;
                if (!$answer instanceof SustainabilityPlanBlockAnswer
                    || $answer->applies()
                    || $measure->getMeasureBlock() !== $measureIndex[$anchorOrder]->getMeasureBlock()) {
                    throw new \RuntimeException(sprintf('Invalid fixture block skip for %s/%d', $projectName, $order));
                }
                $planMeasure->markAsBlockSkipped($answer);
            } else {
                $planMeasure->markAsManual();
            }
            $plan->addPlanMeasure($planMeasure);
            $manager->persist($planMeasure);
        }
    }

    /** @param iterable<Measure> $measures @return array<int, Measure> */
    private function indexMeasures(iterable $measures): array
    {
        $index = [];
        foreach ($measures as $measure) {
            if (!$measure instanceof Measure) { continue; }
            $order = (int) $measure->getSortOrder();
            if (isset($index[$order])) {
                throw new \RuntimeException(sprintf('Duplicate catalog sortOrder %d', $order));
            }
            $index[$order] = $measure;
        }
        return $index;
    }

    private function resetPlan(Plan $plan): void
    {
        foreach (clone $plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure instanceof PlanMeasure) { $plan->removePlanMeasure($planMeasure); }
        }
        foreach (clone $plan->getBlockAnswers() as $answer) {
            $plan->removeBlockAnswer($answer);
        }
    }
}
