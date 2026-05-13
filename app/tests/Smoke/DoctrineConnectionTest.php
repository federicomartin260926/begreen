<?php

namespace App\Tests\Smoke;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineConnectionTest extends KernelTestCase
{
    public function testDoctrineConnectionIsReachable(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get('doctrine')->getConnection();

        self::assertInstanceOf(Connection::class, $connection);
        self::assertTrue($connection->connect());
        self::assertSame('1', (string) $connection->fetchOne('SELECT 1'));
    }
}
