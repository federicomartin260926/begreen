<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\TestCase;

final class CommercialPlanResolverTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testBasicPlanRules(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);

        self::assertSame(ProjectSubscription::TIER_BASIC, $resolver->getTierCode($project, CommercialPhase::ELABORATION));
        self::assertSame([4, 5], $resolver->getAllowedScores($project, CommercialPhase::ELABORATION));
        self::assertTrue($resolver->hasWatermark($project, CommercialPhase::ELABORATION));
        self::assertSame(10, $resolver->getMaxEvidenceCount($project, CommercialPhase::ELABORATION));
        self::assertFalse($resolver->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.department_pdf'));
        self::assertSame('Elaboración Basic', $resolver->getPlanLabel($project, CommercialPhase::ELABORATION));
    }

    public function testStandardPlanRules(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);

        self::assertSame(ProjectSubscription::TIER_STANDARD, $resolver->getTierCode($project, CommercialPhase::ELABORATION));
        self::assertSame([3, 4, 5], $resolver->getAllowedScores($project, CommercialPhase::ELABORATION));
        self::assertFalse($resolver->hasWatermark($project, CommercialPhase::ELABORATION));
        self::assertNull($resolver->getMaxEvidenceCount($project, CommercialPhase::ELABORATION));
        self::assertTrue($resolver->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.department_pdf'));
        self::assertFalse($resolver->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.excel'));
        self::assertSame('Elaboración Standard', $resolver->getPlanLabel($project, CommercialPhase::ELABORATION));
    }

    public function testProPlanRules(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);

        self::assertSame(ProjectSubscription::TIER_PRO, $resolver->getTierCode($project, CommercialPhase::ELABORATION));
        self::assertSame([1, 2, 3, 4, 5], $resolver->getAllowedScores($project, CommercialPhase::ELABORATION));
        self::assertFalse($resolver->hasWatermark($project, CommercialPhase::ELABORATION));
        self::assertNull($resolver->getMaxEvidenceCount($project, CommercialPhase::ELABORATION));
        self::assertTrue($resolver->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.export.excel'));
        self::assertTrue($resolver->canUseFeature($project, CommercialPhase::ELABORATION, 'sustainability_plan.branding'));
        self::assertSame('Elaboración Pro', $resolver->getPlanLabel($project, CommercialPhase::ELABORATION));
    }

    public function testUnknownPlanCodeFailsWithConfigurationError(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Missing active commercial plan for phase "elaboration" and code "unknown-tier".');

        $resolver->getPlanByCode(CommercialPhase::ELABORATION, 'unknown-tier');
    }

    public function testMissingSubscriptionFailsWithConfigurationError(): void
    {
        $resolver = $this->makeCommercialPlanResolver($this->makeDefaultCommercialPlans());
        $project = new Project();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Missing project subscription for project "unpersisted" and phase "elaboration".');

        $resolver->getTierCode($project, CommercialPhase::ELABORATION);
    }
}
