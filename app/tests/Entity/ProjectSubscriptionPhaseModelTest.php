<?php

namespace App\Tests\Entity;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use PHPUnit\Framework\TestCase;

final class ProjectSubscriptionPhaseModelTest extends TestCase
{
    public function testProjectKeepsOneSubscriptionPerPhase(): void
    {
        $project = new Project();

        $elaboration = (new ProjectSubscription())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setTier(ProjectSubscription::TIER_PRO);
        $implementation = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_BASIC);

        $project->addSubscription($elaboration);
        $project->addSubscription($implementation);

        self::assertCount(2, $project->getSubscriptions());
        self::assertSame($elaboration, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION));
        self::assertSame($implementation, $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION));
        self::assertSame($elaboration, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION));
    }

    public function testProjectRejectsDuplicatePhaseSubscriptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $project = new Project();
        $project->addSubscription(
            (new ProjectSubscription())
                ->setPhase(CommercialPhase::ELABORATION)
                ->setTier(ProjectSubscription::TIER_BASIC)
        );
        $project->addSubscription(
            (new ProjectSubscription())
                ->setPhase(CommercialPhase::ELABORATION)
                ->setTier(ProjectSubscription::TIER_STANDARD)
        );
    }

    public function testTwoPhasesCanShareTheSameTier(): void
    {
        $project = new Project();

        $project->addSubscription(
            (new ProjectSubscription())
                ->setPhase(CommercialPhase::ELABORATION)
                ->setTier(ProjectSubscription::TIER_STANDARD)
        );
        $project->addSubscription(
            (new ProjectSubscription())
                ->setPhase(CommercialPhase::IMPLEMENTATION)
                ->setTier(ProjectSubscription::TIER_STANDARD)
        );

        self::assertSame(
            ProjectSubscription::TIER_STANDARD,
            $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier()
        );
        self::assertSame(
            ProjectSubscription::TIER_STANDARD,
            $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->getTier()
        );
    }

    public function testAddSubscriptionKeepsImplementationPhaseUntouched(): void
    {
        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_BASIC);

        $project->addSubscription($subscription);

        self::assertSame(CommercialPhase::IMPLEMENTATION, $subscription->getPhase());
        self::assertSame($subscription, $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION));
    }
}
