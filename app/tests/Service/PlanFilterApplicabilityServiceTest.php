<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Service\MeasureApplicabilityRuleCatalog;
use App\Service\PlanFilterApplicabilityService;
use App\Service\PlanMeasureGetOrCreate;
use PHPUnit\Framework\TestCase;

final class PlanFilterApplicabilityServiceTest extends TestCase
{
    public function testFilterChangeIsReversibleAndPreservesExistingPlanMeasureData(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-film');
        $measure = (new Measure())->setProtocol($protocol)->setSourceRow(39)->setScore(5);
        $this->setId($measure, 37);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setIsCritical(true)
            ->setObservations(str_repeat('a', 50))
            ->setEvidence('proof.pdf');
        $plan = (new Plan())->setProtocol($protocol)->setFilterAnswers(['F05' => false]);
        $plan->addPlanMeasure($planMeasure);

        $resolver = $this->createMock(PlanMeasureGetOrCreate::class);
        $resolver->method('getOrCreateMany')->willReturn([37 => $planMeasure]);
        $service = new PlanFilterApplicabilityService(
            new MeasureApplicabilityRuleCatalog(dirname(__DIR__, 2)),
            $resolver,
        );

        $service->recalculate($plan, [$measure]);
        self::assertFalse($planMeasure->isApplicable());
        self::assertTrue($planMeasure->isProfileFiltered());
        self::assertTrue($planMeasure->willImplement());
        self::assertTrue($planMeasure->isCritical());
        self::assertSame(str_repeat('a', 50), $planMeasure->getObservations());
        self::assertSame('proof.pdf', $planMeasure->getEvidence());

        $plan->setFilterAnswers(['F05' => true]);
        $service->recalculate($plan, [$measure]);
        self::assertTrue($planMeasure->isApplicable());
        self::assertSame(PlanMeasure::APPLICABILITY_MANUAL, $planMeasure->getApplicabilitySource());
        self::assertSame('proof.pdf', $planMeasure->getEvidence());
    }

    public function testManualNotApplicableDecisionIsNotOverwrittenByProfileFilter(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-film');
        $measure = (new Measure())->setProtocol($protocol)->setSourceRow(39);
        $this->setId($measure, 37);
        $planMeasure = (new PlanMeasure())->setMeasure($measure)->setIsApplicable(false)->markAsManual();
        $plan = (new Plan())->setProtocol($protocol)->setFilterAnswers(['F05' => false]);
        $plan->addPlanMeasure($planMeasure);

        $resolver = $this->createMock(PlanMeasureGetOrCreate::class);
        $resolver->method('getOrCreateMany')->willReturn([37 => $planMeasure]);
        $service = new PlanFilterApplicabilityService(
            new MeasureApplicabilityRuleCatalog(dirname(__DIR__, 2)),
            $resolver,
        );

        $service->recalculate($plan, [$measure]);
        self::assertFalse($planMeasure->isApplicable());
        self::assertSame(PlanMeasure::APPLICABILITY_MANUAL, $planMeasure->getApplicabilitySource());
    }

    public function testChangedLinkedFilterReopensManualNaWithoutLosingEvidence(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-film');
        $measure = (new Measure())->setProtocol($protocol)->setSourceRow(77);
        $this->setId($measure, 75);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(false)
            ->setWillImplement(null)
            ->setObservations('Justificación anterior de N/A')
            ->setEvidence('justificacion.pdf');
        $plan = (new Plan())->setProtocol($protocol)->setFilterAnswers(['F09' => true]);
        $plan->addPlanMeasure($planMeasure);

        $resolver = $this->createMock(PlanMeasureGetOrCreate::class);
        $resolver->method('getOrCreateMany')->willReturn([]);
        $service = new PlanFilterApplicabilityService(
            new MeasureApplicabilityRuleCatalog(dirname(__DIR__, 2)),
            $resolver,
        );

        $service->recalculate($plan, [$measure], ['F01']);
        self::assertFalse($planMeasure->isApplicable(), 'Un cambio ajeno no reabre el N/A.');

        $service->recalculate($plan, [$measure], ['F09']);
        self::assertNull($planMeasure->isApplicable());
        self::assertSame(PlanMeasure::APPLICABILITY_MANUAL, $planMeasure->getApplicabilitySource());
        self::assertSame('Justificación anterior de N/A', $planMeasure->getObservations());
        self::assertSame('justificacion.pdf', $planMeasure->getEvidence());
    }

    public function testAnimationContextualFiltersAloneDoNotAutomaticallyExclude(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-animation');
        $measure = (new Measure())->setProtocol($protocol)->setCatalogId('ANI-U259');
        $plan = (new Plan())->setProtocol($protocol)->setFilterAnswers([
            'A03' => 'PROC_NIVEL_BASICO',
            'A05' => 'ESC_MICRO',
            'A06' => ['PROC_INFRA_EQUIPOS'],
        ]);

        $service = new PlanFilterApplicabilityService(
            new MeasureApplicabilityRuleCatalog(dirname(__DIR__, 2)),
            $this->createMock(PlanMeasureGetOrCreate::class),
        );
        self::assertSame([], $service->automaticExclusionCodes($plan, $measure));
    }

    public function testEventAndAnimationBooleanFiltersExcludeTheirDependentMeasures(): void
    {
        $resolver = $this->createMock(PlanMeasureGetOrCreate::class);
        $service = new PlanFilterApplicabilityService(
            new MeasureApplicabilityRuleCatalog(dirname(__DIR__, 2)),
            $resolver,
        );

        $eventProtocol = (new Protocol())->setCode('be-green-my-event');
        $eventMeasure = (new Measure())->setProtocol($eventProtocol)->setSourceRow(3);
        $eventPlan = (new Plan())->setProtocol($eventProtocol)->setFilterAnswers(['E09' => false]);
        self::assertSame(['E09'], $service->automaticExclusionCodes($eventPlan, $eventMeasure));

        $animationProtocol = (new Protocol())->setCode('be-green-my-animation');
        $animationMeasure = (new Measure())->setProtocol($animationProtocol)->setCatalogId('ANI-U230');
        $animationPlan = (new Plan())->setProtocol($animationProtocol)->setFilterAnswers(['A01' => false]);
        self::assertSame(['A01'], $service->automaticExclusionCodes($animationPlan, $animationMeasure));
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
