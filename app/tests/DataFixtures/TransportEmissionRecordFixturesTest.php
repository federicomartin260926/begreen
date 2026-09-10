<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\TransportEmissionFactorFixtures;
use App\DataFixtures\TransportEmissionRecordFixtures;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Transport\TransportEmissionCalculator;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class TransportEmissionRecordFixturesTest extends TestCase
{
    public function testCreatesModernCalculatedTransportRecordsWithoutLegacyActivities(): void
    {
        $category = (new Category())->setName('Transporte');
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
        $manager->expects(self::exactly(3))
            ->method('persist')
            ->with(self::callback(static function (object $record) use (&$records): bool {
                self::assertInstanceOf(EmissionRecord::class, $record);
                $records[] = $record;

                return true;
            }));
        $manager->expects(self::once())->method('flush');

        $keyGenerator = new EmissionFactorKeyGenerator();
        $factorRepository = $this->createMock(EmissionFactorRepository::class);
        $factorRepository->method('findForActivityYear')->willReturnCallback(
            static fn (string $categoryKey, string $functionalKey): EmissionFactor => (new EmissionFactor())
                ->setCategoryKey($categoryKey)
                ->setFunctionalKey($functionalKey)
                ->setCriteria([])
                ->setYear(2025)
                ->setValue('0.5')
                ->setUnit('km')
                ->setSource('test'),
        );
        $calculator = new TransportEmissionCalculator(
            new TransportFactorCriteriaMapper(),
            new EmissionFactorResolver($factorRepository, $keyGenerator),
            $keyGenerator,
        );

        (new TransportEmissionRecordFixtures($calculator, new TransportEmissionSnapshot()))->load($manager);

        self::assertCount(3, $records);
        foreach ($records as $record) {
            self::assertSame($category, $record->getCategory());
            self::assertSame(EmissionRecord::STATUS_CALCULATED, $record->getStatus());
            self::assertStringContainsString('"version":"transport-v20"', (string) $record->getCalculationDetails());
            $input = (new TransportEmissionSnapshot())->decode((string) $record->getCalculationDetails());
            self::assertSame($input->startDate->format('Y-m-d'), $input->endDate->format('Y-m-d'));
        }

        self::assertSame(6.0, $records[0]->getEmission());
        self::assertSame(10.0, $records[1]->getEmission());
        self::assertStringContainsString('"fallback":true', (string) $records[1]->getCalculationDetails());
        self::assertSame(3.0, $records[2]->getEmission());
        self::assertStringContainsString('"source":"operator"', (string) $records[2]->getCalculationDetails());
        self::assertStringContainsString('"factorId":null', (string) $records[2]->getCalculationDetails());
        self::assertSame(
            [TransportEmissionFactorFixtures::class],
            array_values(array_filter(
                (new TransportEmissionRecordFixtures($calculator, new TransportEmissionSnapshot()))->getDependencies(),
                static fn (string $dependency): bool => TransportEmissionFactorFixtures::class === $dependency,
            ))
        );
    }
}
