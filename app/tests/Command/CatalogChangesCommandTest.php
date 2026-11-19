<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CatalogChangesCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogChangesCommandTest extends TestCase
{
    public function testInvalidDateDoesNotQueryDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');
        $db->expects(self::never())->method('fetchAllAssociative');

        $tester = new CommandTester(new CatalogChangesCommand($db));
        self::assertSame(Command::INVALID, $tester->execute(['--since' => '2026-02-30 10:00:00']));
        self::assertStringContainsString('CATALOG_CHANGE_GUARD=ERROR', $tester->getDisplay());
    }

    public function testNoChangesReturnsPass(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAssociative')->willReturn([
            'total' => '637', 'first_updated' => '2026-11-17 09:34:49',
            'last_updated' => '2026-11-17 09:34:56',
        ]);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        $tester = new CommandTester(new CatalogChangesCommand($db));
        self::assertSame(Command::SUCCESS, $tester->execute(['--since' => '2026-11-17 09:34:56']));
        self::assertStringContainsString('TOTAL_MEASURES=637', $tester->getDisplay());
        self::assertStringContainsString('CHANGED_MEASURES=0', $tester->getDisplay());
        self::assertStringContainsString('CATALOG_CHANGE_GUARD=PASS', $tester->getDisplay());
    }

    public function testChangedMeasureReturnsFailAndListsIt(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'total' => '637', 'first_updated' => '2026-11-17 09:34:49',
            'last_updated' => '2026-11-18 12:30:00',
        ]);
        $db->expects(self::once())->method('fetchAllAssociative')
            ->with(self::anything(), ['since' => '2026-11-17 09:34:56'])
            ->willReturn([[
                'catalog_id' => 'BGFM-001', 'name' => 'Prueba',
                'updated_at' => '2026-11-18 12:30:00',
            ]]);

        $tester = new CommandTester(new CatalogChangesCommand($db));
        self::assertSame(Command::FAILURE, $tester->execute(['--since' => '2026-11-17 09:34:56']));
        self::assertStringContainsString('CHANGED_MEASURES=1', $tester->getDisplay());
        self::assertStringContainsString('MEASURE=BGFM-001', $tester->getDisplay());
        self::assertStringContainsString('CATALOG_CHANGE_GUARD=FAIL', $tester->getDisplay());
    }
}
