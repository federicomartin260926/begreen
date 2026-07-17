<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\TestCase;

final class ProjectFeatureGateTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testBasicTierRules(): void
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_BASIC);
        $basicDefinition = $this->defaultCommercialPlanDefinition('basic');

        self::assertSame([4, 5], $gate->getAllowedScores($project, CommercialPhase::ELABORATION));
        self::assertSame('Elaboración Basic', $gate->getPlanLabel($project, CommercialPhase::ELABORATION));
        self::assertSame($basicDefinition['description'], $gate->getPlanDescription($project, CommercialPhase::ELABORATION));
        self::assertTrue($gate->hasWatermark($project, CommercialPhase::ELABORATION));
        self::assertSame(10, $gate->getMaxEvidenceCount($project, CommercialPhase::ELABORATION));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.excel'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.public_comments'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.internal_notes'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.responsibles'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.custom_measures'));
    }

    public function testBasicTierCanEnableCustomMeasuresManually(): void
    {
        $basicPlan = $this->makeCommercialPlan('basic', [
            'features' => array_replace(
                $this->defaultCommercialPlanDefinition('basic')['features'],
                [
                    'sustainability_plan.custom_measures' => true,
                ]
            ),
        ]);

        $gate = $this->makeProjectFeatureGate([
            $basicPlan,
            $this->makeCommercialPlan('standard'),
            $this->makeCommercialPlan('pro'),
        ]);
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_BASIC);

        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.custom_measures'));
    }

    public function testStandardTierRules(): void
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_STANDARD);
        $standardDefinition = $this->defaultCommercialPlanDefinition('standard');

        self::assertSame([3, 4, 5], $gate->getAllowedScores($project, CommercialPhase::ELABORATION));
        self::assertSame('Elaboración Standard', $gate->getPlanLabel($project, CommercialPhase::ELABORATION));
        self::assertSame($standardDefinition['description'], $gate->getPlanDescription($project, CommercialPhase::ELABORATION));
        self::assertFalse($gate->hasWatermark($project, CommercialPhase::ELABORATION));
        self::assertNull($gate->getMaxEvidenceCount($project, CommercialPhase::ELABORATION));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.advanced_exports'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.category'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.excel'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.public_comments'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.custom_measures'));
    }

    public function testProTierRules(): void
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_PRO);
        $proDefinition = $this->defaultCommercialPlanDefinition('pro');

        self::assertSame([1, 2, 3, 4, 5], $gate->getAllowedScores($project, CommercialPhase::ELABORATION));
        self::assertSame('Elaboración Pro', $gate->getPlanLabel($project, CommercialPhase::ELABORATION));
        self::assertSame($proDefinition['description'], $gate->getPlanDescription($project, CommercialPhase::ELABORATION));
        self::assertFalse($gate->hasWatermark($project, CommercialPhase::ELABORATION));
        self::assertNull($gate->getMaxEvidenceCount($project, CommercialPhase::ELABORATION));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.branding'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.category'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.department'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.excel'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.public_comments'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.internal_notes'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.responsibles'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.custom_measures'));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.validation_summary'));
    }

    public function testPhaseTiersAreIndependent(): void
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $project = $this->makeProjectWithTiers(ProjectSubscription::TIER_PRO, ProjectSubscription::TIER_BASIC);

        self::assertSame(ProjectSubscription::TIER_PRO, $gate->getTier($project, CommercialPhase::ELABORATION));
        self::assertSame([1, 2, 3, 4, 5], $gate->getAllowedScores($project, CommercialPhase::ELABORATION));
        self::assertTrue($gate->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.custom_measures'));

        self::assertSame(ProjectSubscription::TIER_BASIC, $gate->getTier($project, CommercialPhase::IMPLEMENTATION));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::IMPLEMENTATION, 'sustainability_plan.checklist'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::IMPLEMENTATION, 'sustainability_plan.responsibles'));
        self::assertFalse($gate->canUseFeature($project, CommercialPhase::IMPLEMENTATION, 'sustainability_plan.internal_notes'));
        self::assertSame(10, $gate->getMaxEvidenceCount($project, CommercialPhase::IMPLEMENTATION));
        self::assertTrue($gate->hasWatermark($project, CommercialPhase::IMPLEMENTATION));
    }

    private function createProjectWithTier(string $tier): Project
    {
        $project = new Project();
        foreach ([
            [CommercialPhase::ELABORATION, $tier],
            [CommercialPhase::IMPLEMENTATION, $tier],
        ] as [$phase, $phaseTier]) {
            $subscription = (new ProjectSubscription())
                ->setPhase($phase)
                ->setTier($phaseTier)
                ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                ->setSource(ProjectSubscription::SOURCE_MANUAL);

            $project->addSubscription($subscription);
        }

        return $project;
    }
}
