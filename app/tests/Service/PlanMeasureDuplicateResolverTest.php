<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\PlanMeasure;
use App\Service\PlanMeasureDuplicateResolver;
use PHPUnit\Framework\TestCase;

final class PlanMeasureDuplicateResolverTest extends TestCase
{
    public function testSelectsTheAnsweredEvidenceRelationRegardlessOfOrder(): void
    {
        $measure = new Measure();
        $answered = (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setImplemented(false)
            ->setObservations('Justificación de prueba')
            ->setEvidence('/uploads/evidences/ejemplo.pdf');
        $empty = (new PlanMeasure())->setMeasure($measure);

        foreach ([[$answered, $empty], [$empty, $answered]] as $order) {
            $chosen = PlanMeasureDuplicateResolver::unique($order);
            self::assertCount(1, $chosen);
            self::assertSame($answered, $chosen[0]);
            self::assertTrue($chosen[0]->isApplicable());
            self::assertSame('/uploads/evidences/ejemplo.pdf', $chosen[0]->getEvidence());
        }
    }

    public function testForMeasurePrefersAnsweredDuplicateRegardlessOfOrder(): void
    {
        $measure = new Measure();
        $answered = (new PlanMeasure())->setMeasure($measure)->setIsApplicable(false)
            ->setObservations('Respuesta conservada');
        $empty = (new PlanMeasure())->setMeasure($measure);

        foreach ([[$answered, $empty], [$empty, $answered]] as $order) {
            $plan = new \App\Entity\Plan();
            foreach ($order as $relation) {
                // One PlanMeasure can belong to only one plan; this loop uses a new plan
                // for each ordering and the entity back-reference follows addPlanMeasure.
                $plan->addPlanMeasure($relation);
            }
            self::assertSame($answered, \App\Service\PlanMeasureDuplicateResolver::forMeasure($plan, $measure));
        }
    }

    public function testForMeasureReturnsPendingRelationAndNullForUnrelatedMeasure(): void
    {
        $plan = new \App\Entity\Plan();
        $measure = new Measure();
        $other = new Measure();
        $pending = (new PlanMeasure())->setMeasure($measure);
        $plan->addPlanMeasure($pending);

        self::assertSame($pending, \App\Service\PlanMeasureDuplicateResolver::forMeasure($plan, $measure));
        self::assertNull(\App\Service\PlanMeasureDuplicateResolver::forMeasure($plan, $other));
    }

    public function testRetainsSeparateMeasuresAndValidFalseAnswers(): void
    {
        $one = new Measure();
        $two = new Measure();
        $answeredNo = (new PlanMeasure())->setMeasure($one)->setIsApplicable(false);
        $emptyDuplicate = (new PlanMeasure())->setMeasure($one);
        $other = (new PlanMeasure())->setMeasure($two)->setIsApplicable(true);

        $result = PlanMeasureDuplicateResolver::unique([$emptyDuplicate, $other, $answeredNo]);
        self::assertCount(2, $result);
        self::assertContains($answeredNo, $result);
        self::assertContains($other, $result);
    }
}
