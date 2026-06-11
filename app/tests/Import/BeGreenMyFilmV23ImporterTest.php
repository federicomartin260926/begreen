<?php

namespace App\Tests\Import;

use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\Category;
use App\Entity\Department;
use App\Entity\Protocol;
use App\Repository\CategoryRepository;
use App\Repository\DepartmentRepository;
use App\Repository\MeasureRepository;
use App\Repository\OdsRepository;
use App\Repository\ProtocolRepository;
use App\Service\Import\BeGreenMyFilmV23Importer;
use App\Service\Import\BeGreenMyFilmV23Report;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class BeGreenMyFilmV23ImporterTest extends TestCase
{
    public function testDryRunDoesNotTouchPersistence(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('getConnection');

        $importer = $this->createImporter($entityManager);

        $report = new BeGreenMyFilmV23Report();
        $result = $importer->import($report, false);

        self::assertSame('dry-run', $result->getImportSummary()['mode'] ?? null);
        self::assertSame('not-applied', $result->getImportSummary()['status'] ?? null);
    }

    public function testApplyIsBlockedWhenValidationFails(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('getConnection');

        $importer = $this->createImporter($entityManager);

        $report = new BeGreenMyFilmV23Report();
        $report->addError('critical', 'boom');

        $result = $importer->import($report, true);

        self::assertSame('apply', $result->getImportSummary()['mode'] ?? null);
        self::assertSame('aborted', $result->getImportSummary()['status'] ?? null);
        self::assertSame('validation-errors', $result->getImportSummary()['reason'] ?? null);
    }

    public function testApplyCreatesAllHeaderImpactAreasEvenIfUnusedByMeasures(): void
    {
        $impactAreaRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $impactAreaRepository
            ->expects(self::exactly(6))
            ->method('findOneBy')
            ->willReturn(null);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(7))->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::exactly(6))
            ->method('getRepository')
            ->with(ImpactArea::class)
            ->willReturn($impactAreaRepository);

        $importer = $this->createImporter($entityManager);

        $report = new BeGreenMyFilmV23Report();
        $result = $importer->import($report, true);

        self::assertSame(6, $result->getImportSummary()['impactAreas']['resolved'] ?? null);
        self::assertSame(6, $result->getImportSummary()['impactAreas']['created'] ?? null);
    }

    public function testApplySetsMeasureSortOrderFromSourceRow(): void
    {
        $protocol = (new \App\Entity\Protocol())
            ->setCode('be-green-my-film')
            ->setName('Be Green My Film')
            ->setType(\App\Entity\Protocol::TYPE_RODAJE);
        $category = (new \App\Entity\Category())->setName('Energía')->setSortOrder(10);
        $department = (new \App\Entity\Department())->setCode('prod')->setName('Producción')->setSortOrder(10);

        $impactAreaRepositories = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $code) {
            $impactArea = (new ImpactArea())->setCode($code)->setName($code)->setSortOrder(1);
            $impactAreaRepositories[$code] = $impactArea;
        }

        $persistedMeasures = [];
        $repositories = [
            Protocol::class => $this->createRepository(static fn (array $criteria): ?\App\Entity\Protocol => (($criteria['code'] ?? null) === 'be-green-my-film' || ($criteria['name'] ?? null) === 'Be Green My Film') ? $protocol : null),
            Category::class => $this->createRepository(static fn (array $criteria): ?Category => ($criteria['name'] ?? null) === 'Energía' ? $category : null),
            Department::class => $this->createRepository(static fn (array $criteria): ?Department => (($criteria['code'] ?? null) === 'prod' || ($criteria['name'] ?? null) === 'Producción') ? $department : null),
            Measure::class => $this->createRepository(static fn (): ?Measure => null),
            ImpactArea::class => $this->createRepository(static function (array $criteria) use ($impactAreaRepositories): ?ImpactArea {
                $code = (string) ($criteria['code'] ?? '');
                return $impactAreaRepositories[$code] ?? null;
            }),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class]) || $repositories[$class] === null) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->expects(self::atLeastOnce())
            ->method('persist')
            ->willReturnCallback(static function ($entity) use (&$persistedMeasures): void {
                if ($entity instanceof Measure) {
                    $persistedMeasures[] = $entity;
                }
            });
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new BeGreenMyFilmV23Importer(
            $entityManager,
            $this->createMock(ProtocolRepository::class),
            $this->createMock(CategoryRepository::class),
            $this->createMock(DepartmentRepository::class),
            $this->createMock(OdsRepository::class),
            $this->createMock(MeasureRepository::class),
        );

        $report = new BeGreenMyFilmV23Report();
        $report->registerMeasure([
            'row' => 42,
            'name' => 'Medida v31',
            'category' => 'Energía',
            'departments' => [
                ['code' => 'prod'],
            ],
            'score' => 3,
            'description' => 'Descripción',
            'blockName' => '',
            'ods' => [],
            'impactAreas' => [],
            'tripleBalanceAxes' => [],
            'verificationSources' => [],
        ]);

        $result = $importer->import($report, true);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertCount(1, $persistedMeasures);
        self::assertSame(42, $persistedMeasures[0]->getSourceRow());
        self::assertSame(42, $persistedMeasures[0]->getSortOrder());
    }

    /**
     * @template T of object
     *
     * @param callable(array<string, mixed>): T|null $resolver
     *
     * @return EntityRepository<T>
     */
    private function createRepository(callable $resolver): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback($resolver);

        return $repository;
    }

    private function createImporter(EntityManagerInterface $entityManager): BeGreenMyFilmV23Importer
    {
        return new BeGreenMyFilmV23Importer(
            $entityManager,
            $this->createMock(ProtocolRepository::class),
            $this->createMock(CategoryRepository::class),
            $this->createMock(DepartmentRepository::class),
            $this->createMock(OdsRepository::class),
            $this->createMock(MeasureRepository::class),
        );
    }
}
