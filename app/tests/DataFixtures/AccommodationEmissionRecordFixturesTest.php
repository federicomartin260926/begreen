<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\AccommodationEmissionFactorFixtures;
use App\DataFixtures\AccommodationEmissionRecordFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Accommodation\AccommodationEmissionCalculator;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Accommodation\AccommodationFactorResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class AccommodationEmissionRecordFixturesTest extends TestCase
{
    public function testCreatesRepresentativeModernAccommodationRecordsFromRealCalculations(): void
    {
        $category = (new Category())->setName('Alojamientos');
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
        $manager->method('getRepository')->willReturnCallback(static fn (string $class): ObjectRepository => match ($class) {
            Category::class => $categoryRepository,
            Project::class => $projectRepository,
            ProjectPhaseDate::class => $phaseRepository,
            default => throw new \LogicException(sprintf('Repositorio inesperado: %s', $class)),
        });
        $manager->expects(self::exactly(6))->method('persist')->willReturnCallback(
            static function (object $record) use (&$records): void {
                self::assertInstanceOf(EmissionRecord::class, $record);
                $records[] = $record;
            },
        );
        $manager->expects(self::once())->method('flush');

        $snapshot = new AccommodationEmissionSnapshot();
        $calculator = $this->calculator();
        $fixture = new AccommodationEmissionRecordFixtures($calculator, $snapshot);
        $fixture->load($manager);

        self::assertCount(6, $records);
        $cases = [];
        foreach ($records as $record) {
            self::assertSame($category, $record->getCategory());
            self::assertTrue($snapshot->isAccommodationV1Record($record, 20));

            $encoded = (string) $record->getCalculationDetails();
            $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(AccommodationEmissionSnapshot::VERSION, $data['version']);
            $cases[$data['presentation']['fixtureCase']] = [$record, $data];

            $recalculated = $calculator->calculate($snapshot->decodeInput($encoded));
            self::assertSame(null === $recalculated->normalizedAmount ? null : (float) $recalculated->normalizedAmount, $record->getAmount());
            self::assertSame(null === $recalculated->emissionKgCo2e ? null : (float) $recalculated->emissionKgCo2e, $record->getEmission());
            self::assertSame($recalculated->status, $record->getStatus());
        }

        self::assertSame([
            'hotel_exact', 'hotel_temporal_fallback', 'hotel_geographic_proxy',
            'hostel', 'apartment', 'other',
        ], array_keys($cases));
        self::assertSame('hotel', $cases['hotel_exact'][1]['input']['accommodationType']);
        self::assertFalse($cases['hotel_exact'][1]['calculation']['factorTraces'][0]['fallback']);
        self::assertSame(2024, $cases['hotel_exact'][1]['calculation']['factorTraces'][0]['factorYear']);
        self::assertTrue($cases['hotel_temporal_fallback'][1]['calculation']['factorTraces'][0]['fallback']);
        self::assertSame(2024, $cases['hotel_temporal_fallback'][1]['calculation']['factorTraces'][0]['factorYear']);
        self::assertTrue($cases['hotel_geographic_proxy'][1]['calculation']['factorTraces'][0]['geographicProxy']);
        self::assertSame('hostel', $cases['hostel'][1]['input']['accommodationType']);
        self::assertSame('apartment', $cases['apartment'][1]['input']['accommodationType']);
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $cases['other'][0]->getStatus());
        self::assertNull($cases['other'][0]->getAmount());
        self::assertNull($cases['other'][0]->getEmission());

        self::assertContains(AccommodationEmissionFactorFixtures::class, $fixture->getDependencies());
        $source = file_get_contents(__DIR__.'/../../src/DataFixtures/AccommodationEmissionRecordFixtures.php');
        self::assertIsString($source);
        self::assertStringContainsString('->setEmission(null === $calculation->emissionKgCo2e', $source);
    }

    private function calculator(): AccommodationEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $factorManager = $this->createMock(ObjectManager::class);
        $factorManager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new AccommodationEmissionFactorFixtures($keyGenerator))->load($factorManager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('accommodation', $categoryKey);
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
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
                    if ('accommodation' === $categoryKey
                        && EmissionFactor::TEMPORAL_TYPE_VERSIONED === $temporalType
                        && EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType()
                        && $factor->getFunctionalKey() === $functionalKey
                    ) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new AccommodationEmissionCalculator(
            new AccommodationFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)),
        );
    }
}
