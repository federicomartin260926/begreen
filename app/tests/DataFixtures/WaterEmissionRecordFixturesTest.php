<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\WaterEmissionFactorFixtures;
use App\DataFixtures\WaterEmissionRecordFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Water\WaterEmissionCalculator;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\Emission\Water\WaterFactorResolver;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class WaterEmissionRecordFixturesTest extends TestCase
{
    public function testCreatesRepresentativeModernWaterRecordsFromRealCalculations(): void
    {
        $category = (new Category())->setName('Agua');
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
        $manager->method('getRepository')->willReturnCallback(static fn (string $class): ObjectRepository => match ($class) {
            Category::class => $categoryRepository,
            Project::class => $projectRepository,
            ProjectPhaseDate::class => $phaseRepository,
            default => throw new \LogicException(sprintf('Repositorio inesperado: %s', $class)),
        });
        $manager->expects(self::exactly(5))
            ->method('persist')
            ->with(self::callback(static function (object $record) use (&$records): bool {
                self::assertInstanceOf(EmissionRecord::class, $record);
                $records[] = $record;

                return true;
            }));
        $manager->expects(self::once())->method('flush');

        $snapshot = new WaterEmissionSnapshot();
        $fixture = new WaterEmissionRecordFixtures($this->calculator(), $snapshot);
        $fixture->load($manager);

        self::assertCount(5, $records);
        $cases = [];
        foreach ($records as $record) {
            self::assertSame($category, $record->getCategory());
            self::assertNull($record->getActivity());
            self::assertSame(EmissionRecord::STATUS_CALCULATED, $record->getStatus());
            self::assertNotNull($record->getAmount());
            self::assertNotNull($record->getEmission());

            $encoded = (string) $record->getCalculationDetails();
            $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(WaterEmissionSnapshot::VERSION, $data['version']);
            $cases[$data['presentation']['fixtureCase']] = $data;

            $recalculated = $this->calculator()->calculate($snapshot->decodeInput($encoded));
            self::assertSame((float) $recalculated->normalizedAmount, $record->getAmount());
            self::assertSame((float) $recalculated->emissionKgCo2e, $record->getEmission());
            self::assertSame($recalculated->status, $record->getStatus());
        }

        self::assertSame([
            'es_sewer',
            'gb_sewer_supply_treatment',
            'es_irrigation_uk_proxy',
            'fr_sewer_uk_proxy',
            'gb_litres_normalization',
        ], array_keys($cases));
        self::assertCount(1, $cases['es_sewer']['calculation']['factorTraces']);
        self::assertCount(2, $cases['gb_sewer_supply_treatment']['calculation']['factorTraces']);
        self::assertTrue($cases['es_irrigation_uk_proxy']['calculation']['factorTraces'][0]['geographicProxy']);
        self::assertTrue($cases['fr_sewer_uk_proxy']['calculation']['factorTraces'][0]['geographicProxy']);
        self::assertSame('750', $cases['gb_litres_normalization']['input']['volumeInput']);
        self::assertSame('L', $cases['gb_litres_normalization']['input']['volumeInputUnit']);
        self::assertSame('0.75', $cases['gb_litres_normalization']['calculation']['normalizedAmount']);

        self::assertContains(WaterEmissionFactorFixtures::class, $fixture->getDependencies());
        $source = file_get_contents(__DIR__.'/../../src/DataFixtures/WaterEmissionRecordFixtures.php');
        self::assertIsString($source);
        self::assertStringContainsString('->setEmission(null === $calculation->emissionKgCo2e', $source);
    }

    private function calculator(): WaterEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new WaterEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('water', $categoryKey);
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool => $factor->getFunctionalKey() === $functionalKey
                        && $factor->getYear() <= $activityYear,
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );

        return new WaterEmissionCalculator(
            new WaterFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)),
        );
    }
}
