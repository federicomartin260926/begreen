<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Measure;
use App\Entity\PlanMeasure;
use App\Service\PlanMeasureOperationalStateResolver;
use App\Service\SustainabilityPlanImplementationViewService;
use App\Service\SustainabilityPlanMeasureOrderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

final class SustainabilityPlanImplementationViewServiceTest extends TestCase
{
    public function testBuildGroupsVisibleMeasuresAndKeepsHistoricalStatesOutsideProgress(): void
    {
        $service = new SustainabilityPlanImplementationViewService(
            new PlanMeasureOperationalStateResolver(),
            new SustainabilityPlanMeasureOrderer(),
            new Translator('es'),
        );
        $implementation = (new Category())->setName('Implementación')->setSortOrder(1);
        $historical = (new Category())->setName('Históricas')->setSortOrder(2);
        $this->setId($implementation, 10);
        $this->setId($historical, 20);

        $pending = $this->planMeasure(1, $implementation, true, null);
        $inProgress = $this->planMeasure(2, $implementation, true, true)->setActionTaken('Acción');
        $implemented = $this->planMeasure(3, $implementation, true, true)
            ->setActionTaken('Acción')
            ->setEvidence('/evidence.pdf');
        $notImplemented = $this->planMeasure(4, $implementation, true, false)
            ->setExecutionIncident('Incidencia');
        $discarded = $this->planMeasure(5, $implementation, false, null);
        $notApplicable = $this->planMeasure(6, $historical, null, null)->setIsApplicable(false);
        $all = [$pending, $inProgress, $implemented, $notImplemented, $discarded, $notApplicable];

        $regularModel = $service->build($all, $all, PlanMeasureOperationalStateResolver::ALL);

        self::assertSame(4, $regularModel['visibleCount']);
        self::assertCount(1, $regularModel['groups']);
        self::assertSame(4, $regularModel['groups'][0]['totalComputable']);
        self::assertSame(2, $regularModel['groups'][0]['resolved']);
        self::assertSame(1, $regularModel['groups'][0]['pending']);
        self::assertSame(1, $regularModel['groups'][0]['inProgress']);
        self::assertSame(1, $regularModel['groups'][0]['implemented']);
        self::assertSame(1, $regularModel['groups'][0]['notImplemented']);
        self::assertSame(50, $regularModel['groups'][0]['progressPercentage']);
        self::assertFalse($regularModel['groups'][0]['completed']);

        $historicalModel = $service->build($all, [$discarded], PlanMeasureOperationalStateResolver::DISCARDED);

        self::assertSame(1, $historicalModel['visibleCount']);
        self::assertCount(1, $historicalModel['groups']);
        self::assertTrue($historicalModel['groups'][0]['outsideProgress']);
        self::assertSame(PlanMeasureOperationalStateResolver::DISCARDED, $historicalModel['groups'][0]['items'][0]['operationalState']);
        self::assertSame(4, $historicalModel['groups'][0]['totalComputable']);
        self::assertSame(2, $historicalModel['groups'][0]['resolved']);
        self::assertSame(50, $historicalModel['groups'][0]['progressPercentage']);
    }

    public function testBuildMarksFullyResolvedCategoryAsCompleted(): void
    {
        $category = (new Category())->setName('Completada')->setSortOrder(1);
        $this->setId($category, 10);
        $implemented = $this->planMeasure(1, $category, true, true)
            ->setActionTaken('Acción')
            ->setEvidence('/evidence.pdf');
        $notImplemented = $this->planMeasure(2, $category, true, false)
            ->setExecutionIncident('Incidencia');

        $model = $this->service()->build(
            [$implemented, $notImplemented],
            [$implemented, $notImplemented],
            PlanMeasureOperationalStateResolver::ALL,
        );

        $group = $model['groups'][0];
        self::assertSame(2, $group['totalComputable']);
        self::assertSame(1, $group['implemented']);
        self::assertSame(1, $group['notImplemented']);
        self::assertSame(2, $group['resolved']);
        self::assertSame(100, $group['progressPercentage']);
        self::assertTrue($group['completed']);
    }

    public function testBuildOrdersCategoriesAndResolvesOpenContext(): void
    {
        $first = (new Category())->setName('Primera')->setSortOrder(1);
        $second = (new Category())->setName('Segunda')->setSortOrder(2);
        $unordered = (new Category())->setName('Sin orden')->setSortOrder(0);
        $this->setId($first, 10);
        $this->setId($second, 20);
        $this->setId($unordered, 30);

        $firstMeasure = $this->implementedPlanMeasure(1, $first);
        $secondMeasure = $this->implementedPlanMeasure(2, $second);
        $unorderedMeasure = $this->implementedPlanMeasure(3, $unordered);
        $uncategorizedMeasure = $this->implementedPlanMeasure(4, null);
        $all = [$firstMeasure, $secondMeasure, $unorderedMeasure, $uncategorizedMeasure];

        $byMeasure = $this->service()->build($all, $all, PlanMeasureOperationalStateResolver::ALL, 2);
        self::assertSame(['10', '20', '30', SustainabilityPlanImplementationViewService::UNCATEGORIZED_KEY], array_column($byMeasure['groups'], 'key'));
        self::assertTrue($this->groupByKey($byMeasure['groups'], '20')['isOpen']);

        $byCategory = $this->service()->build($all, $all, PlanMeasureOperationalStateResolver::ALL, null, '30');
        self::assertTrue($this->groupByKey($byCategory['groups'], '30')['isOpen']);

        $singleCategory = $this->service()->build([$firstMeasure], [$firstMeasure], PlanMeasureOperationalStateResolver::ALL);
        self::assertTrue($singleCategory['groups'][0]['isOpen']);
    }

    public function testBuildFiltersImplementedMeasuresWithoutChangingCategoryProgress(): void
    {
        $category = (new Category())->setName('Implementación')->setSortOrder(1);
        $this->setId($category, 10);
        $pending = $this->planMeasure(1, $category, true, null);
        $implemented = $this->planMeasure(2, $category, true, true)
            ->setActionTaken('Acción')
            ->setEvidence('/evidence.pdf');
        $notImplemented = $this->planMeasure(3, $category, true, false)
            ->setExecutionIncident('Incidencia');
        $all = [$pending, $implemented, $notImplemented];

        $model = $this->service()->build($all, $all, PlanMeasureOperationalStateResolver::IMPLEMENTED);

        self::assertSame(1, $model['visibleCount']);
        self::assertSame(PlanMeasureOperationalStateResolver::IMPLEMENTED, $model['groups'][0]['items'][0]['operationalState']);
        self::assertSame(3, $model['groups'][0]['totalComputable']);
        self::assertSame(2, $model['groups'][0]['resolved']);
        self::assertSame(67, $model['groups'][0]['progressPercentage']);
    }

    private function service(): SustainabilityPlanImplementationViewService
    {
        return new SustainabilityPlanImplementationViewService(
            new PlanMeasureOperationalStateResolver(),
            new SustainabilityPlanMeasureOrderer(),
            new Translator('es'),
        );
    }

    private function implementedPlanMeasure(int $id, ?Category $category): PlanMeasure
    {
        return $this->planMeasure($id, $category, true, true)
            ->setActionTaken('Acción')
            ->setEvidence('/evidence.pdf');
    }

    /** @param list<array<string, mixed>> $groups */
    private function groupByKey(array $groups, string $key): array
    {
        foreach ($groups as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        self::fail(sprintf('No se encontró la categoría %s.', $key));
    }

    private function planMeasure(int $id, ?Category $category, ?bool $willImplement, ?bool $implemented): PlanMeasure
    {
        $measure = (new Measure())->setName('Medida '.$id)->setCategory($category)->setSortOrder($id);
        $this->setId($measure, $id);

        return (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setWillImplement($willImplement)
            ->setImplemented($implemented);
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
