<?php

namespace App\Tests\Repository;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Repository\ProjectSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class ProjectSubscriptionRepositoryTest extends TestCase
{
    public function testFindOneByProjectAndPhaseUsesProjectAndPhaseCriteria(): void
    {
        $subscription = new ProjectSubscription();
        $project = new Project();

        $repository = $this->getMockBuilder(ProjectSubscriptionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();

        $repository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'project' => $project,
                'phase' => CommercialPhase::IMPLEMENTATION,
            ])
            ->willReturn($subscription);

        self::assertSame($subscription, $repository->findOneByProjectAndPhase($project, CommercialPhase::IMPLEMENTATION));
    }

    public function testFindOneByProjectAndPhaseDefaultsToElaborationPhase(): void
    {
        $subscription = new ProjectSubscription();
        $project = new Project();

        $repository = $this->getMockBuilder(ProjectSubscriptionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();

        $repository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'project' => $project,
                'phase' => CommercialPhase::ELABORATION,
            ])
            ->willReturn($subscription);

        self::assertSame($subscription, $repository->findOneByProjectAndPhase($project, CommercialPhase::ELABORATION));
    }
}
