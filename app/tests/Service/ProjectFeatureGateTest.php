<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
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

        self::assertSame([4, 5], $gate->getAllowedScores($project));
        self::assertSame('Basic', $gate->getPlanLabel($project));
        self::assertSame($basicDefinition['description'], $gate->getPlanDescription($project));
        self::assertTrue($gate->hasWatermark($project));
        self::assertSame(10, $gate->getMaxEvidenceCount($project));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.export.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.export.excel'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.public_comments'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.internal_notes'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.responsibles'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.custom_measures'));
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

        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.custom_measures'));
    }

    public function testStandardTierRules(): void
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_STANDARD);
        $standardDefinition = $this->defaultCommercialPlanDefinition('standard');

        self::assertSame([3, 4, 5], $gate->getAllowedScores($project));
        self::assertSame('Standard', $gate->getPlanLabel($project));
        self::assertSame($standardDefinition['description'], $gate->getPlanDescription($project));
        self::assertFalse($gate->hasWatermark($project));
        self::assertNull($gate->getMaxEvidenceCount($project));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.advanced_exports'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.export.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.export.category'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.export.excel'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.public_comments'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.custom_measures'));
    }

    public function testProTierRules(): void
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_PRO);
        $proDefinition = $this->defaultCommercialPlanDefinition('pro');

        self::assertSame([1, 2, 3, 4, 5], $gate->getAllowedScores($project));
        self::assertSame('Pro', $gate->getPlanLabel($project));
        self::assertSame($proDefinition['description'], $gate->getPlanDescription($project));
        self::assertFalse($gate->hasWatermark($project));
        self::assertNull($gate->getMaxEvidenceCount($project));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.branding'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.export.category'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.export.department'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.export.excel'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.public_comments'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.internal_notes'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.responsibles'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.custom_measures'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.validation_summary'));
    }

    public function testMissingSubscriptionDefaultsToBasic(): void
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $project = new Project();

        self::assertSame(ProjectSubscription::TIER_BASIC, $gate->getTier($project));
        self::assertSame('Basic', $gate->getPlanLabel($project));
    }

    private function createProjectWithTier(string $tier): Project
    {
        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setTier($tier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);

        $project->setSubscription($subscription);

        return $project;
    }
}
