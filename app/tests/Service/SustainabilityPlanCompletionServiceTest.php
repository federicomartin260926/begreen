<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
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

    private function createMeasure(int $id, int $score): Measure
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
            ->setScore($score);
        $this->setEntityId($measure, $id);

        return $measure;
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
