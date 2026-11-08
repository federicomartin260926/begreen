<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiReportPromptBuilder;
use App\Service\Ai\AiReportPromptConfiguration;
use App\Service\Ai\Dto\AiReportRequest;
use PHPUnit\Framework\TestCase;

final class AiReportPromptBuilderTest extends TestCase
{
    public function testFeaturedMeasureScopeUsesDedicatedInstructionsAndStructuredContext(): void
    {
        $request = new AiReportRequest(
            'en',
            [],
            'final_sustainability_report',
            [
                'scope' => 'featured_measure',
                'project' => ['name' => 'Project'],
                'measure' => [
                    'planMeasureId' => 10,
                    'implementation' => ['state' => 'pending'],
                ],
            ],
            ['featured_measure'],
        );
        $builder = new AiReportPromptBuilder(new AiReportPromptConfiguration(
            dirname(__DIR__, 3).'/config/ai_report_prompt.yaml',
        ));

        $instructions = $builder->buildInstructions($request);
        self::assertStringContainsString('exactly one featured sustainability measure', $instructions);
        self::assertStringContainsString('maximum of 2,000 characters', $instructions);
        self::assertStringContainsString('categoryKey is featured_measure', $instructions);
        self::assertStringContainsString('pending, in progress, implemented, not implemented', $instructions);
        self::assertStringContainsString('Do not use external knowledge', $instructions);

        $context = json_decode($builder->buildContext($request), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('en', $context['locale']);
        self::assertSame(['featured_measure'], $context['requiredSectionKeys']);
        self::assertSame('featured_measure', $context['report']['scope']);
        self::assertSame(10, $context['report']['measure']['planMeasureId']);
        self::assertArrayNotHasKey('emissions', $context['report']);
        self::assertArrayNotHasKey('documents', $context['report']);
    }
}
