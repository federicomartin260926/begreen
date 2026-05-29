<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Repository\MeasureRepository;
use App\Repository\ProjectSubscriptionRepository;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Service\SustainabilityCommitmentLevelService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SustainabilityCommitmentLevelServiceTest extends TestCase
{
    #[DataProvider('boundaryCases')]
    public function testBoundaryLevelsByPercentage(int $plannedPoints, string $expectedLevel): void
    {
        [$service, $plan, $project, $protocol, $catalogMeasures] = $this->createServiceWithCatalog(100, array_fill(0, 100, 1));

        $this->attachPlanMeasures($plan, $catalogMeasures, $plannedPoints, $plannedPoints);

        $summary = $service->buildSummary($plan, $project);

        self::assertSame(100, $summary['totalOfficialPoints']);
        self::assertSame($plannedPoints, $summary['planned']['points']);
        self::assertSame($plannedPoints, $summary['implemented']['points']);
        self::assertSame($plannedPoints, (int) round($summary['planned']['percentage']));
        self::assertSame($plannedPoints, $summary['planned']['percentageRounded']);
        self::assertSame($expectedLevel, $summary['planned']['levelKey']);
        self::assertSame($expectedLevel, $summary['implemented']['levelKey']);
    }

    public function testUsesPointsNotNumberOfMeasures(): void
    {
        [$service, $plan, $project, $protocol, $catalogMeasures] = $this->createServiceWithCatalog(5, [5, 1, 1, 1, 2]);

        $this->attachPlanMeasures($plan, [$catalogMeasures[0]], 5, 5);

        $customProtocol = (new Protocol())
            ->setCode('custom-measure')
            ->setName('Custom measure');
        $customMeasure = (new Measure())
            ->setName('Custom measure')
            ->setProtocol($customProtocol)
            ->setImportVersion('legacy')
            ->setScore(100);
        $this->setEntityId($customProtocol, 9101);
        $this->setEntityId($customMeasure, 9102);
        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($customMeasure)
                ->setIsApplicable(true)
                ->setWillImplement(true)
                ->setImplemented(true)
        );

        $summary = $service->buildSummary($plan, $project);

        self::assertSame(10, $summary['totalOfficialPoints']);
        self::assertSame(5, $summary['planned']['points']);
        self::assertSame(5, $summary['implemented']['points']);
        self::assertSame('tree', $summary['planned']['levelKey']);
        self::assertSame('tree', $summary['implemented']['levelKey']);
    }

    public function testPlannedAndImplementedUseDifferentFlags(): void
    {
        [$service, $plan, $project, $protocol, $catalogMeasures] = $this->createServiceWithCatalog(2, [5, 5]);

        $this->attachPlanMeasures(
            $plan,
            $catalogMeasures,
            5,
            5,
            [true, false],
            [false, true]
        );

        $summary = $service->buildSummary($plan, $project);

        self::assertSame(10, $summary['totalOfficialPoints']);
        self::assertSame(5, $summary['planned']['points']);
        self::assertSame(5, $summary['implemented']['points']);
        self::assertSame('tree', $summary['planned']['levelKey']);
        self::assertSame('tree', $summary['implemented']['levelKey']);
    }

    public function testSkippedBlockMeasuresDoNotCountTowardsPlannedPoints(): void
    {
        [$service, $plan, $project, $protocol, $catalogMeasures] = $this->createServiceWithCatalog(2, [5, 5]);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('block-2')
            ->setName('Bloque 2')
            ->setSortOrder(2);
        $this->setEntityId($block, 9101);
        $catalogMeasures[1]->setMeasureBlock($block);

        $blockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($block)
            ->setApplies(false);
        $this->setEntityId($blockAnswer, 9201);
        $plan->addBlockAnswer($blockAnswer);

        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($catalogMeasures[0])
                ->setIsApplicable(true)
                ->setWillImplement(true)
                ->setImplemented(true)
        );
        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($catalogMeasures[1])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip')
                ->setBlockSkipAnswer($blockAnswer)
                ->setWillImplement(null)
                ->setImplemented(null)
        );

        $summary = $service->buildSummary($plan, $project);

        self::assertSame(5, $summary['totalOfficialPoints']);
        self::assertSame(1, $summary['officialMeasures']);
        self::assertSame(5, $summary['planned']['points']);
        self::assertSame(5, $summary['implemented']['points']);
        self::assertSame(100, $summary['planned']['percentageRounded']);
        self::assertSame(100, $summary['implemented']['percentageRounded']);
    }

    public static function boundaryCases(): array
    {
        return [
            [0, 'seed'],
            [20, 'seed'],
            [21, 'plant'],
            [40, 'plant'],
            [41, 'tree'],
            [60, 'tree'],
            [61, 'forest'],
            [80, 'forest'],
            [81, 'jungle'],
            [100, 'jungle'],
        ];
    }

    /**
     * @param int[] $scores
     * @return array{0: SustainabilityCommitmentLevelService, 1: Plan, 2: Project, 3: Protocol, 4: Measure[]}
     */
    private function createServiceWithCatalog(int $measureCount, array $scores): array
    {
        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProject')->willReturn(null);

        $gate = new ProjectFeatureGate($subscriptionRepository);
        $resolver = new PlanMeasureCatalogResolver($gate);

        $measureRepository = $this->createMock(MeasureRepository::class);

        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film');
        $this->setEntityId($protocol, 9001);

        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setTier(ProjectSubscription::TIER_PRO)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);
        $project->setSubscription($subscription);

        $plan = (new Plan())
            ->setProject($project)
            ->setProtocol($protocol);

        $catalogMeasures = [];
        for ($i = 0; $i < $measureCount; $i++) {
            $measure = (new Measure())
                ->setName('Measure ' . ($i + 1))
                ->setProtocol($protocol)
                ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
                ->setScore($scores[$i] ?? 1);
            $this->setEntityId($measure, 1000 + $i + 1);
            $catalogMeasures[] = $measure;
        }

        $measureRepository->expects(self::once())
            ->method('getCatalogMeasuresForProtocol')
            ->with($project, $protocol)
            ->willReturn($catalogMeasures);

        $service = new SustainabilityCommitmentLevelService($measureRepository, $resolver);

        return [$service, $plan, $project, $protocol, $catalogMeasures];
    }

    /**
     * @param Measure[] $catalogMeasures
     * @param bool[]|null $plannedFlags
     * @param bool[]|null $implementedFlags
     */
    private function attachPlanMeasures(
        Plan $plan,
        array $catalogMeasures,
        int $plannedPoints,
        int $implementedPoints,
        ?array $plannedFlags = null,
        ?array $implementedFlags = null
    ): void {
        foreach ($catalogMeasures as $index => $measure) {
            $planMeasure = (new PlanMeasure())
                ->setMeasure($measure)
                ->setIsApplicable(true)
                ->setWillImplement($plannedFlags[$index] ?? ($index < $plannedPoints))
                ->setImplemented($implementedFlags[$index] ?? ($index < $implementedPoints));
            $plan->addPlanMeasure($planMeasure);
        }
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
