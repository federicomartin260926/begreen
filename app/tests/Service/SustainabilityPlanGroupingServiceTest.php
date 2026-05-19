<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Department;
use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Ods;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Entity\TripleBalanceAxis;
use App\Repository\ProjectSubscriptionRepository;
use App\Service\MeasureTaxonomyPresenter;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Service\SustainabilityPlanCustomMeasureParser;
use App\Service\SustainabilityPlanGroupingService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SustainabilityPlanGroupingServiceTest extends TestCase
{
    public function testGroupsByDepartmentAndDeduplicatesMeasuresWithinTheSameGroup(): void
    {
        $service = $this->createService();
        [$plan, $project, $protocol] = $this->createPlanContext(ProjectSubscription::TIER_PRO);

        $deptA = $this->createDepartment(11, 'prod', 'Producción');
        $deptB = $this->createDepartment(12, 'post', 'Postproducción');

        $measureA = $this->createCanonicalMeasure(101, 'Medida A', 5, $protocol);
        $measureA->addDepartment($deptA);
        $measureA->addDepartment($deptB);
        $plan->addPlanMeasure($this->createPlanMeasure($measureA));

        $measureB = $this->createCanonicalMeasure(102, 'Medida B', 4, $protocol);
        $measureB->addDepartment($deptA);
        $plan->addPlanMeasure($this->createPlanMeasure($measureB));

        $groups = $service->groupPlanMeasures($plan, $project, 'department');

        self::assertCount(2, $groups);
        $productionGroup = $this->findGroup($groups, 'Producción');
        $postGroup = $this->findGroup($groups, 'Postproducción');

        self::assertSame(['Medida A', 'Medida B'], array_column($productionGroup['rows'], 'displayName'));
        self::assertSame(['Medida A'], array_column($postGroup['rows'], 'displayName'));
    }

    public function testGroupsByOdsAndAllowsMultipleGroupsForTheSameMeasure(): void
    {
        $service = $this->createService();
        [$plan, $project, $protocol] = $this->createPlanContext(ProjectSubscription::TIER_PRO);

        $ods12 = $this->createOds(21, '12', 'ODS 12');
        $ods13 = $this->createOds(22, '13', 'ODS 13');

        $measure = $this->createCanonicalMeasure(201, 'Medida ODS', 5, $protocol);
        $measure->addOdsItem($ods12);
        $measure->addOdsItem($ods13);
        $plan->addPlanMeasure($this->createPlanMeasure($measure));

        $groups = $service->groupPlanMeasures($plan, $project, 'ods');

        self::assertCount(2, $groups);
        self::assertSame(['Medida ODS'], array_column($this->findGroup($groups, '12')['rows'], 'displayName'));
        self::assertSame(['Medida ODS'], array_column($this->findGroup($groups, '13')['rows'], 'displayName'));
    }

    public function testBasicTierFiltersOutNonAllowedScores(): void
    {
        $service = $this->createService();
        [$plan, $project, $protocol] = $this->createPlanContext(ProjectSubscription::TIER_BASIC);

        $allowed = $this->createCanonicalMeasure(301, 'Puntuación 5', 5, $protocol);
        $allowed->addDepartment($this->createDepartment(31, 'prod', 'Producción'));
        $plan->addPlanMeasure($this->createPlanMeasure($allowed));

        $blocked = $this->createCanonicalMeasure(302, 'Puntuación 2', 2, $protocol);
        $blocked->addDepartment($this->createDepartment(32, 'post', 'Postproducción'));
        $plan->addPlanMeasure($this->createPlanMeasure($blocked));

        $groups = $service->groupPlanMeasures($plan, $project, 'department');

        self::assertCount(1, $groups);
        self::assertSame(['Puntuación 5'], array_column($groups[0]['rows'], 'displayName'));
    }

    private function createService(): SustainabilityPlanGroupingService
    {
        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProject')->willReturn(null);

        $gate = new ProjectFeatureGate($subscriptionRepository);
        $resolver = new PlanMeasureCatalogResolver($gate);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => $id);

        return new SustainabilityPlanGroupingService(
            $resolver,
            new MeasureTaxonomyPresenter(),
            $translator,
            new SustainabilityPlanCustomMeasureParser()
        );
    }

    /**
     * @return array{0: Plan, 1: Project, 2: Protocol}
     */
    private function createPlanContext(string $tier): array
    {
        $protocol = $this->createCanonicalProtocol(1001);

        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setTier($tier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);
        $project->setSubscription($subscription);

        $plan = (new Plan())
            ->setProject($project)
            ->setProtocol($protocol);

        return [$plan, $project, $protocol];
    }

    private function createCanonicalMeasure(int $id, string $name, int $score, Protocol $protocol): Measure
    {
        $measure = new Measure();
        $this->setEntityId($measure, $id);

        $category = (new Category())->setName('Categoría');
        $this->setEntityId($category, 2001);

        $block = (new MeasureBlock())->setCode('block-1')->setName('Bloque');
        $this->setEntityId($block, 2002);

        $measure
            ->setName($name)
            ->setCategory($category)
            ->setMeasureBlock($block)
            ->setProtocol($protocol)
            ->setImportVersion('v23')
            ->setScore($score);

        $measure
            ->addImpactArea($this->createImpactArea(4001, 'clima', 'Cambio Climático'))
            ->addTripleBalanceAxis($this->createTripleBalanceAxis(5001, 'ambiental', 'Ambiental'));

        $source = $this->createOds(6001, '12', 'ODS 12');
        $measure->addOdsItem($source);

        return $measure;
    }

    private function createCanonicalProtocol(int $id): Protocol
    {
        $protocol = (new Protocol())
            ->setCode('be-green-my-film')
            ->setName('Be Green My Film');
        $this->setEntityId($protocol, $id);

        return $protocol;
    }

    private function createPlanMeasure(Measure $measure): PlanMeasure
    {
        return (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setWillImplement(true);
    }

    private function createDepartment(int $id, string $code, string $name): Department
    {
        $department = (new Department())
            ->setCode($code)
            ->setName($name);
        $this->setEntityId($department, $id);

        return $department;
    }

    private function createOds(int $id, string $code, string $name): Ods
    {
        $ods = (new Ods())
            ->setCode($code)
            ->setName($name);
        $this->setEntityId($ods, $id);

        return $ods;
    }

    private function createImpactArea(int $id, string $code, string $name): ImpactArea
    {
        $impactArea = (new ImpactArea())
            ->setCode($code)
            ->setName($name);
        $this->setEntityId($impactArea, $id);

        return $impactArea;
    }

    private function createTripleBalanceAxis(int $id, string $code, string $name): TripleBalanceAxis
    {
        $axis = (new TripleBalanceAxis())
            ->setCode($code)
            ->setName($name);
        $this->setEntityId($axis, $id);

        return $axis;
    }

    /**
     * @param array<int, array{label:string, rows:array<int, array<string, mixed>>}> $groups
     * @return array<string, mixed>
     */
    private function findGroup(array $groups, string $label): array
    {
        foreach ($groups as $group) {
            if (($group['label'] ?? null) === $label) {
                return $group;
            }
        }

        self::fail(sprintf('Group "%s" not found.', $label));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
