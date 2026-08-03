<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Entity\ProjectSubscription;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\PlanMeasureOperationalStateResolver;
use App\Service\SustainabilityPlanImplementationPhaseService;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanImplementationPhaseServiceTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testPhaseUsesOperationalStatesAndNeverCompletesWithAnEmptyDenominator(): void
    {
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film');
        $plan = (new Plan())->setProject($project)->setProtocol($protocol);
        $service = new SustainabilityPlanImplementationPhaseService(
            new PlanMeasureCatalogResolver($this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())),
            new PlanMeasureOperationalStateResolver(),
        );

        self::assertSame(SustainabilityPlanImplementationPhaseService::NOT_STARTED, $service->resolve($plan, $project));

        $pending = $this->createPlanMeasure($protocol);
        $plan->addPlanMeasure($pending);
        self::assertSame(SustainabilityPlanImplementationPhaseService::NOT_STARTED, $service->resolve($plan, $project));

        $pending->setActionTaken('Actividad previa sin decisión');
        self::assertSame(SustainabilityPlanImplementationPhaseService::IN_PROGRESS, $service->resolve($plan, $project));

        $pending->setImplemented(true)->setEvidence('/uploads/evidences/test.pdf');
        $notImplemented = $this->createPlanMeasure($protocol)
            ->setImplemented(false)
            ->setExecutionIncident('No puede ejecutarse');
        $plan->addPlanMeasure($notImplemented);
        self::assertSame(SustainabilityPlanImplementationPhaseService::COMPLETED, $service->resolve($plan, $project));

        $plan->addPlanMeasure($this->createPlanMeasure($protocol));
        self::assertSame(SustainabilityPlanImplementationPhaseService::IN_PROGRESS, $service->resolve($plan, $project));

        $excludedPlan = (new Plan())->setProject($project)->setProtocol($protocol);
        $excludedPlan->addPlanMeasure($this->createPlanMeasure($protocol)->setWillImplement(false));
        $excludedPlan->addPlanMeasure($this->createPlanMeasure($protocol)->setIsApplicable(false)->setWillImplement(null));
        self::assertSame(SustainabilityPlanImplementationPhaseService::NOT_STARTED, $service->resolve($excludedPlan, $project));
    }

    private function createPlanMeasure(Protocol $protocol): PlanMeasure
    {
        $measure = (new Measure())
            ->setName('Medida')
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::CATALOG_IMPORT_VERSION)
            ->setScore(5);

        return (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setImplemented(null);
    }
}
