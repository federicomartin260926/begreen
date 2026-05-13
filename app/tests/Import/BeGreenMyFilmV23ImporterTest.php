<?php

namespace App\Tests\Import;

use App\Repository\CategoryRepository;
use App\Repository\DepartmentRepository;
use App\Repository\MeasureRepository;
use App\Repository\OdsRepository;
use App\Repository\ProtocolRepository;
use App\Service\Import\BeGreenMyFilmV23Importer;
use App\Service\Import\BeGreenMyFilmV23Report;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
