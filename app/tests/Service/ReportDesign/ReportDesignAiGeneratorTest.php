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
}
