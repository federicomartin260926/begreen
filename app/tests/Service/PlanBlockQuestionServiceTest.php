<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Repository\PlanMeasureRepository;
use App\Service\PlanBlockQuestionService;
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

        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($planMeasures): ?PlanMeasure {
                return $planMeasures[$criteria['measure']->getId() ?? 0] ?? null;
            });

        $persistedAnswer = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedAnswer): void {
                self::assertInstanceOf(SustainabilityPlanBlockAnswer::class, $entity);
                $persistedAnswer = $entity;
            });

        $service = new PlanBlockQuestionService($planMeasureRepository, $entityManager);
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

        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($planMeasures): ?PlanMeasure {
                return $planMeasures[$criteria['measure']->getId() ?? 0] ?? null;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new PlanBlockQuestionService($planMeasureRepository, $entityManager);
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
