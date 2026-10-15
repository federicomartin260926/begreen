<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignAiGenerator;
use PHPUnit\Framework\TestCase;

final class ReportDesignAiGeneratorTest extends TestCase
{
    private function prompt(): string
    {
        $generator = (new \ReflectionClass(ReportDesignAiGenerator::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($generator, 'instructions');

        return $method->invoke($generator);
    }

    public function testPromptContainsCoverSummaryTextTargets(): void
    {
        $prompt = $this->prompt();

        self::assertStringContainsString(
            '.report-design-cover .pdf-cover-approved__summary-title',
            $prompt
        );
        self::assertStringContainsString(
            '.report-design-cover .pdf-cover-approved__summary-table',
            $prompt
        );
        self::assertStringContainsString(
            '.report-design-cover .pdf-cover-approved__summary-table td',
            $prompt
        );
        self::assertStringContainsString(
            '.report-design-cover .pdf-cover-approved__summary-line',
            $prompt
        );
    }

    public function testPromptContainsCoverCommitmentTextTargets(): void
    {
        $prompt = $this->prompt();

        foreach (
            [
                '.report-design-cover .pdf-cover-commitment__heading h3',
                '.report-design-cover .pdf-cover-commitment__level',
                '.report-design-cover .pdf-cover-commitment__labels td',
                '.report-design-cover .pdf-cover-commitment__meta',
                '.report-design-cover .pdf-cover-commitment__meta td',
                '.report-design-cover .pdf-cover-commitment__message',
            ] as $target
        ) {
            self::assertStringContainsString($target, $prompt);
        }
    }

    public function testPromptContainsFinalCommitmentLabelAndTypographyRules(): void
    {
        $prompt = $this->prompt();

        self::assertStringContainsString(
            '.report-design-final-conclusion .pdf-final-page__commitment-label',
            $prompt
        );
        self::assertStringContainsString(
            'Do not set font-family, the font shorthand or line-height.',
            $prompt
        );
        self::assertStringContainsString(
            'Always return the COMPLETE resulting html and css',
            $prompt
        );
        self::assertStringContainsString(
            'apply font-size to its relevant descendant text targets',
            $prompt
        );
    }

    public function testPromptContainsSafeLogoTargetsAndCapabilities(): void
    {
        $prompt = $this->prompt();

        foreach (
            [
                '.report-design-cover .pdf-cover-company-logos',
                '.report-design-cover .pdf-cover-company-logos__cell',
                '.report-design-cover .pdf-cover-company-logos__image',
                'max-width from 8mm to 40mm !important',
                'height: auto !important',
                'Never use width',
                'may not grow beyond the safe inline size',
                'margin-left and margin-right',
                'padding-right from 0mm to 2.5mm',
            ] as $instruction
        ) {
            self::assertStringContainsString($instruction, $prompt);
        }
    }

    public function testPromptContainsLogoProhibitions(): void
    {
        $prompt = $this->prompt();

        foreach (
            [
                'Never upload, replace, remove, reorder, overlap, rotate',
                'never change their source files or aspect ratio',
                'current order and location',
            ] as $prohibition
        ) {
            self::assertStringContainsString($prohibition, $prompt);
        }
    }

    public function testHelpExplainsSafeLogoReductionInBothLanguages(): void
    {
        $spanish = file_get_contents(
            __DIR__.'/../../../translations/messages.es.yaml'
        );
        $english = file_get_contents(
            __DIR__.'/../../../translations/messages.en.yaml'
        );

        self::assertIsString($spanish);
        self::assertIsString($english);
        self::assertStringContainsString(
            'El tamaño de los logos puede reducirse dentro de límites seguros.',
            $spanish
        );
        self::assertStringContainsString(
            'no permite ampliarlos por encima del tamaño calculado automáticamente',
            $spanish
        );
        self::assertStringContainsString(
            'Logo size can be reduced within safe limits.',
            $english
        );
        self::assertStringContainsString(
            'does not allow them to be enlarged beyond the automatically calculated size',
            $english
        );
    }
}
