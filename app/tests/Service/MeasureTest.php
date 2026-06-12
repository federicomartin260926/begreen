<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\TripleBalanceAxis;
use PHPUnit\Framework\TestCase;

final class MeasureTest extends TestCase
{
    public function testDepartmentActionDisplayNameUsesDepartmentSpecificTextWhenAvailable(): void
    {
        $measure = (new Measure())
            ->setName('Medida base')
            ->setNameReview('Medida en pasado')
            ->setDepartmentActionText('Acción por departamento');

        self::assertSame('Acción por departamento', $measure->getDepartmentActionDisplayName());
    }

    public function testDepartmentActionDisplayNameFallsBackToCurrentMeasureText(): void
    {
        $measure = (new Measure())
            ->setName('Medida base')
            ->setNameReview('Medida en pasado');

        self::assertSame('Medida en pasado', $measure->getDepartmentActionDisplayName());
    }

    public function testSortOrderCanBeReadAndWritten(): void
    {
        $measure = (new Measure())
            ->setName('Medida base')
            ->setSortOrder(30);

        self::assertSame(30, $measure->getSortOrder());
    }

    public function testTripleBalanceAxesCanBeSetInBulk(): void
    {
        $axisEnvironmental = (new TripleBalanceAxis())->setCode('ambiental')->setName('Ambiental');
        $axisSocial = (new TripleBalanceAxis())->setCode('social')->setName('Social');

        $measure = (new Measure())
            ->setName('Medida base')
            ->setTripleBalanceAxes([$axisEnvironmental, $axisSocial]);

        self::assertCount(2, $measure->getTripleBalanceAxes());
        self::assertSame($axisEnvironmental, $measure->getTripleBalanceAxes()->first());
    }
}
