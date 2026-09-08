<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\WasteEmissionFactorFixtures;
use App\DataFixtures\WasteEmissionRecordFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Waste\WasteEmissionCalculator;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Waste\WasteFactorResolver;
use App\Service\Emission\Waste\WasteUiCatalog;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class WasteEmissionRecordFixturesTest extends TestCase
{
    public function testCreatesModernWasteRecordsThroughRealCalculator(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(70);
        $project = $this->createMock(Project::class);
        $phase = $this->createMock(ProjectPhaseDate::class);

        $categoryRepository = $this->createMock(ObjectRepository::class);
        $categoryRepository->method('findOneBy')->with(['name' => 'Residuos'])->willReturn($category);
        $projectRepository = $this->createMock(ObjectRepository::class);
        $projectRepository->method('findAll')->willReturn([$project]);
        $phaseRepository = $this->createMock(ObjectRepository::class);
        $phaseRepository->method('findBy')->willReturn([$phase]);

        $records = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')->willReturnCallback(static fn (string $class): ObjectRepository => match ($class) {
            Category::class => $categoryRepository,
            Project::class => $projectRepository,
            ProjectPhaseDate::class => $phaseRepository,
            default => throw new \LogicException(sprintf('Unexpected repository: %s', $class)),
        });
        $manager->expects(self::exactly(10))->method('persist')->willReturnCallback(
            static function (object $record) use (&$records): void {
                self::assertInstanceOf(EmissionRecord::class, $record);
                $records[] = $record;
            },
        );
        $manager->expects(self::once())->method('flush');

        $snapshot = new WasteEmissionSnapshot();
        $calculator = $this->calculator();
        $fixture = new WasteEmissionRecordFixtures($calculator, $snapshot);
        $fixture->load($manager);

        self::assertCount(10, $records);
        $cases = [];
        foreach ($records as $record) {
            self::assertSame($category, $record->getCategory());
            self::assertTrue($snapshot->isWasteV1Record($record, 70));

            $encoded = (string) $record->getCalculationDetails();
            $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            $case = $data['presentation']['fixtureCase'];
            $cases[$case] = [$record, $data];

            $recalculated = $calculator->calculate($snapshot->decodeInput($encoded));
            self::assertSame(null === $recalculated->normalizedAmount ? null : (float) $recalculated->normalizedAmount, $record->getAmount());
            self::assertSame(null === $recalculated->emissionKgCo2e ? null : (float) $recalculated->emissionKgCo2e, $record->getEmission());
            self::assertSame($recalculated->status, $record->getStatus());
        }

        self::assertSame([
            'spain_occc_versioned',
            'spain_defra_proxy_subactivity',
            'uk_defra_local',
            'france_defra_proxy',
            'annual_temporal_fallback',
            'unknown_maximum',
            'non_waste_zero',
            'tonnes_normalization',
            'specific_subactivity',
            'not_calculable_before_series',
        ], array_keys($cases));

        self::assertSame('VERSIONED', $cases['spain_occc_versioned'][1]['calculation']['factorTraces'][0]['temporalType']);
        self::assertNull($cases['spain_occc_versioned'][1]['calculation']['factorTraces'][0]['factorYear']);

        self::assertTrue($cases['spain_defra_proxy_subactivity'][1]['calculation']['factorTraces'][0]['isGeographicProxy']);
        self::assertSame('GBR', $cases['spain_defra_proxy_subactivity'][1]['calculation']['factorTraces'][0]['sourceGeography']);

        self::assertFalse($cases['uk_defra_local'][1]['calculation']['factorTraces'][0]['isGeographicProxy']);
        self::assertTrue($cases['france_defra_proxy'][1]['calculation']['factorTraces'][0]['isGeographicProxy']);

        self::assertSame(2026, $cases['annual_temporal_fallback'][1]['calculation']['factorTraces'][0]['factorYear']);
        self::assertTrue($cases['annual_temporal_fallback'][1]['calculation']['factorTraces'][0]['isFallback']);

        self::assertSame('DERIVED_MAX_VALID_TREATMENTS', $cases['unknown_maximum'][1]['calculation']['factorTraces'][0]['ruleType']);
        self::assertNotEmpty($cases['unknown_maximum'][1]['calculation']['factorTraces'][0]['candidateEvaluations']);

        self::assertSame('NON_WASTE_ROUTE_ZERO', $cases['non_waste_zero'][1]['calculation']['factorTraces'][0]['ruleType']);
        self::assertSame(0.0, $cases['non_waste_zero'][0]->getEmission());

        self::assertSame(1500.0, $cases['tonnes_normalization'][0]->getAmount());
        self::assertSame('Pintura al agua', $cases['specific_subactivity'][1]['calculation']['resolvedWasteActivity']);

        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $cases['not_calculable_before_series'][0]->getStatus());
        self::assertSame(100.0, $cases['not_calculable_before_series'][0]->getAmount());
        self::assertNull($cases['not_calculable_before_series'][0]->getEmission());

        self::assertContains(WasteEmissionFactorFixtures::class, $fixture->getDependencies());

        $source = file_get_contents(__DIR__.'/../../src/DataFixtures/WasteEmissionRecordFixtures.php');
        self::assertIsString($source);
        self::assertStringContainsString('->setEmission(null === $calculation->emissionKgCo2e', $source);

    }

    private function calculator(): WasteEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $factorManager = $this->createMock(ObjectManager::class);
        $factorManager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new WasteEmissionFactorFixtures($keyGenerator))->load($factorManager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    'waste' === $categoryKey
                    && EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                    && $factor->getFunctionalKey() === $functionalKey
                    && $factor->getYear() <= $activityYear
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('waste' === $categoryKey && $temporalType === $factor->getTemporalType() && $functionalKey === $factor->getFunctionalKey()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        $catalog = new WasteUiCatalog();

        return new WasteEmissionCalculator(
            $catalog,
            new WasteFactorResolver(new EmissionFactorResolver($repository, $keyGenerator), $catalog),
        );
    }
}
