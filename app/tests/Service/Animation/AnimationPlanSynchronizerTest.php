<?php

namespace App\Tests\Service\Animation;

use App\Entity\AnimationMeasureMetadata;
use App\Entity\AnimationProjectConfiguration;
use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Repository\MeasureRepository;
use App\Service\Animation\AnimationCatalogImporter;
use App\Service\Animation\AnimationConfigurationCatalog;
use App\Service\Animation\AnimationConfigurationFactory;
use App\Service\Animation\AnimationMeasure;
use App\Service\Animation\AnimationMeasureProvider;
use App\Service\Animation\AnimationMeasureSelector;
use App\Service\Animation\AnimationPlanSynchronizer;
use App\Service\Animation\AnimationProjectMeasureResolver;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\PlanMeasureElaborationDecisionValidator;
use App\Service\SustainabilityPlanCompletionService;
use App\Service\SustainabilityPlanMeasureOrderer;
use App\Tests\Support\CommercialPlanTestHelpers;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AnimationPlanSynchronizerTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testSyncPreservesHistoryAcrossConfigurationAndTierChangesWithoutDuplicates(): void
    {
        $protocol = $this->animationProtocol();
        $measureA = $this->measure($protocol, 'ANI-A', 20, ['TEC_2D_DIGITAL'], ['basic', 'standard', 'pro']);
        $measureB = $this->measure($protocol, 'ANI-B', 10, ['TEC_3D_CGI'], ['basic', 'standard', 'pro']);
        $measurePro = $this->measure($protocol, 'ANI-PRO', 5, ['TEC_2D_DIGITAL', 'TEC_3D_CGI'], ['pro']);
        $project = $this->animationProject('basic');
        $configuration = $project->getAnimationConfiguration();
        self::assertInstanceOf(AnimationProjectConfiguration::class, $configuration);
        $synchronizer = $this->synchronizer([$measureA, $measureB, $measurePro]);
        $plan = (new Plan())->setProject($project)->setProtocol($protocol);

        $first = $synchronizer->synchronize($plan, $project);
        self::assertSame(['ANI-A'], $this->ids($first));
        $historicalA = $first[0]
            ->setIsApplicable(false)
            ->setObservations('Respuesta histórica que debe conservarse.');

        self::assertSame($first, $synchronizer->synchronize($plan, $project));
        self::assertCount(1, $plan->getPlanMeasures());

        $configuration->setTechniques(['TEC_3D_CGI'])->setShootingAnswered(false);
        $currentB = $synchronizer->synchronize($plan, $project);
        self::assertSame(['ANI-B'], $this->ids($currentB));
        self::assertCount(2, $plan->getPlanMeasures());
        self::assertFalse($historicalA->isApplicable());
        self::assertSame('Respuesta histórica que debe conservarse.', $historicalA->getObservations());
        self::assertSame(['ANI-B'], array_map(
            static fn (Measure $measure): ?string => $measure->getCatalogId(),
            $this->completionService($synchronizer)->getVisibleMeasures($plan, $project),
        ));
        self::assertSame(
            [$measureB->getId() => 1],
            $this->completionService($synchronizer)->getVisibleMeasurePositions($plan, $project),
        );

        $configuration->setTechniques(['TEC_2D_DIGITAL'])->setShootingAnswered(false);
        $restored = $synchronizer->synchronize($plan, $project);
        self::assertSame($historicalA, $restored[0]);
        self::assertCount(2, $plan->getPlanMeasures());
        self::assertSame(
            ['ANI-PRO', 'ANI-A'],
            array_map(
                static fn (Measure $measure): ?string => $measure->getCatalogId(),
                $synchronizer->resolveMeasuresForTier($plan, $project, 'pro'),
            ),
        );

        $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->setTier('pro');
        $pro = $synchronizer->synchronize($plan, $project);
        self::assertSame(['ANI-PRO', 'ANI-A'], $this->ids($pro));
        self::assertSame(
            [$measurePro->getId() => 1, $measureA->getId() => 2],
            $this->completionService($synchronizer)->getVisibleMeasurePositions($plan, $project),
        );
        self::assertCount(3, $plan->getPlanMeasures());

        $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->setTier('basic');
        self::assertSame(['ANI-A'], $this->ids($synchronizer->synchronize($plan, $project)));
        $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->setTier('pro');
        self::assertSame($pro[0], $synchronizer->synchronize($plan, $project)[0]);
        self::assertCount(3, $plan->getPlanMeasures());
    }

    public function testIncompleteAnimationConfigurationFailsClearly(): void
    {
        $protocol = $this->animationProtocol();
        $project = $this->makeProjectWithTier('basic')
            ->setType(Protocol::TYPE_RODAJE)
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION)
            ->setDistributionMedia(['tv']);
        (new AnimationProjectConfiguration())->setProject($project);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuración Animation incompleta');
        $this->synchronizer([])->synchronize((new Plan())->setProject($project)->setProtocol($protocol), $project);
    }

    /** @param list<Measure> $measures */
    private function synchronizer(array $measures): AnimationPlanSynchronizer
    {
        $provider = new class($measures) implements AnimationMeasureProvider {
            /** @param list<Measure> $measures */
            public function __construct(private array $measures) {}
            public function forProtocol(Protocol $protocol): array { return $this->measures; }
        };

        return new AnimationPlanSynchronizer(new AnimationProjectMeasureResolver(
            $provider,
            new AnimationConfigurationFactory(),
            new AnimationMeasureSelector(),
            $this->makeProjectFeatureGate(),
        ));
    }

    private function animationProject(string $tier): Project
    {
        $project = $this->makeProjectWithTier($tier)
            ->setType(Protocol::TYPE_RODAJE)
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION)
            ->setDistributionMedia(['tv']);
        (new AnimationProjectConfiguration())
            ->setProject($project)
            ->setTechniques(['TEC_2D_DIGITAL'])
            ->setStructure('ESC_MICRO')
            ->setShootingAnswered(false)
            ->setProcessingInfrastructures(['PROC_INFRA_EQUIPOS'])
            ->setProcessingLevel('PROC_NIVEL_BASICO')
            ->setUsesAi(false);

        return $project;
    }

    /** @param list<string> $techniques @param list<string> $tiers */
    private function measure(Protocol $protocol, string $id, int $order, array $techniques, array $tiers): Measure
    {
        $planCompatibility = array_fill_keys(array_values(AnimationConfigurationCatalog::PLANS), false);
        foreach ($tiers as $tier) {
            $planCompatibility[AnimationConfigurationCatalog::PLANS[$tier]] = true;
        }
        $filter = new AnimationMeasure(
            id: $id,
            active: true,
            visualOrder: $order,
            planCompatibility: $planCompatibility,
            forceIfTechniqueMatches: false,
            forceIfShooting: false,
            notApplicableAllowed: true,
            operationalCondition: null,
            techniqueCompatibility: array_fill_keys($techniques, true),
            structureCompatibility: ['ESC_MICRO' => true],
            processingLevelCompatibility: ['PROC_NIVEL_BASICO' => true],
            processingInfrastructureCompatibility: ['PROC_INFRA_EQUIPOS' => true],
            shootingCompatibility: ['RODAJE_NO' => true],
            aiCompatibility: ['IA_NO' => true],
            interactiveCompatibility: ['INTERACTIVO_NO' => true],
        );
        $measure = (new Measure())->setCatalogId($id)->setSortOrder($order)->setProtocol($protocol);
        $reflection = new \ReflectionProperty(Measure::class, 'id');
        $reflection->setValue($measure, $order);
        (new AnimationMeasureMetadata())->setMeasure($measure)->syncFrom($filter, null, null, null);

        return $measure;
    }

    private function animationProtocol(): Protocol
    {
        return (new Protocol())
            ->setCode(AnimationCatalogImporter::PROTOCOL_CODE)
            ->setName('Be Green My Animation')
            ->setType(Protocol::TYPE_RODAJE);
    }

    private function completionService(AnimationPlanSynchronizer $synchronizer): SustainabilityPlanCompletionService
    {
        $featureGate = $this->makeProjectFeatureGate();

        return new SustainabilityPlanCompletionService(
            $this->createMock(MeasureRepository::class),
            new PlanMeasureCatalogResolver($featureGate),
            new SustainabilityPlanMeasureOrderer(),
            new PlanMeasureElaborationDecisionValidator(),
            $synchronizer,
        );
    }

    /** @param iterable<\App\Entity\PlanMeasure> $planMeasures @return list<string|null> */
    private function ids(iterable $planMeasures): array
    {
        return array_map(
            static fn ($planMeasure): ?string => $planMeasure->getMeasure()?->getCatalogId(),
            is_array($planMeasures) ? $planMeasures : iterator_to_array($planMeasures),
        );
    }
}
