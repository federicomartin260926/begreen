<?php

declare(strict_types=1);

namespace App\Tests\Service\FinalReport;

use App\Service\FinalReport\FinalReportRichText;
use PHPUnit\Framework\TestCase;

final class FinalReportRichTextTest extends TestCase
{
    public function testPlainTextIsPreservedAndEscapedInPdf(): void
    {
        self::assertSame("Uno\nDos", FinalReportRichText::sanitize("Uno\nDos", 100));
        self::assertSame("Uno<br>\nDos", FinalReportRichText::render("Uno\nDos"));
        self::assertSame('&lt; literal', FinalReportRichText::render('< literal'));
    }

    public function testAllowedFormattingSurvives(): void
    {
        self::assertSame('<p>Texto <strong>fuerte</strong></p><ul><li>Uno</li></ul>',
            FinalReportRichText::sanitize('<p>Texto <b class="x">fuerte</b></p><ul><li>Uno</li></ul>', 100));
    }

    public function testDangerousMarkupAndAttributesAreDiscarded(): void
    {
        $html = '<p onclick="alert(1)">Seguro<script>alert(1)</script><img src="x" onerror="evil()"></p>';
        self::assertSame('<p>Seguro</p>', FinalReportRichText::sanitize($html, 100));
    }

    public function testPlainLengthIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FinalReportRichText::sanitize('<p>'.str_repeat('X', 101).'</p>', 100);
    }
}
