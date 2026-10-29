<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Service\PlanBlockQuestionService;
use App\Service\PlanMeasureGetOrCreate;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PlanBlockQuestionServiceTest extends TestCase
{
    public function testNoOnBlockMarksEligibleMeasuresAsBlockSkipAndKeepsManualNoIntact(): void
    {
        $plan = new Plan();
        $block = $this->createBlock(101, 'biodiversidad', 'Biodiversidad');
        $blockMeasures = [
            $this->createMeasure(201, 'Medida 1', $block),
            $this->createMeasure(202, 'Medida 2', $block),
        ];

        $planMeasures = [
            201 => (new PlanMeasure())
                ->setMeasure($blockMeasures[0])
                ->setIsApplicable(null)
                ->setApplicabilitySource('manual'),
            202 => (new PlanMeasure())
                ->setMeasure($blockMeasures[1])
                ->setIsApplicable(false)
                ->setApplicabilitySource('manual'),
        ];

        $getOrCreate = $this->getOrCreateReturning($planMeasures);

        $persistedAnswer = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedAnswer): void {
                self::assertInstanceOf(SustainabilityPlanBlockAnswer::class, $entity);
                $persistedAnswer = $entity;
            });

        $service = new PlanBlockQuestionService($getOrCreate, $entityManager);
        $service->applyAnswer($plan, $block, false, null, $blockMeasures);

        self::assertCount(1, $plan->getBlockAnswers());
        self::assertSame($persistedAnswer, $plan->getBlockAnswers()->first());

        self::assertFalse($planMeasures[201]->isApplicable());
        self::assertSame('block_skip', $planMeasures[201]->getApplicabilitySource());
        self::assertSame($persistedAnswer, $planMeasures[201]->getBlockSkipAnswer());

        self::assertFalse($planMeasures[202]->isApplicable());
        self::assertSame('manual', $planMeasures[202]->getApplicabilitySource());
        self::assertNull($planMeasures[202]->getBlockSkipAnswer());
    }

    public function testYesRestoresOnlyMeasuresSkippedBySameBlockAnswer(): void
    {
        $plan = new Plan();
        $block = $this->createBlock(101, 'biodiversidad', 'Biodiversidad');
        $blockMeasures = [
            $this->createMeasure(201, 'Medida 1', $block),
            $this->createMeasure(202, 'Medida 2', $block),
            $this->createMeasure(203, 'Medida 3', $block),
        ];

        $blockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($block)
            ->setApplies(false)
            ->setAnsweredAt(new \DateTimeImmutable());
        $this->setEntityId($blockAnswer, 301);
        $plan->addBlockAnswer($blockAnswer);

        $otherBlockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($block)
            ->setApplies(false)
            ->setAnsweredAt(new \DateTimeImmutable());
        $this->setEntityId($otherBlockAnswer, 302);

        $planMeasures = [
            201 => (new PlanMeasure())
                ->setMeasure($blockMeasures[0])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip')
                ->setBlockSkipAnswer($blockAnswer)
                ->setWillImplement(true)
                ->setImplemented(true)
                ->setIsCritical(true)
                ->setObservations('Observación'),
            202 => (new PlanMeasure())
                ->setMeasure($blockMeasures[1])
                ->setIsApplicable(false)
                ->setApplicabilitySource('manual')
                ->setWillImplement(null)
                ->setImplemented(null)
                ->setIsCritical(null)
                ->setObservations(null),
            203 => (new PlanMeasure())
                ->setMeasure($blockMeasures[2])
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip')
                ->setBlockSkipAnswer($otherBlockAnswer)
                ->setWillImplement(true)
                ->setImplemented(true)
                ->setIsCritical(true)
                ->setObservations('Otra observación'),
        ];

        $getOrCreate = $this->getOrCreateReturning($planMeasures);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new PlanBlockQuestionService($getOrCreate, $entityManager);
        $service->applyAnswer($plan, $block, true, null, $blockMeasures);

        self::assertSame(true, $blockAnswer->applies());
        self::assertSame('manual', $planMeasures[201]->getApplicabilitySource());
        self::assertNull($planMeasures[201]->getBlockSkipAnswer());
        self::assertNull($planMeasures[201]->isApplicable());
        self::assertNull($planMeasures[201]->willImplement());
        self::assertNull($planMeasures[201]->isImplemented());
        self::assertNull($planMeasures[201]->isCritical());
        self::assertNull($planMeasures[201]->getObservations());

        self::assertFalse($planMeasures[202]->isApplicable());
        self::assertSame('manual', $planMeasures[202]->getApplicabilitySource());
        self::assertNull($planMeasures[202]->getBlockSkipAnswer());

        self::assertFalse($planMeasures[203]->isApplicable());
        self::assertSame('block_skip', $planMeasures[203]->getApplicabilitySource());
        self::assertSame($otherBlockAnswer, $planMeasures[203]->getBlockSkipAnswer());
    }

    public function testReusesUnflushedRelationCreatedBeforeBlockQuestion(): void
    {
        $plan = new Plan();
        $block = $this->createBlock(101, 'biodiversidad', 'Biodiversidad');
        $measure = $this->createMeasure(201, 'Medida 1', $block);

        // Mirrors PlanController::updateSelection before its first flush.
        $pending = (new PlanMeasure())->setMeasure($measure);
        $plan->addPlanMeasure($pending);

        $getOrCreate = $this->getOrCreateReturning([201 => $pending]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')
            ->with(self::isInstanceOf(SustainabilityPlanBlockAnswer::class));

        (new PlanBlockQuestionService($getOrCreate, $em))
            ->applyAnswer($plan, $block, false, null, [$measure]);

        self::assertCount(1, $plan->getPlanMeasures());
        self::assertSame($pending, $plan->getPlanMeasures()->first());
        self::assertFalse($pending->isApplicable());
        self::assertSame('block_skip', $pending->getApplicabilitySource());
    }

    public function testRepeatedMeasureInBlockInputIsCreatedOnlyOnce(): void
    {
        $plan = new Plan();
        $block = $this->createBlock(101, 'biodiversidad', 'Biodiversidad');
        $measure = $this->createMeasure(201, 'Medida 1', $block);

        $getOrCreate = $this->createMock(PlanMeasureGetOrCreate::class);
        $getOrCreate->expects(self::once())->method('getOrCreateMany')
            ->willReturnCallback(static function (Plan $plan, iterable $measures): array {
                $measure = array_values(is_array($measures) ? $measures : iterator_to_array($measures))[0];
                $item = (new PlanMeasure())->setMeasure($measure);
                $plan->addPlanMeasure($item);

                return [$measure->getId() => $item];
            });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist'); // block answer; relation is owned by get-or-create

        (new PlanBlockQuestionService($getOrCreate, $em))
            ->applyAnswer($plan, $block, false, null, [$measure, $measure]);

        self::assertCount(1, $plan->getPlanMeasures());
        self::assertFalse($plan->getPlanMeasures()->first()->isApplicable());
    }

    /** @param array<int, PlanMeasure> $relations */
    private function getOrCreateReturning(array $relations): PlanMeasureGetOrCreate
    {
        $service = $this->createMock(PlanMeasureGetOrCreate::class);
        $service->expects(self::once())
            ->method('getOrCreateMany')
            ->willReturn($relations);

        return $service;
    }

    private function createBlock(int $id, string $code, string $name): MeasureBlock
    {
        $protocol = (new Protocol())
            ->setCode('bgmf')
            ->setName('Be Green My Film');
        $this->setEntityId($protocol, 100);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode($code)
            ->setName($name);
        $this->setEntityId($block, $id);

        return $block;
    }

    private function createMeasure(int $id, string $name, MeasureBlock $block): Measure
    {
        $measure = (new Measure())
            ->setName($name)
            ->setMeasureBlock($block);
        $this->setEntityId($measure, $id);

        return $measure;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
