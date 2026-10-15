<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportDesignLogoValidatorTest extends TestCase
{
    private const HTML = <<<'HTML'
[COMPONENT:cover]
[COMPONENT:analytics]
[COMPONENT:ai_report]
[COMPONENT:departments]
[COMPONENT:final_conclusion]
HTML;

    private function validator(): ReportDesignValidator
    {
        return new ReportDesignValidator(
            new ReportDesignComponentCatalog(),
            new ReportDesignDataCatalog(),
        );
    }

    public function testSafeLogoAlignmentAndSpacingAreAccepted(): void
    {
        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos {'
            .' margin-left: auto; margin-right: auto;'
            .'}'
            .' .report-design-cover .pdf-cover-company-logos__cell {'
            .' padding-right: 2.5mm;'
            .'}'
        );

        self::assertTrue(true);
    }

    public function testSafeLogoSizePreservesAspectRatio(): void
    {
        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos__image {'
            .' max-width: 24mm !important; height: auto !important;'
            .'}'
        );

        self::assertTrue(true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeLogoMaxWidthProvider(): iterable
    {
        yield 'too small' => ['7.9mm'];
        yield 'too large' => ['40.1mm'];
        yield 'percentage' => ['50%'];
    }

    #[DataProvider('unsafeLogoMaxWidthProvider')]
    public function testUnsafeLogoMaxWidthIsRejected(string $maxWidth): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos__image {'
            .' max-width: '.$maxWidth.' !important; height: auto !important;'
            .'}'
        );
    }

    public function testLogoWidthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported property');

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos__image {'
            .' width: 24mm !important; height: auto !important;'
            .'}'
        );
    }

    public function testLogoMaxWidthWithoutAutomaticHeightIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'max-width and automatic height must be set together'
        );

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos__image {'
            .' max-width: 24mm !important;'
            .'}'
        );
    }

    public function testFixedLogoHeightIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must preserve aspect ratio');

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos__image {'
            .' max-width: 24mm !important; height: 12mm !important;'
            .'}'
        );
    }

    public function testUnsafeHeightOutsideLogoImageIsStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe geometry properties (height)');

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover__title { height: auto; }'
        );
    }

    public function testFreeLogoPositioningIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos {'
            .' position: absolute;'
            .'}'
        );
    }

    public function testLogoSpacingOutsideSafeRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('spacing is outside the safe range');

        $this->validator()->validate(
            self::HTML,
            '.report-design-cover .pdf-cover-company-logos__cell {'
            .' padding-right: 2.6mm;'
            .'}'
        );
    }
}
