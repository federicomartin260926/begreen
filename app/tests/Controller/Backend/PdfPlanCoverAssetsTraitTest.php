<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PdfPlanCoverAssetsTrait;
use PHPUnit\Framework\TestCase;

final class PdfPlanCoverAssetsTraitTest extends TestCase
{
    public function testSharedCoverAssetsPreserveGraphicsAndEmptyLogos(): void
    {
        $factory = new class {
            use PdfPlanCoverAssetsTrait {
                pdfCommitmentChartDataUri as public commitmentChart;
                pdfScoreRingDataUri as public scoreRing;
                buildPdfCompanyLogos as public companyLogos;
            }

            private function pdfAssetDataUri(string $path, string $mime): string
            {
                return 'data:'.$mime.';base64,AAAA';
            }

            private function getParameter(string $name): string
            {
                return '/app';
            }
        };

        self::assertSame([], $factory->companyLogos(null));
        $chart = $factory->commitmentChart([
            'points' => 174,
            'percentageRounded' => 51,
            'levelKey' => 'tree',
        ]);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $chart);
        $svg = base64_decode(substr($chart, strlen('data:image/svg+xml;base64,')), true);
        self::assertIsString($svg);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('<image ', $svg);
        self::assertStringContainsString('174', $svg);

        $ring = $factory->scoreRing(46);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $ring);
        self::assertStringContainsString('<circle', (string) base64_decode(substr($ring, strlen('data:image/svg+xml;base64,')), true));
    }

    public function testBothControllersReuseSameCoverAssetTrait(): void
    {
        $root = dirname(__DIR__, 3);
        $general = file_get_contents($root.'/src/Controller/Backend/PlanController.php');
        $grouped = file_get_contents($root.'/src/Controller/Backend/SustainabilityPlanExportController.php');
        self::assertIsString($general);
        self::assertIsString($grouped);
        self::assertStringContainsString('use PdfPlanCoverAssetsTrait;', $general);
        self::assertStringContainsString('use PdfPlanCoverAssetsTrait;', $grouped);
        foreach (['companyLogos', 'commitmentChart', 'scoreRing'] as $name) {
            self::assertStringContainsString("'".$name."' =>", $general);
            self::assertStringContainsString("'".$name."' =>", $grouped);
        }
    }
}
