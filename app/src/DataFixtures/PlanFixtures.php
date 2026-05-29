<?php

namespace App\DataFixtures;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Repository\MeasureRepository;
use App\Service\PlanBlockQuestionService;
use App\Service\PlanMeasureCatalogResolver;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

final class PlanFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly MeasureRepository $measureRepository,
        private readonly PlanBlockQuestionService $blockQuestionService,
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
        /** @var Project|null $project */
        $project = $manager->getRepository(Project::class)->findOneBy(['name' => 'Proyecto Fede']);
        if (!$project instanceof Project) {
            echo "⚠️  Project not found for demo plan seed: Proyecto Fede\n";
            return;
        }

        /** @var Protocol|null $protocol */
        $protocol = $manager->getRepository(Protocol::class)->findOneBy([
            'code' => PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE,
        ]);
        if (!$protocol instanceof Protocol) {
            echo "⚠️  Protocol not found for demo plan seed: Be Green My Film\n";
            return;
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
            $manager->flush();
        }

        $plan
            ->setProject($project)
            ->setUser($project->getUser() ?? null)
            ->setProtocol($protocol)
            ->setStatus('incompleto')
            ->setStatusChangedAt(new \DateTimeImmutable())
            ->setCustomMeasures(null);

        $catalogMeasures = $this->measureRepository->getCatalogMeasuresForProtocol($project, $protocol);
        $usedMeasureIds = [];

        $blockSeed = $this->findSeedableBlockQuestion($catalogMeasures);
        if ($blockSeed !== null) {
            [$block, $blockMeasures] = $blockSeed;
            $this->blockQuestionService->applyAnswer(
                $plan,
                $block,
                false,
                $project->getUser(),
                $blockMeasures
            );
            foreach ($blockMeasures as $measure) {
                $usedMeasureIds[(int) $measure->getId()] = true;
            }
        }

        $remainingMeasures = array_values(array_filter(
            $catalogMeasures,
            static fn (Measure $measure): bool => !isset($usedMeasureIds[(int) $measure->getId()])
        ));

        usort($remainingMeasures, static function (Measure $left, Measure $right): int {
            $scoreDiff = (int) ($right->getScore() ?? 0) <=> (int) ($left->getScore() ?? 0);
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }

            return (int) $left->getId() <=> (int) $right->getId();
        });

        $states = [
            ['isApplicable' => true,  'willImplement' => true,  'implemented' => true,  'isCritical' => true,  'criticalReason' => 'Demo'],
            ['isApplicable' => true,  'willImplement' => false, 'implemented' => null,   'isCritical' => false, 'criticalReason' => null],
            ['isApplicable' => false, 'willImplement' => null,  'implemented' => null,   'isCritical' => null,  'criticalReason' => null],
            ['isApplicable' => null,  'willImplement' => null,  'implemented' => null,   'isCritical' => null,  'criticalReason' => null],
            ['isApplicable' => true,  'willImplement' => true,  'implemented' => false,  'isCritical' => true,  'criticalReason' => 'Demo'],
        ];

        foreach ($states as $index => $state) {
            if (!isset($remainingMeasures[$index])) {
                break;
            }

            $measure = $remainingMeasures[$index];
            $planMeasure = new PlanMeasure();
            $plan->addPlanMeasure($planMeasure);
            $planMeasure->setMeasure($measure);
            $planMeasure->markAsManual();
            $planMeasure->setIsApplicable($state['isApplicable']);
            $planMeasure->setWillImplement($state['willImplement']);
            $planMeasure->setImplemented($state['implemented']);
            $planMeasure->setIsCritical($state['isCritical']);
            $planMeasure->setCriticalReason($state['criticalReason']);
            $manager->persist($planMeasure);
        }

        $manager->flush();
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

    /**
     * @param Measure[] $catalogMeasures
     * @return array{0: MeasureBlock, 1: Measure[]}|null
     */
    private function findSeedableBlockQuestion(array $catalogMeasures): ?array
    {
        $preferredCodes = [
            'modulo-animales-en-el-rodaje',
            'modulo-menores-en-el-rodaje',
            'biodiversidad',
        ];

        foreach ($preferredCodes as $preferredCode) {
            $blockMeasures = array_values(array_filter(
                $catalogMeasures,
                static fn (Measure $measure): bool => $measure->getMeasureBlock()?->getCode() === $preferredCode
            ));

            if ($blockMeasures === []) {
                continue;
            }

            $block = $blockMeasures[0]->getMeasureBlock();
            if ($block instanceof MeasureBlock && $block->hasScreeningQuestion()) {
                return [$block, $blockMeasures];
            }
        }

        foreach ($catalogMeasures as $measure) {
            $block = $measure->getMeasureBlock();
            if ($block instanceof MeasureBlock && $block->hasScreeningQuestion()) {
                $blockMeasures = array_values(array_filter(
                    $catalogMeasures,
                    static fn (Measure $candidate): bool => $candidate->getMeasureBlock()?->getId() === $block->getId()
                ));

                if ($blockMeasures !== []) {
                    return [$block, $blockMeasures];
                }
            }
        }

        return null;
    }
}
