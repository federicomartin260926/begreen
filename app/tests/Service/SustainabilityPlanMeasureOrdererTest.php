<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Department;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Protocol;
use App\Service\SustainabilityPlanMeasureOrderer;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanMeasureOrdererTest extends TestCase
{
    public function testSortVisibleMeasuresRespectsCategoryGroupingCategoryThenBlockThenSourceRow(): void
    {
        [$energy, $accommodation, $production, $postproduction, $blockIntro, $blockActions, $measures] = $this->buildMeasures();

        $orderer = new SustainabilityPlanMeasureOrderer();
        $sorted = $orderer->sortVisibleMeasures($measures, Protocol::GROUP_BY_CATEGORY);

        self::assertSame([
            'Medida C',
            'Medida B',
            'Medida A',
            'Medida D',
        ], array_map(static fn (Measure $measure): string => (string) $measure->getName(), $sorted));
    }

    public function testSortVisibleMeasuresRespectsDepartmentGroupingDepartmentThenBlockThenSourceRow(): void
    {
        [$energy, $accommodation, $production, $postproduction, $blockIntro, $blockActions, $measures] = $this->buildMeasures();

        $orderer = new SustainabilityPlanMeasureOrderer();
        $sorted = $orderer->sortVisibleMeasures($measures, Protocol::GROUP_BY_DEPARTMENT);

        self::assertSame([
            'Medida B',
            'Medida A',
            'Medida C',
            'Medida D',
        ], array_map(static fn (Measure $measure): string => (string) $measure->getName(), $sorted));
    }

    /**
     * @return array{0:Category,1:Category,2:Department,3:Department,4:MeasureBlock,5:MeasureBlock,6:Measure[]}
     */
    private function buildMeasures(): array
    {
        $energy = (new Category())->setName('Energía')->setSortOrder(10);
        $accommodation = (new Category())->setName('Alojamientos')->setSortOrder(20);
        $production = (new Department())->setName('Producción')->setSortOrder(10);
        $postproduction = (new Department())->setName('Postproducción')->setSortOrder(20);

        $blockIntro = (new MeasureBlock())->setName('Bloque Intro')->setSortOrder(20);
        $blockActions = (new MeasureBlock())->setName('Bloque Acciones')->setSortOrder(10);

        $measures = [
            $this->createMeasure('Medida D', $accommodation, $postproduction, $blockIntro, 40, 0),
            $this->createMeasure('Medida A', $energy, $production, $blockIntro, 10, 50),
            $this->createMeasure('Medida C', $energy, $postproduction, $blockActions, 30, 0),
            $this->createMeasure('Medida B', $energy, $production, $blockIntro, 20, 5),
        ];

        return [$energy, $accommodation, $production, $postproduction, $blockIntro, $blockActions, $measures];
    }

    private function createMeasure(string $name, Category $category, Department $department, MeasureBlock $block, int $sourceRow, int $sortOrder): Measure
    {
        return (new Measure())
            ->setName($name)
            ->setCategory($category)
            ->setDepartment($department)
            ->setMeasureBlock($block)
            ->setSourceRow($sourceRow)
            ->setSortOrder($sortOrder);
    }
}
