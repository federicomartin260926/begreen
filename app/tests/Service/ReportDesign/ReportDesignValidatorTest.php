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
<section class="report-cover">
    [[project.name]]
    [COMPONENT:cover]
</section>
<section class="report-summary">
    <span class="score">[[score.percent]]</span>
    [COMPONENT:analytics]
    [COMPONENT:ai_report]
</section>
<div class="report-detail">
    [COMPONENT:departments]
</div>
<section class="report-closing">
    [COMPONENT:final_conclusion]
</section>
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
            ".report-summary { margin: 10mm 0; }\n"
        );

        self::assertTrue(true);
    }

    public function testKnownVariablesCanBeUsed(): void
    {
        $html = str_replace(
            '[[project.name]]',
            '[[project.name]][[ai.general_conclusion]]',
            self::VALID_HTML
        );

        $this->validator()->validate($html, '');

        self::assertTrue(true);
    }

    public function testUnknownVariableIsRejected(): void
    {
        $html = str_replace(
            '[[project.name]]',
            '[[project.secret]]',
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
            '<section class="report-cover">',
            '<section class="report-cover">Texto inventado',
            self::VALID_HTML
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden HTML or textual content');

        $this->validator()->validate($html, '');
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
}
