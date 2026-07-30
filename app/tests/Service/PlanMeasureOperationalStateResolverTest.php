<?php

namespace App\Tests\Service;

use App\Entity\CrewMember;
use App\Entity\PlanMeasure;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Service\PlanMeasureOperationalStateResolver;
use PHPUnit\Framework\TestCase;

final class PlanMeasureOperationalStateResolverTest extends TestCase
{
    private PlanMeasureOperationalStateResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PlanMeasureOperationalStateResolver();
    }

    public function testResolvesPendingMeasureWithoutExecutionActivity(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::PENDING, $this->resolver->resolve($this->executableMeasure()));
    }

    public function testResolvesInProgressMeasureWithOnlyAction(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::IN_PROGRESS, $this->resolver->resolve($this->executableMeasure()->setActionTaken('Acción iniciada')));
    }

    public function testResolvesInProgressMeasureWithOnlyEvidence(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::IN_PROGRESS, $this->resolver->resolve($this->executableMeasure()->setEvidence('/uploads/evidences/evidence.pdf')));
    }

    public function testResolvesInProgressMeasureWithExecutionIncident(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::IN_PROGRESS, $this->resolver->resolve($this->executableMeasure()->setExecutionIncident('Incidencia de ejecución')));
    }

    public function testGeneralObservationsDoNotCountAsExecutionActivity(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::PENDING, $this->resolver->resolve($this->executableMeasure()->setObservations('Observación general')));
    }

    public function testResolvesInProgressMeasureWithInternalNote(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::IN_PROGRESS, $this->resolver->resolve($this->executableMeasure()->setInternalNotes('Nota interna')));
    }

    public function testResolvesInProgressMeasureWithResponsible(): void
    {
        $planMeasure = $this->executableMeasure()->addResponsibleCrewMember(new CrewMember());

        self::assertSame(PlanMeasureOperationalStateResolver::IN_PROGRESS, $this->resolver->resolve($planMeasure));
    }

    public function testResolvesInProgressMeasureWithVerification(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::IN_PROGRESS, $this->resolver->resolve($this->executableMeasure()->setVerification(true)));
    }

    public function testResolvesImplementedMeasure(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::IMPLEMENTED, $this->resolver->resolve($this->executableMeasure()->setImplemented(true)));
    }

    public function testResolvesDiscardedMeasure(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::DISCARDED, $this->resolver->resolve((new PlanMeasure())
            ->setIsApplicable(true)
            ->setWillImplement(false)));
    }

    public function testResolvesManualNotApplicableMeasure(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::NOT_APPLICABLE, $this->resolver->resolve((new PlanMeasure())
            ->setIsApplicable(false)
            ->markAsManual()));
    }

    public function testResolvesBlockSkippedMeasureAsNotApplicable(): void
    {
        self::assertSame(PlanMeasureOperationalStateResolver::NOT_APPLICABLE, $this->resolver->resolve((new PlanMeasure())
            ->setIsApplicable(false)
            ->markAsBlockSkipped(new SustainabilityPlanBlockAnswer())));
    }

    public function testExclusionsTakePriorityOverInconsistentExecutionData(): void
    {
        $notApplicable = $this->executableMeasure()->setIsApplicable(false)->setImplemented(true);
        $discarded = $this->executableMeasure()->setWillImplement(false)->setImplemented(true);

        self::assertSame(PlanMeasureOperationalStateResolver::NOT_APPLICABLE, $this->resolver->resolve($notApplicable));
        self::assertSame(PlanMeasureOperationalStateResolver::DISCARDED, $this->resolver->resolve($discarded));
    }

    private function executableMeasure(): PlanMeasure
    {
        return (new PlanMeasure())
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setImplemented(false);
    }
}
