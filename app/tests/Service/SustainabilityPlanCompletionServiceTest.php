<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Repository\MeasureRepository;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Service\SustainabilityPlanCompletionService;
use App\Service\SustainabilityPlanMeasureOrderer;
use App\Tests\Support\CommercialPlanTestHelpers;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanCompletionServiceTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testBasicProjectCompletePlanStaysCompleteUntilNewMeasuresBecomeVisible(): void
    {
        $service = $this->createService([
            $basicMeasure = $this->createMeasure(101, 5),
            $standardMeasure = $this->createMeasure(102, 3),
        ]);

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $plan = $this->createPlan($project);
        $plan->addPlanMeasure($this->createPlanMeasure($basicMeasure, true, false, true));

        self::assertTrue($service->syncStatus($plan, $project));
        self::assertSame('completo', $plan->getStatus());

        $project->getSubscription()?->setTier(ProjectSubscription::TIER_STANDARD);

        self::assertFalse($service->syncStatus($plan, $project));
        self::assertSame('incompleto', $plan->getStatus());

        $pending = $service->findFirstPendingVisibleMeasure($plan, $project);
        self::assertNotNull($pending);
        self::assertSame($standardMeasure->getId(), $pending['measure']->getId());
    }

    public function testUpgradeDoesNotBreakPlanWhenNewVisibleMeasuresAreAlreadyComplete(): void
    {
        $service = $this->createService([
            $basicMeasure = $this->createMeasure(201, 5),
            $standardMeasure = $this->createMeasure(202, 3),
        ]);

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $plan = $this->createPlan($project);
        $plan->addPlanMeasure($this->createPlanMeasure($basicMeasure, true, false, true));
        $plan->addPlanMeasure($this->createPlanMeasure($standardMeasure, true, false, true));

        self::assertTrue($service->syncStatus($plan, $project));
        self::assertSame('completo', $plan->getStatus());

        $project->getSubscription()?->setTier(ProjectSubscription::TIER_STANDARD);

        self::assertTrue($service->syncStatus($plan, $project));
        self::assertSame('completo', $plan->getStatus());
        self::assertNull($service->findFirstPendingVisibleMeasure($plan, $project));
    }

    public function testStatusRemainsCompleteForReviewProjectsThatDoNotGainPendingMeasures(): void
    {
        $service = $this->createService([
            $basicMeasure = $this->createMeasure(301, 5),
        ]);

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $plan = $this->createPlan($project);
        $plan->addPlanMeasure($this->createPlanMeasure($basicMeasure, true, false, true));

        $plan->setStatus('completo');
        self::assertTrue($service->syncStatus($plan, $project));
        self::assertSame('completo', $plan->getStatus());
    }

    public function testGetPendingVisibleMeasuresReturnsOnlyPendingMeasuresAndSkipsBlockSkippedOnes(): void
    {
        $service = $this->createService([
            $completeMeasure = $this->createMeasure(401, 5, 'Medida A'),
            $pendingMeasure = $this->createMeasure(402, 3, 'Medida B'),
            $secondPendingMeasure = $this->createMeasure(404, 3, 'Medida C'),
            $skippedMeasure = $this->createMeasure(403, 3, 'Medida D', $this->createMeasureBlock(901)),
        ]);

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);
        $plan = $this->createPlan($project);

        $plan->addPlanMeasure($this->createPlanMeasure($completeMeasure, true, false, true));

        $pendingPlanMeasure = $this->createPlanMeasure($pendingMeasure, null, null, null);
        $plan->addPlanMeasure($pendingPlanMeasure);

        $secondPendingPlanMeasure = $this->createPlanMeasure($secondPendingMeasure, true, null, null);
        $plan->addPlanMeasure($secondPendingPlanMeasure);

        $blockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($skippedMeasure->getMeasureBlock())
            ->setApplies(false)
            ->setAnsweredAt(new \DateTimeImmutable());
        $this->setEntityId($blockAnswer, 801);
        $plan->addBlockAnswer($blockAnswer);

        $skippedPlanMeasure = $this->createPlanMeasure($skippedMeasure, null, null, null)
            ->markAsBlockSkipped($blockAnswer);
        $plan->addPlanMeasure($skippedPlanMeasure);

        $pending = $service->getPendingVisibleMeasures($plan, $project);

        self::assertCount(2, $pending);
        self::assertSame($pendingMeasure->getId(), $pending[0]['measure']->getId());
        self::assertSame('applicability_missing', $pending[0]['reason']);
        self::assertSame($secondPendingMeasure->getId(), $pending[1]['measure']->getId());
        self::assertSame('will_implement_missing', $pending[1]['reason']);
    }

    public function testGetPendingVisibleMeasuresTreatsStaleBlockSkipAsPending(): void
    {
        $service = $this->createService([
            $measure = $this->createMeasure(405, 5, 'Medida visible', $this->createMeasureBlock(902)),
        ]);

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);
        $plan = $this->createPlan($project);

        $currentBlockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($measure->getMeasureBlock())
            ->setApplies(true)
            ->setAnsweredAt(new \DateTimeImmutable());
        $this->setEntityId($currentBlockAnswer, 802);
        $plan->addBlockAnswer($currentBlockAnswer);

        $staleBlockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($measure->getMeasureBlock())
            ->setApplies(false)
            ->setAnsweredAt(new \DateTimeImmutable());
        $this->setEntityId($staleBlockAnswer, 803);

        $planMeasure = $this->createPlanMeasure($measure, null, null, null)
            ->markAsBlockSkipped($staleBlockAnswer);
        $plan->addPlanMeasure($planMeasure);

        $pending = $service->getPendingVisibleMeasures($plan, $project);

        self::assertCount(1, $pending);
        self::assertSame($measure->getId(), $pending[0]['measure']->getId());
        self::assertSame('stale_block_skip_visible', $pending[0]['reason']);
    }

    public function testApplicableMeasureNotSelectedForImplementationDoesNotRemainPending(): void
    {
        $service = $this->createService([
            $measure = $this->createMeasure(405, 5, 'Medida cerrada'),
        ]);

        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);
        $plan = $this->createPlan($project);
        $plan->addPlanMeasure($this->createPlanMeasure($measure, true, null, false));

        self::assertTrue($service->isComplete($plan, $project));
        self::assertSame('completo', $service->syncStatus($plan, $project) ? 'completo' : 'incompleto');
        self::assertSame([], $service->getPendingVisibleMeasures($plan, $project));
    }

    /**
     * @param Measure[] $measures
     */
    private function createService(array $measures): SustainabilityPlanCompletionService
    {
        return new SustainabilityPlanCompletionService(
            $this->createMeasureRepositoryMock($measures),
            new PlanMeasureCatalogResolver($this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())),
            new SustainabilityPlanMeasureOrderer(),
        );
    }

    /**
     * @param Measure[] $measures
     */
    private function createMeasureRepositoryMock(array $measures): MeasureRepository
    {
        $allowedScores = [];

        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturnCallback(static function () use (&$allowedScores, $measures): array {
            if ($allowedScores === []) {
                return $measures;
            }

            return array_values(array_filter(
                $measures,
                static fn (Measure $measure): bool => in_array((int) ($measure->getScore() ?? 0), $allowedScores, true)
            ));
        });

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['join', 'leftJoin', 'addSelect', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnCallback(
                static function (...$args) use (&$allowedScores, $qb) {
                    if (($args[0] ?? null) === 'catalogAllowedScores') {
                        $allowedScores = array_map('intval', (array) ($args[1] ?? []));
                    }

                    return $qb;
                }
            );
        }
        $qb->method('getQuery')->willReturn($query);

        $repository = $this->createMock(MeasureRepository::class);
        $repository->method('createQueryBuilder')->willReturn($qb);

        return $repository;
    }

    private function createPlan(Project $project): Plan
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        return (new Plan())
            ->setProject($project)
            ->setUser(new \App\Entity\User())
            ->setProtocol($protocol);
    }

    private function createMeasure(int $id, int $score, string $name = 'Medida', ?MeasureBlock $block = null): Measure
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore($score)
            ->setName($name);
        if ($block instanceof MeasureBlock) {
            $measure->setMeasureBlock($block);
        }
        $this->setEntityId($measure, $id);

        return $measure;
    }

    private function createMeasureBlock(int $id): MeasureBlock
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('block-' . $id)
            ->setName('Bloque ' . $id);
        $this->setEntityId($block, $id);

        return $block;
    }

    private function createPlanMeasure(Measure $measure, ?bool $applicable, ?bool $critical, ?bool $willImplement): PlanMeasure
    {
        return (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable($applicable)
            ->setIsCritical($critical)
            ->setWillImplement($willImplement)
            ->markAsManual();
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }
}
