<?php

namespace App\DataFixtures;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Repository\MeasureRepository;
use App\Service\PlanMeasureCatalogResolver;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

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
            ->setStatus('completo')
            ->setStatusChangedAt(new \DateTimeImmutable())
            ->setCustomMeasures(null);

        $catalogMeasures = $this->measureRepository->getCatalogMeasuresForProtocol($project, $protocol);

        $measuresToSeed = array_values($catalogMeasures);

        usort($measuresToSeed, static function (Measure $left, Measure $right): int {
            $scoreDiff = (int) ($right->getScore() ?? 0) <=> (int) ($left->getScore() ?? 0);
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }

            return (int) $left->getId() <=> (int) $right->getId();
        });

        $demoEvidencePath = $this->ensureDemoEvidence();
        $states = [
            ['isApplicable' => true,  'willImplement' => true,  'implemented' => null,  'isCritical' => true],
            ['isApplicable' => true,  'willImplement' => true,  'implemented' => true,  'isCritical' => false, 'actionTaken' => 'Acción de ejemplo iniciada.'],
            ['isApplicable' => true,  'willImplement' => true,  'implemented' => true,  'isCritical' => true, 'actionTaken' => 'Acción de ejemplo completada.', 'evidence' => $demoEvidencePath],
            ['isApplicable' => true,  'willImplement' => true,  'implemented' => false, 'isCritical' => true, 'executionIncident' => 'La medida no puede ejecutarse en las condiciones actuales del proyecto.'],
            ['isApplicable' => true,  'willImplement' => false, 'implemented' => null,  'isCritical' => null],
            ['isApplicable' => false, 'willImplement' => null,  'implemented' => null,  'isCritical' => null],
        ];

        foreach ($measuresToSeed as $index => $measure) {
            $state = $states[$index % count($states)];
            $planMeasure = new PlanMeasure();
            $plan->addPlanMeasure($planMeasure);
            $planMeasure->setMeasure($measure);
            $planMeasure->markAsManual();
            $planMeasure->setIsApplicable($state['isApplicable']);
            $planMeasure->setWillImplement($state['willImplement']);
            $planMeasure->setImplemented($state['implemented']);
            $planMeasure->setIsCritical($state['isCritical']);
            $planMeasure->setActionTaken($state['actionTaken'] ?? null);
            $planMeasure->setEvidence($state['evidence'] ?? null);
            $planMeasure->setExecutionIncident($state['executionIncident'] ?? null);
            $planMeasure->setObservations('Observación de ejemplo sobre la decisión de Elaboración.');
            $manager->persist($planMeasure);
        }

        $manager->flush();
    }

    private function ensureDemoEvidence(): string
    {
        $publicPath = '/uploads/evidences/demo/implemented-measure.png';
        $absolutePath = dirname(__DIR__, 2) . '/public' . $publicPath;
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create demo evidence directory "%s".', $directory));
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8WQAAAABJRU5ErkJggg==', true);
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
