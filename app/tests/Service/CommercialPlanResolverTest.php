<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Service\CommercialPlanResolver;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\TestCase;

final class CommercialPlanResolverTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testBasicPlanRulesAndFallbackForMissingSubscription(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());
        $project = new Project();

        self::assertSame(ProjectSubscription::TIER_BASIC, $resolver->getTierCode($project));
        self::assertSame([4, 5], $resolver->getAllowedScores($project));
        self::assertTrue($resolver->hasWatermark($project));
        self::assertSame(10, $resolver->getMaxEvidenceCount($project));
        self::assertFalse($resolver->canUseFeature($project, 'sustainability_plan.department_pdf'));
        self::assertSame('Basic', $resolver->getPlanLabel($project));
    }

    public function testStandardPlanRules(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);

        self::assertSame(ProjectSubscription::TIER_STANDARD, $resolver->getTierCode($project));
        self::assertSame([3, 4, 5], $resolver->getAllowedScores($project));
        self::assertFalse($resolver->hasWatermark($project));
        self::assertNull($resolver->getMaxEvidenceCount($project));
        self::assertTrue($resolver->canUseFeature($project, 'sustainability_plan.department_pdf'));
        self::assertFalse($resolver->canUseFeature($project, 'sustainability_plan.export.excel'));
        self::assertSame('Standard', $resolver->getPlanLabel($project));
    }

    public function testProPlanRules(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);

        self::assertSame(ProjectSubscription::TIER_PRO, $resolver->getTierCode($project));
        self::assertSame([1, 2, 3, 4, 5], $resolver->getAllowedScores($project));
        self::assertFalse($resolver->hasWatermark($project));
        self::assertNull($resolver->getMaxEvidenceCount($project));
        self::assertTrue($resolver->canUseFeature($project, 'sustainability_plan.export.excel'));
        self::assertTrue($resolver->canUseFeature($project, 'sustainability_plan.branding'));
        self::assertSame('Pro', $resolver->getPlanLabel($project));
    }

    public function testUnknownPlanCodeFallsBackToBasic(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());

        self::assertSame('basic', $resolver->getPlanByCode('unknown-tier')->getCode());
        self::assertSame([4, 5], $resolver->getPlanByCode('unknown-tier')->getAllowedScores());
    }
}
