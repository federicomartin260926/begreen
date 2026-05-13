<?php

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootTest extends KernelTestCase
{
    public function testKernelBootsAndContainerIsAvailable(): void
    {
        self::bootKernel();

        self::assertNotNull(self::getContainer());
        self::assertSame('test', self::getContainer()->getParameter('kernel.environment'));
    }
}
