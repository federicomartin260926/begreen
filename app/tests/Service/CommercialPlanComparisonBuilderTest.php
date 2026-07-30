<?php

namespace App\Tests\Service;

use App\Entity\Plan;
use App\Entity\Protocol;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
use App\Service\CommercialPlanComparisonBuilder;
use App\Tests\Support\CommercialPlanTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommercialPlanComparisonBuilderTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testComparisonReflectsPlanPriceAllowedScoresAndFeatureConfiguration(): void
    {
        self::bootKernel();

        $plans = $this->makeDefaultCommercialPlans();
        $phasePlans = [$plans['basic'], $plans['standard'], $plans['pro']];
        $repository = $this->createMock(CommercialPlanRepository::class);
        $repository->method('findByPhaseOrdered')->with(CommercialPhase::ELABORATION)->willReturn($phasePlans);

        $measureRepository = $this->createMock(MeasureRepository::class);
        $measureRepository->method('countCatalogMeasuresForProtocol')->willReturnCallback(
            static fn (Protocol $protocol, array $scores): int => count($scores) * 10
        );

        $builder = new CommercialPlanComparisonBuilder(
            $repository,
            $measureRepository,
            self::getContainer()->get('translator'),
        );
        $projectPlan = (new Plan())->setProtocol(new Protocol());

        $initial = $builder->build(
            CommercialPhase::ELABORATION,
            'basic',
            $projectPlan,
            ['standard' => ['targetTier' => 'standard', 'priceId' => 'price_standard']],
        );

        $plans['standard']
            ->setPriceAmount(3700)
            ->setAllowedScores([1, 2])
            ->setFeature('sustainability_plan.custom_measures', false);

        $updated = $builder->build(
            CommercialPhase::ELABORATION,
            'basic',
            $projectPlan,
            ['standard' => ['targetTier' => 'standard', 'priceId' => 'price_standard']],
        );

        self::assertSame(2900, $initial['plans']['standard']['priceAmount']);
        self::assertSame(3700, $updated['plans']['standard']['priceAmount']);
        self::assertSame('EUR', $updated['plans']['standard']['priceCurrency']);
        self::assertSame([1, 2], $updated['plans']['standard']['allowedScores']);
        self::assertSame(30, (int) $this->findRow($initial, 'measure_count')['values']['standard']['label']);
        self::assertSame(20, (int) $this->findRow($updated, 'measure_count')['values']['standard']['label']);
        self::assertTrue($this->findRow($initial, 'custom_measure')['values']['standard']['enabled']);
        self::assertFalse($this->findRow($updated, 'custom_measure')['values']['standard']['enabled']);
        self::assertSame(
            'feature:sustainability_plan.custom_measures',
            $this->findRow($updated, 'custom_measure')['source'],
        );
    }

    public function testStandardToProUsesSpecificUpgradePriceAndStaticRowsRemainIdentified(): void
    {
        self::bootKernel();

        $plans = $this->makeDefaultCommercialPlans();
        $repository = $this->createMock(CommercialPlanRepository::class);
        $repository->method('findByPhaseOrdered')->willReturn([
            $plans['basic'],
            $plans['standard'],
            $plans['pro'],
        ]);

        $comparison = (new CommercialPlanComparisonBuilder(
            $repository,
            $this->createMock(MeasureRepository::class),
            self::getContainer()->get('translator'),
        ))->build(
            CommercialPhase::ELABORATION,
            'standard',
            null,
            ['pro' => ['targetTier' => 'pro', 'amountCents' => 2000, 'priceId' => 'price_upgrade']],
        );

        self::assertSame(4900, $comparison['plans']['pro']['basePriceAmount']);
        self::assertSame(2000, $comparison['plans']['pro']['priceAmount']);
        self::assertSame(2000, $comparison['plans']['pro']['upgrade']['priceAmount']);
        self::assertTrue($comparison['plans']['standard']['current']);
        self::assertSame('static', $this->findRow($comparison, 'level_alerts')['source']);
        $observations = $this->findRow($comparison, 'observations');
        self::assertSame('static', $observations['source']);
        self::assertTrue($observations['values']['basic']['enabled']);
        self::assertTrue($observations['values']['standard']['enabled']);
        self::assertTrue($observations['values']['pro']['enabled']);
    }

    /**
     * @param array{rows: array<int, array<string, mixed>>} $comparison
     * @return array<string, mixed>
     */
    private function findRow(array $comparison, string $id): array
    {
        foreach ($comparison['rows'] as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        self::fail(sprintf('Comparison row "%s" was not found.', $id));
    }
}
