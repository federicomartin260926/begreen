<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\MaterialEmissionFactorFixtures;
use App\DataFixtures\MaterialEmissionRecordFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Material\MaterialAmountNormalizer;
use App\Service\Emission\Material\MaterialEmissionCalculator;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Material\MaterialFactorResolver;
use App\Service\Emission\Material\MaterialUiCatalog;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class MaterialEmissionRecordFixturesTest extends TestCase
{
    public function testCreatesModernMaterialRecordsThroughRealCalculator(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(80);

        $project = $this->createMock(Project::class);
        $phase = $this->createMock(ProjectPhaseDate::class);

        $categoryRepository = $this->createMock(ObjectRepository::class);
        $categoryRepository->method('findOneBy')
            ->with(['name' => 'Materiales'])
            ->willReturn($category);

        $projectRepository = $this->createMock(ObjectRepository::class);
        $projectRepository->method('findAll')->willReturn([$project]);

        $phaseRepository = $this->createMock(ObjectRepository::class);
        $phaseRepository->method('findBy')->willReturn([$phase]);

        $records = [];

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn (string $class): ObjectRepository => match ($class) {
                Category::class => $categoryRepository,
                Project::class => $projectRepository,
                ProjectPhaseDate::class => $phaseRepository,
                default => throw new \LogicException(sprintf('Unexpected repository: %s', $class)),
            },
        );

        $manager->expects(self::exactly(7))
            ->method('persist')
            ->willReturnCallback(static function (object $record) use (&$records): void {
                self::assertInstanceOf(EmissionRecord::class, $record);
                $records[] = $record;
            });

        $manager->expects(self::once())->method('flush');

        $snapshot = new MaterialEmissionSnapshot();
        $calculator = $this->calculator();

        $fixture = new MaterialEmissionRecordFixtures($calculator, $snapshot);
        $fixture->load($manager);

        self::assertCount(7, $records);

        $cases = [];

        foreach ($records as $record) {
            self::assertSame($category, $record->getCategory());
            self::assertTrue($snapshot->isMaterialV1Record($record, 80));

            $encoded = (string) $record->getCalculationDetails();
            $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            $key = $data['presentation']['fixtureCase'];
            $cases[$key] = [$record, $data];

            $recalculated = $calculator->calculate($snapshot->decodeInput($encoded));

            self::assertSame(
                null === $recalculated->normalizedAmount ? null : (float) $recalculated->normalizedAmount,
                $record->getAmount(),
            );
            self::assertSame(
                null === $recalculated->emissionKgCo2e ? null : (float) $recalculated->emissionKgCo2e,
                $record->getEmission(),
            );
            self::assertSame($recalculated->status, $record->getStatus());
            self::assertSame($recalculated->toArray(), $data['calculation']);
        }

        self::assertSame([
            'direct_weight',
            'wood_dimensions',
            'paper_packages',
            'battery_versioned',
            'paint_volume',
            'reuse_rule_zero',
            'reused_plastic_not_calculable',
        ], array_keys($cases));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $cases['direct_weight'][0]->getStatus());
        self::assertGreaterThan(0.0, $cases['direct_weight'][0]->getEmission());

        self::assertSame(271.48416, $cases['wood_dimensions'][0]->getAmount());
        self::assertSame(8.887725, $cases['paper_packages'][0]->getAmount());
        self::assertSame(16.0, $cases['paint_volume'][0]->getAmount());

        self::assertSame(
            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            $cases['battery_versioned'][1]['calculation']['factorTraces'][0]['temporalType'],
        );
        self::assertNull(
            $cases['battery_versioned'][1]['calculation']['factorTraces'][0]['factorYear'],
        );

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $cases['reuse_rule_zero'][0]->getStatus());
        self::assertSame(0.0, $cases['reuse_rule_zero'][0]->getEmission());
        self::assertSame(
            EmissionFactor::TEMPORAL_TYPE_RULE,
            $cases['reuse_rule_zero'][1]['calculation']['factorTraces'][0]['temporalType'],
        );
        self::assertNull(
            $cases['reuse_rule_zero'][1]['calculation']['factorTraces'][0]['factorYear'],
        );

        self::assertSame(
            EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
            $cases['reused_plastic_not_calculable'][0]->getStatus(),
        );
        self::assertNull($cases['reused_plastic_not_calculable'][0]->getEmission());

        self::assertContains(MaterialEmissionFactorFixtures::class, $fixture->getDependencies());


        $source = file_get_contents(__DIR__.'/../../src/DataFixtures/MaterialEmissionRecordFixtures.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            '->setEmission(null === $calculation->emissionKgCo2e',
            $source,
        );
        self::assertStringNotContainsString('->setEmission(0', $source);
    }

    private function calculator(): MaterialEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];

        $factorManager = $this->createMock(ObjectManager::class);
        $factorManager->method('persist')->willReturnCallback(
            static function (object $factor) use (&$factors): void {
                if ($factor instanceof EmissionFactor) {
                    $factors[] = $factor;
                }
            },
        );

        (new MaterialEmissionFactorFixtures($keyGenerator))->load($factorManager);

        $repository = $this->createMock(EmissionFactorRepository::class);

        $repository->method('findForActivityYear')->willReturnCallback(
            static function (
                string $categoryKey,
                string $functionalKey,
                int $activityYear,
            ) use (&$factors): ?EmissionFactor {
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool =>
                        'material' === $categoryKey
                        && EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                        && $factor->getFunctionalKey() === $functionalKey
                        && (int) $factor->getYear() <= $activityYear,
                );

                usort(
                    $candidates,
                    static fn (EmissionFactor $left, EmissionFactor $right): int =>
                        (int) $right->getYear() <=> (int) $left->getYear(),
                );

                return $candidates[0] ?? null;
            },
        );

        $repository->method('findMethodological')->willReturnCallback(
            static function (
                string $categoryKey,
                string $functionalKey,
                string $temporalType,
            ) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if (
                        'material' === $categoryKey
                        && $temporalType === $factor->getTemporalType()
                        && $functionalKey === $factor->getFunctionalKey()
                    ) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        $catalog = new MaterialUiCatalog();

        return new MaterialEmissionCalculator(
            $catalog,
            new MaterialAmountNormalizer($catalog),
            new MaterialFactorResolver(
                new EmissionFactorResolver($repository, $keyGenerator),
                $catalog,
            ),
        );
    }
}
