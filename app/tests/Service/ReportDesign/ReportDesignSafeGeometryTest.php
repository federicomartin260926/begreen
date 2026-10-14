<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\TestCase;

final class ReportDesignSafeGeometryTest extends TestCase
{
    private const HTML = <<<'HTML'
[COMPONENT:cover]
[COMPONENT:analytics]
[COMPONENT:departments]
[COMPONENT:ai_report]
[COMPONENT:final_conclusion]
HTML;

    private function validator(): ReportDesignValidator
    {
        return new ReportDesignValidator(
            new ReportDesignComponentCatalog(),
            new ReportDesignDataCatalog(),
        );
    }

    public function testInternalWidthAndDisplayAreAccepted(): void
    {
        $this->validator()->validate(
            self::HTML,
            '.report-design-analytics .pdf-quick-read__row {'
            .' display: block;'
            .' width: 96%;'
            .' max-width: 100%;'
            .' margin: 2mm auto;'
            .'}'
        );

        self::assertTrue(true);
    }

    public function testPhysicalRootWidthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'physical report root geometry with width'
        );

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover { width: 90%; }'
        );
    }

    public function testPhysicalRootDisplayIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'physical report root geometry with display'
        );

        $this->validator()->validate(
            self::HTML,
            '.report-design-final-conclusion { display: block; }'
        );
    }

    public function testInternalAbsolutePositionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'unsafe geometry properties'
        );

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-indicators__cell {'
            .' position: absolute;'
            .'}'
        );
    }

    public function testFlexAndGridAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'unsupported flex/grid layout'
        );

        $this->validator()->validate(
            self::HTML,
            '.report-design-ai-report .pdf-ai-page__category-card {'
            .' display: grid;'
            .'}'
        );
    }

    public function testBorderPropertiesAreNotMistakenForGeometry(): void
    {
        $this->validator()->validate(
            self::HTML,
            '.report-design-cover {'
            .' border-top: 2px solid #d8c99f;'
            .' border-right: 2px solid #d8c99f;'
            .' border-bottom: 2px solid #d8c99f;'
            .' border-left: 2px solid #d8c99f;'
            .' border-width: 2px;'
            .'}'
        );

        self::assertTrue(true);
    }

    public function testUnsafeGeometryReportsExactProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'unsafe geometry properties (transform)'
        );

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover__title {'
            .' transform: scale(1.1);'
            .'}'
        );
    }

}
