<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\PlanMeasure;
use App\Service\PlanMeasureResumeService;
use PHPUnit\Framework\TestCase;

final class PlanMeasureResumeServiceTest extends TestCase
{
    public function testResolveIndexReturnsFirstPendingMeasureAndIgnoresBlockSkippedMeasures(): void
    {
        $service = new PlanMeasureResumeService();

        $measures = [$this->createMeasure(), $this->createMeasure(), $this->createMeasure(), $this->createMeasure()];

        $planMeasures = [
            (new PlanMeasure())
                ->setMeasure($measures[0])
                ->setIsApplicable(true)
                ->setIsCritical(true)
                ->setWillImplement(true),
            (new PlanMeasure())
                ->setMeasure($measures[1])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip'),
            (new PlanMeasure())
                ->setMeasure($measures[2])
                ->setIsApplicable(true)
                ->setIsCritical(null)
                ->setWillImplement(null),
        ];

        self::assertSame(2, $service->resolveIndex($measures, $planMeasures));
    }

    public function testResolveIndexFallsBackToLastVisibleMeasureWhenEverythingIsAnswered(): void
    {
        $service = new PlanMeasureResumeService();

        $measures = [$this->createMeasure(), $this->createMeasure(), $this->createMeasure()];

        $planMeasures = [
            (new PlanMeasure())
                ->setMeasure($measures[0])
                ->setIsApplicable(true)
                ->setIsCritical(false)
                ->setWillImplement(true),
            (new PlanMeasure())
                ->setMeasure($measures[1])
                ->setIsApplicable(false)
                ->setApplicabilitySource('manual'),
            (new PlanMeasure())
                ->setMeasure($measures[2])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip'),
        ];

        self::assertSame(2, $service->resolveIndex($measures, $planMeasures));
    }

    private function createMeasure(): Measure
    {
        return new Measure();
    }
}
