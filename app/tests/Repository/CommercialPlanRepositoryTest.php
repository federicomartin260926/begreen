<?php

namespace App\Tests\Repository;

use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use PHPUnit\Framework\TestCase;

final class CommercialPlanRepositoryTest extends TestCase
{
    public function testFindActiveByPhaseAndCodeUsesPhaseScopedCriteria(): void
    {
        $plan = new CommercialPlan();

        $repository = $this->getMockBuilder(CommercialPlanRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();

        $repository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'phase' => CommercialPhase::IMPLEMENTATION,
                'code' => 'pro',
                'active' => true,
            ])
            ->willReturn($plan);

        self::assertSame($plan, $repository->findActiveByPhaseAndCode(CommercialPhase::IMPLEMENTATION, 'pro'));
    }
}
