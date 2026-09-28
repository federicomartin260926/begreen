<?php

namespace App\Tests\Service\Animation;

use App\Entity\PlanMeasure;
use App\Service\Animation\AnimationComplianceService;
use App\Service\PlanMeasureOperationalStateResolver;
use PHPUnit\Framework\TestCase;

final class AnimationComplianceServiceTest extends TestCase
{
    public function testComplianceUsesOnlyFinalEvaluationStatesAndExcludesNotApplicable(): void
    {
        $summary = $this->service()->summarize([
            $this->implemented(),
            $this->implemented(),
            $this->implemented(),
            $this->notImplemented(),
            (new PlanMeasure())->setIsApplicable(false),
            (new PlanMeasure())->setIsApplicable(false),
            $this->executable(),
            $this->executable()->setImplemented(true),
            (new PlanMeasure())->setIsApplicable(true)->setWillImplement(false),
        ]);

        self::assertSame(3, $summary->fulfilledCount);
        self::assertSame(1, $summary->notFulfilledCount);
        self::assertSame(2, $summary->notApplicableCount);
        self::assertSame(1, $summary->pendingCount);
        self::assertSame(1, $summary->inProgressCount);
        self::assertSame(1, $summary->discardedCount);
        self::assertSame(4, $summary->denominator);
        self::assertSame(75.0, $summary->percentage);
        self::assertTrue($summary->hasEvaluableMeasures());
    }

    public function testAllNotApplicableOrPendingHasNoEvaluablePercentage(): void
    {
        $summary = $this->service()->summarize([
            (new PlanMeasure())->setIsApplicable(false),
            (new PlanMeasure())->setIsApplicable(false),
            $this->executable(),
        ]);

        self::assertSame(0, $summary->denominator);
        self::assertNull($summary->percentage);
        self::assertFalse($summary->hasEvaluableMeasures());
    }

    public function testHistoricalMeasureOnlyParticipatesWhenItReturnsToCurrentEligibleInput(): void
    {
        $historical = $this->implemented();

        self::assertSame(0, $this->service()->summarize([])->fulfilledCount);
        self::assertSame(1, $this->service()->summarize([$historical])->fulfilledCount);
        self::assertTrue($historical->isImplemented());
    }

    private function service(): AnimationComplianceService
    {
        return new AnimationComplianceService(new PlanMeasureOperationalStateResolver());
    }

    private function executable(): PlanMeasure
    {
        return (new PlanMeasure())->setIsApplicable(true)->setWillImplement(true);
    }

    private function implemented(): PlanMeasure
    {
        return $this->executable()
            ->setImplemented(true)
            ->setActionTaken('Acción realizada y completada con todos los detalles necesarios.')
            ->setEvidence('/evidence.pdf');
    }

    private function notImplemented(): PlanMeasure
    {
        return $this->executable()->setImplemented(false)->setExecutionIncident('No se pudo ejecutar.');
    }
}
