<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\SustainabilityPlanDiscardedMeasureService;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanDiscardedMeasureServiceTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testGetDiscardedMeasuresReturnsOnlyApplicableMeasuresNotSelectedForImplementation(): void
    {
        $service = $this->createService();
        $project = $this->makeProjectWithTier(\App\Entity\ProjectSubscription::TIER_PRO);
        $plan = $this->createPlan($project);

        $notImplemented = $this->createMeasure(1001, 5, 'Medida no');
        $notApplicable = $this->createMeasure(1002, 5, 'Medida na');
        $implemented = $this->createMeasure(1003, 5, 'Medida ok');
        $blockSkipped = $this->createMeasure(1004, 5, 'Medida bloqueada', $this->createBlock(2001));

        $plan->addPlanMeasure((new PlanMeasure())
            ->setMeasure($notImplemented)
            ->setIsApplicable(true)
            ->setWillImplement(false)
            ->markAsManual());

        $plan->addPlanMeasure((new PlanMeasure())
            ->setMeasure($notApplicable)
            ->setIsApplicable(false)
            ->markAsManual());

        $plan->addPlanMeasure((new PlanMeasure())
            ->setMeasure($implemented)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setIsCritical(false)
            ->markAsManual());

        $blockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($blockSkipped->getMeasureBlock())
            ->setApplies(false)
            ->setAnsweredAt(new \DateTimeImmutable());
        $this->setEntityId($blockAnswer, 3001);

        $plan->addPlanMeasure((new PlanMeasure())
            ->setMeasure($blockSkipped)
            ->setIsApplicable(false)
            ->markAsBlockSkipped($blockAnswer));
        $plan->addBlockAnswer($blockAnswer);

        $discarded = $service->getDiscardedMeasures($plan, $project);

        self::assertCount(1, $discarded);
        self::assertSame($notImplemented->getId(), $discarded[0]->getMeasure()?->getId());
    }

    public function testRecoverDiscardedMeasureRestoresOnlyDiscardedMeasures(): void
    {
        $service = $this->createService();
        $project = $this->makeProjectWithTier(\App\Entity\ProjectSubscription::TIER_PRO);
        $plan = $this->createPlan($project);

        $notImplemented = $this->createMeasure(1101, 5, 'Medida no');
        $notApplicable = $this->createMeasure(1102, 5, 'Medida na');
        $blockSkipped = $this->createMeasure(1103, 5, 'Medida bloqueada', $this->createBlock(2002));

        $planMeasureNo = (new PlanMeasure())
            ->setMeasure($notImplemented)
            ->setIsApplicable(true)
            ->setWillImplement(false)
            ->setObservations('Decisión descartada')
            ->markAsManual();
        $plan->addPlanMeasure($planMeasureNo);

        $planMeasureNa = (new PlanMeasure())
            ->setMeasure($notApplicable)
            ->setIsApplicable(false)
            ->markAsManual();
        $plan->addPlanMeasure($planMeasureNa);

        $blockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($blockSkipped->getMeasureBlock())
            ->setApplies(false)
            ->setAnsweredAt(new \DateTimeImmutable());
        $this->setEntityId($blockAnswer, 3002);
        $plan->addBlockAnswer($blockAnswer);

        $planMeasureBlockSkipped = (new PlanMeasure())
            ->setMeasure($blockSkipped)
            ->setIsApplicable(false)
            ->markAsBlockSkipped($blockAnswer);
        $plan->addPlanMeasure($planMeasureBlockSkipped);

        self::assertSame($planMeasureNo, $service->recoverDiscardedMeasure($plan, $project, $notImplemented->getId() ?? 0));
        self::assertTrue($planMeasureNo->isApplicable());
        self::assertTrue($planMeasureNo->willImplement());
        self::assertFalse($planMeasureNo->isCritical());
        self::assertSame('Decisión descartada', $planMeasureNo->getObservations());
        self::assertSame('manual', $planMeasureNo->getApplicabilitySource());

        self::assertNull($service->recoverDiscardedMeasure($plan, $project, $notApplicable->getId() ?? 0));
        self::assertNull($service->recoverDiscardedMeasure($plan, $project, $blockSkipped->getId() ?? 0));
    }

    private function createService(): SustainabilityPlanDiscardedMeasureService
    {
        return new SustainabilityPlanDiscardedMeasureService(
            new PlanMeasureCatalogResolver($this->makeProjectFeatureGate($this->makeDefaultCommercialPlans()))
        );
    }

    private function createPlan(Project $project): Plan
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        return (new Plan())
            ->setProtocol($protocol)
            ->setUser(new \App\Entity\User())
            ->setProject($project);
    }

    private function createMeasure(int $id, int $score, string $name, ?MeasureBlock $block = null): Measure
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore($score)
            ->setName($name);

        if ($block instanceof MeasureBlock) {
            $measure->setMeasureBlock($block);
        }

        $this->setEntityId($measure, $id);

        return $measure;
    }

    private function createBlock(int $id): MeasureBlock
    {
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('block-' . $id)
            ->setName('Bloque ' . $id);
        $this->setEntityId($block, $id);

        return $block;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
