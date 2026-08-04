<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\PlanMeasure;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Entity\Protocol;
use App\Service\PlanMeasureResumeService;
use App\Service\PlanMeasureElaborationDecisionValidator;
use PHPUnit\Framework\TestCase;

final class PlanMeasureResumeServiceTest extends TestCase
{
    public function testResolveIndexReturnsFirstPendingMeasureAndIgnoresBlockSkippedMeasures(): void
    {
        $service = $this->createService();

        $block = $this->createBlock();
        $blockAnswer = $this->createBlockAnswer($block, false);
        $measures = [
            $this->createMeasure(),
            $this->createMeasure($block),
            $this->createMeasure($block),
            $this->createMeasure(),
        ];

        $planMeasures = [
            (new PlanMeasure())
                ->setMeasure($measures[0])
                ->setIsApplicable(true)
                ->setIsCritical(true)
                ->setWillImplement(true)
                ->setObservations(str_repeat('a', 50)),
            (new PlanMeasure())
                ->setMeasure($measures[1])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip')
                ->setBlockSkipAnswer($blockAnswer),
            (new PlanMeasure())
                ->setMeasure($measures[2])
                ->setIsApplicable(true)
                ->setIsCritical(null)
                ->setWillImplement(null),
        ];

        self::assertSame(2, $service->resolveIndex($measures, $planMeasures));
    }

    public function testResolveIndexSkipsApplicableMeasuresNotSelectedForImplementation(): void
    {
        $service = $this->createService();

        $measures = [
            $this->createMeasure(),
            $this->createMeasure(),
            $this->createMeasure(),
        ];

        $planMeasures = [
            (new PlanMeasure())
                ->setMeasure($measures[0])
                ->setIsApplicable(true)
                ->setWillImplement(false)
                ->setIsCritical(null)
                ->setObservations(str_repeat('a', 50))
                ->markAsManual(),
            (new PlanMeasure())
                ->setMeasure($measures[1])
                ->setIsApplicable(true)
                ->setWillImplement(true)
                ->setIsCritical(true)
                ->setObservations(str_repeat('b', 50))
                ->markAsManual(),
        ];

        self::assertSame(2, $service->resolveIndex($measures, $planMeasures));
    }

    public function testResolveIndexFallsBackToLastVisibleMeasureWhenEverythingIsAnswered(): void
    {
        $service = $this->createService();

        $block = $this->createBlock();
        $blockAnswer = $this->createBlockAnswer($block, false);
        $measures = [$this->createMeasure(), $this->createMeasure(), $this->createMeasure($block)];

        $planMeasures = [
            (new PlanMeasure())
                ->setMeasure($measures[0])
                ->setIsApplicable(true)
                ->setIsCritical(false)
                ->setWillImplement(true)
                ->setObservations(str_repeat('a', 50)),
            (new PlanMeasure())
                ->setMeasure($measures[1])
                ->setIsApplicable(false)
                ->setObservations(str_repeat('b', 50))
                ->setApplicabilitySource('manual'),
            (new PlanMeasure())
                ->setMeasure($measures[2])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip')
                ->setBlockSkipAnswer($blockAnswer),
        ];

        self::assertSame(2, $service->resolveIndex($measures, $planMeasures));
    }

    public function testResolveIndexReturnsStaleBlockSkippedMeasureWhenBlockWasReenabled(): void
    {
        $service = $this->createService();

        $block = $this->createBlock();
        $blockAnswer = $this->createBlockAnswer($block, true);
        $measures = [$this->createMeasure(), $this->createMeasure($block), $this->createMeasure()];

        $planMeasures = [
            (new PlanMeasure())
                ->setMeasure($measures[0])
                ->setIsApplicable(true)
                ->setIsCritical(false)
                ->setWillImplement(true)
                ->setObservations(str_repeat('a', 50)),
            (new PlanMeasure())
                ->setMeasure($measures[1])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip')
                ->setBlockSkipAnswer($blockAnswer),
            (new PlanMeasure())
                ->setMeasure($measures[2])
                ->setIsApplicable(true)
                ->setIsCritical(null)
                ->setWillImplement(null),
        ];

        self::assertSame(1, $service->resolveIndex($measures, $planMeasures));
    }

    private function createMeasure(?MeasureBlock $block = null): Measure
    {
        $measure = new Measure();
        if ($block instanceof MeasureBlock) {
            $measure->setMeasureBlock($block);
        }

        return $measure;
    }

    private function createService(): PlanMeasureResumeService
    {
        return new PlanMeasureResumeService(new PlanMeasureElaborationDecisionValidator());
    }

    private function createBlock(): MeasureBlock
    {
        $protocol = (new Protocol())
            ->setCode('bgmf')
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('block')
            ->setName('Block');
        $this->setEntityId($block, 301);

        return $block;
    }

    private function createBlockAnswer(MeasureBlock $block, bool $applies): SustainabilityPlanBlockAnswer
    {
        $answer = (new SustainabilityPlanBlockAnswer())
            ->setMeasureBlock($block)
            ->setApplies($applies)
            ->setAnsweredAt(new \DateTimeImmutable());

        $this->setEntityId($answer, $applies ? 401 : 402);

        return $answer;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
