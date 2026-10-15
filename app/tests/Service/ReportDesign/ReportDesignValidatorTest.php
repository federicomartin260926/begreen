<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\TestCase;

final class ReportDesignValidatorTest extends TestCase
{
    private const VALID_HTML = <<<'HTML'
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

    public function testValidCompositionIsAccepted(): void
    {
        $this->validator()->validate(
            self::VALID_HTML,
            ".report-design-analytics .pdf-quick-read__row { margin: 2mm 0; }\n"
        );

        self::assertTrue(true);
    }

    public function testKnownVariablesCannotBeRenderedAsAdditionalContent(): void
    {
        $html = str_replace(
            '[COMPONENT:cover]',
            '[[project.name]][COMPONENT:cover]',
            self::VALID_HTML
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'may not render scalar variables outside trusted report components'
        );

        $this->validator()->validate($html, '');
    }

    public function testUnknownVariableIsRejected(): void
    {
        $html = str_replace(
            '[COMPONENT:cover]',
            '[[project.secret]][COMPONENT:cover]',
            self::VALID_HTML
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unavailable variable');

        $this->validator()->validate($html, '');
    }

    public function testTwigIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Twig syntax');

        $this->validator()->validate(
            self::VALID_HTML."\n{{ source('/etc/passwd') }}",
            ''
        );
    }

    public function testUnknownComponentIsRejected(): void
    {
        $html = str_replace(
            '[COMPONENT:cover]',
            '[COMPONENT:secret]',
            self::VALID_HTML
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown component');

        $this->validator()->validate($html, '');
    }

    public function testMissingRequiredComponentIsRejected(): void
    {
        $html = str_replace(
            '[COMPONENT:final_conclusion]',
            '',
            self::VALID_HTML
        );

        $this->expectException(\InvalidArgumentException::class);

        $this->validator()->validate($html, '');
    }

    public function testInventedTextIsRejected(): void
    {
        $html = str_replace(
            '[COMPONENT:cover]',
            'Texto inventado[COMPONENT:cover]',
            self::VALID_HTML
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden HTML or textual content');

        $this->validator()->validate($html, '');
    }

    public function testFontFamilyOverrideIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base font family or line height');

        $this->validator()->validate(
            self::VALID_HTML,
            '.report-cover { font-family: Georgia, serif; }'
        );
    }

    public function testFontShorthandOverrideIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base font family or line height');

        $this->validator()->validate(
            self::VALID_HTML,
            '.report-cover { font: 16px Georgia, serif; }'
        );
    }

    public function testLineHeightOverrideIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base font family or line height');

        $this->validator()->validate(
            self::VALID_HTML,
            '.report-cover { line-height: 1.8; }'
        );
    }

    public function testRemoteCssResourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator()->validate(
            self::VALID_HTML,
            '.x { background-image: url("https://example.com/a.png"); }'
        );
    }

    public function testCssCannotBreakOutOfStyleElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator()->validate(
            self::VALID_HTML,
            '</style><script>alert(1)</script>'
        );
    }

    public function testWrappersAroundComponentsAreRejected(): void
    {
        $html = str_replace(
            '[COMPONENT:cover]',
            '<section class="wrapper">[COMPONENT:cover]</section>',
            self::VALID_HTML
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'forbidden HTML or textual content'
        );

        $this->validator()->validate($html, '');
    }

    public function testScopedVisualCssIsAccepted(): void
    {
        $this->validator()->validate(
            self::VALID_HTML,
            '.report-design-cover { background: #173f35; color: #f4edda; border: 2px solid #d8c99f; }'
            .' .report-design-ai-report .pdf-ai-page__category-card { background: #f4eee1; border: 1px solid #c9b98f; font-size: 1.1em; }'
        );

        self::assertTrue(true);
    }

    public function testFontSizeOnCoverTextDescendantsIsAccepted(): void
    {
        $this->validator()->validate(
            self::VALID_HTML,
            '.report-design-cover .pdf-cover-approved__summary-title { font-size: 16px; }'
            .' .report-design-cover .pdf-cover-commitment__heading h3 { font-size: 15px; }'
            .' .report-design-cover .pdf-cover-commitment__level { font-size: 14px; }'
            .' .report-design-cover .pdf-cover-commitment__meta td { font-size: 12px; }'
        );

        self::assertTrue(true);
    }

    public function testUnscopedCssSelectorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'selectors must be scoped'
        );

        $this->validator()->validate(
            self::VALID_HTML,
            '.pdf-page { color: #123456; }'
        );
    }

    public function testUniversalSelectorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'universal selectors'
        );

        $this->validator()->validate(
            self::VALID_HTML,
            '.report-design-cover * { color: #ffffff; }'
        );
    }

    public function testPhysicalGeometryCssIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'unsafe geometry properties'
        );

        $this->validator()->validate(
            self::VALID_HTML,
            '.report-design-cover { height: 400mm; }'
        );
    }

    public function testPaginationCssIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'pagination properties'
        );

        $this->validator()->validate(
            self::VALID_HTML,
            '.report-design-cover { page-break-after: always; }'
        );
    }

}
