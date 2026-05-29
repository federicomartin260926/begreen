<?php

namespace App\Tests\Smoke;

use App\Service\ActiveProjectService;
use App\Service\PlanMeasureResumeService;
use App\Service\PdfService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ServiceWiringTest extends KernelTestCase
{
    public function testCriticalServicesAreWired(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        self::assertInstanceOf(PdfService::class, $container->get(PdfService::class));
        self::assertInstanceOf(ActiveProjectService::class, $container->get(ActiveProjectService::class));
        self::assertInstanceOf(PlanMeasureResumeService::class, $container->get(PlanMeasureResumeService::class));
    }
}
