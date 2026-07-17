<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\CommercialPlanFixtures;
use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class CommercialPlanFixturesTest extends TestCase
{
    public function testCommercialPlanFixturesCreateSixPhaseScopedPlans(): void
    {
        $persistedPlans = [];

        $repository = new class implements ObjectRepository {
            public function findOneBy(array $criteria): ?CommercialPlan
            {
                return null;
            }

            public function find($id): object|null
            {
                return null;
            }

            public function findAll(): array
            {
                return [];
            }

            public function findBy(array $criteria, array|null $orderBy = null, int|null $limit = null, int|null $offset = null): array
            {
                return [];
            }

            public function getClassName(): string
            {
                return CommercialPlan::class;
            }
        };

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')
            ->with(CommercialPlan::class)
            ->willReturn($repository);
        $manager->expects(self::exactly(6))
            ->method('persist')
            ->with(self::callback(static function (object $plan) use (&$persistedPlans): bool {
                if (!$plan instanceof CommercialPlan) {
                    return false;
                }

                $persistedPlans[] = $plan;

                return true;
            }));
        $manager->expects(self::once())->method('flush');

        (new CommercialPlanFixtures())->load($manager);

        self::assertCount(6, $persistedPlans);

        $keys = array_map(static fn (CommercialPlan $plan): string => $plan->getPhase()->value . ':' . $plan->getCode(), $persistedPlans);
        self::assertEqualsCanonicalizing([
            CommercialPhase::ELABORATION->value . ':basic',
            CommercialPhase::ELABORATION->value . ':standard',
            CommercialPhase::ELABORATION->value . ':pro',
            CommercialPhase::IMPLEMENTATION->value . ':basic',
            CommercialPhase::IMPLEMENTATION->value . ':standard',
            CommercialPhase::IMPLEMENTATION->value . ':pro',
        ], $keys);

        $implementationPlans = [];
        foreach ($persistedPlans as $plan) {
            if ($plan->getPhase() === CommercialPhase::IMPLEMENTATION) {
                $implementationPlans[$plan->getCode()] = $plan;
            }
        }

        self::assertTrue($implementationPlans['basic']->getFeature('sustainability_plan.evidence_upload'));
        self::assertFalse($implementationPlans['basic']->getFeature('sustainability_plan.checklist'));
        self::assertSame(10, $implementationPlans['basic']->getMaxEvidenceCount());
        self::assertTrue($implementationPlans['basic']->isWatermarkEnabled());

        self::assertTrue($implementationPlans['standard']->getFeature('sustainability_plan.checklist'));
        self::assertTrue($implementationPlans['standard']->getFeature('sustainability_plan.responsibles'));
        self::assertTrue($implementationPlans['standard']->getFeature('sustainability_plan.internal_notes'));
        self::assertFalse($implementationPlans['standard']->getFeature('sustainability_plan.export.excel'));
        self::assertNull($implementationPlans['standard']->getMaxEvidenceCount());
        self::assertFalse($implementationPlans['standard']->isWatermarkEnabled());

        self::assertTrue($implementationPlans['pro']->getFeature('sustainability_plan.validation_summary'));
        self::assertTrue($implementationPlans['pro']->getFeature('sustainability_plan.branding'));
        self::assertTrue($implementationPlans['pro']->getFeature('sustainability_plan.export.excel'));
    }
}
