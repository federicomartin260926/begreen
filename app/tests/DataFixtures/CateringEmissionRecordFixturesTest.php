<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\CateringEmissionFactorFixtures;
use App\DataFixtures\CateringEmissionRecordFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Catering\CateringEmissionCalculator;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Catering\CateringFactorResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class CateringEmissionRecordFixturesTest extends TestCase
{
    public function testCreatesRepresentativeModernCateringRecordsFromRealCalculations(): void
    {
        $category = (new Category())->setName('Catering');
        (new \ReflectionClass(Category::class))->getProperty('id')->setValue($category, 20);

        $project = (new Project())->setName('Proyecto')->setType('rodaje')->setCountry('ES');
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-06-01'))
            ->setEndDate(new \DateTimeImmutable('2026-06-30'));

        $categoryRepository = $this->createMock(ObjectRepository::class);
        $categoryRepository->method('findOneBy')->willReturn($category);

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
                default => throw new \LogicException(sprintf('Repositorio inesperado: %s', $class)),
            },
        );
        $manager->expects(self::exactly(4))->method('persist')->willReturnCallback(
            static function (object $record) use (&$records): void {
                self::assertInstanceOf(EmissionRecord::class, $record);
                $records[] = $record;
            },
        );
        $manager->expects(self::once())->method('flush');

        $calculator = $this->calculator();
        $snapshot = new CateringEmissionSnapshot();

        $fixture = new CateringEmissionRecordFixtures($calculator, $snapshot);
        $fixture->load($manager);

        self::assertCount(4, $records);

        $cases = [];
        foreach ($records as $record) {
            self::assertSame($category, $record->getCategory());
            self::assertNull($record->getActivity());
            self::assertTrue($snapshot->isCateringV1Record($record, 20));

            $encoded = (string) $record->getCalculationDetails();
            $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            $cases[$data['presentation']['fixtureCase']] = [$record, $data];

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
        }

        self::assertSame(
            ['meal_compostable', 'meal_reusable', 'drink', 'gas'],
            array_keys($cases),
        );

        self::assertSame(62.2355395, $cases['meal_compostable'][0]->getEmission());
        self::assertSame(53.9348395, $cases['meal_reusable'][0]->getEmission());

        self::assertContains(
            EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
            array_column($cases['meal_compostable'][1]['calculation']['factorTraces'], 'temporalType'),
        );
        self::assertContains(
            EmissionFactor::TEMPORAL_TYPE_PROXY_LCA,
            array_column($cases['meal_reusable'][1]['calculation']['factorTraces'], 'temporalType'),
        );

        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $cases['drink'][0]->getStatus());
        self::assertSame(3.96, $cases['drink'][0]->getAmount());
        self::assertNull($cases['drink'][0]->getEmission());

        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $cases['gas'][0]->getStatus());
        self::assertSame(22.0, $cases['gas'][0]->getAmount());
        self::assertNull($cases['gas'][0]->getEmission());

        self::assertContains(CateringEmissionFactorFixtures::class, $fixture->getDependencies());

        $source = file_get_contents(__DIR__.'/../../src/DataFixtures/CateringEmissionRecordFixtures.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            '->setEmission(null === $calculation->emissionKgCo2e',
            $source,
        );
    }

    private function calculator(): CateringEmissionCalculator
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

        (new CateringEmissionFactorFixtures($keyGenerator))->load($factorManager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findMethodological')->willReturnCallback(
            static function (
                string $categoryKey,
                string $functionalKey,
                string $temporalType,
            ) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('catering' === $categoryKey
                        && $functionalKey === $factor->getFunctionalKey()
                        && $temporalType === $factor->getTemporalType()
                    ) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new CateringEmissionCalculator(
            new CateringFactorResolver(
                new EmissionFactorResolver($repository, $keyGenerator),
            ),
        );
    }
}
