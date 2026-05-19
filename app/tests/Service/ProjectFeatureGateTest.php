<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\ProjectSubscriptionRepository;
use App\Service\ProjectFeatureGate;
use PHPUnit\Framework\TestCase;

final class ProjectFeatureGateTest extends TestCase
{
    public function testBasicTierRules(): void
    {
        $gate = $this->createGate();
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_BASIC);

        self::assertSame([5, 4], $gate->getAllowedScores($project));
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

    public function testStandardTierRules(): void
    {
        $gate = $this->createGate();
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_STANDARD);

        self::assertSame([5, 4, 3], $gate->getAllowedScores($project));
        self::assertFalse($gate->hasWatermark($project));
        self::assertNull($gate->getMaxEvidenceCount($project));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.advanced_exports'));
        self::assertTrue($gate->canUseFeature($project, 'sustainability_plan.export.department_pdf'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.export.category'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.export.excel'));
        self::assertFalse($gate->canUseFeature($project, 'sustainability_plan.public_comments'));
    }

    public function testProTierRules(): void
    {
        $gate = $this->createGate();
        $project = $this->createProjectWithTier(ProjectSubscription::TIER_PRO);

        self::assertSame([5, 4, 3, 2, 1], $gate->getAllowedScores($project));
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
        $gate = $this->createGate();
        $project = new Project();

        self::assertSame(ProjectSubscription::TIER_BASIC, $gate->getTier($project));
    }

    private function createGate(): ProjectFeatureGate
    {
        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProject')->willReturn(null);

        return new ProjectFeatureGate($subscriptionRepository);
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
