<?php

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiReportOutputSchema;
use PHPUnit\Framework\TestCase;

final class AiReportOutputSchemaTest extends TestCase
{
    public function testBindsGeneralAndFutureSummariesToTheirExpectedCategories(): void
    {
        $schema = (new AiReportOutputSchema())->get(
            ['category:1', 'category:2'],
            ['category:2'],
        );

        $general = $schema['properties']['categorySummaries'];
        self::assertFalse($general['additionalProperties']);
        self::assertSame(['category:1', 'category:2'], $general['required']);
        self::assertSame(['category:1', 'category:2'], array_keys($general['properties']));

        $future = $schema['properties']['categoryFutureSummaries'];
        self::assertFalse($future['additionalProperties']);
        self::assertSame(['category:2'], $future['required']);
        self::assertSame(['category:2'], array_keys($future['properties']));
    }

    public function testFutureSchemaIsStrictAndEmptyWhenNoFutureCategoryExists(): void
    {
        $future = (new AiReportOutputSchema())->get(['category:1'], [])['properties']['categoryFutureSummaries'];

        self::assertSame('object', $future['type']);
        self::assertFalse($future['additionalProperties']);
        self::assertSame([], $future['required']);
        self::assertSame([], $future['properties']);
    }

    public function testConvertsBothStructuredCollectionsForTheStrictValidator(): void
    {
        $data = (new AiReportOutputSchema())->toValidatorData([
            'generalConclusion' => 'Conclusión.',
            'categorySummaries' => [
                'category:1' => ['summary' => 'Resumen uno.'],
                'category:2' => ['summary' => 'Resumen dos.'],
            ],
            'categoryFutureSummaries' => [
                'category:2' => ['summary' => 'Horizonte dos.'],
            ],
            'finalConclusion' => 'Cierre.',
        ]);

        self::assertSame([
            ['categoryKey' => 'category:1', 'summary' => 'Resumen uno.'],
            ['categoryKey' => 'category:2', 'summary' => 'Resumen dos.'],
        ], $data['categorySummaries']);
        self::assertSame([
            ['categoryKey' => 'category:2', 'summary' => 'Horizonte dos.'],
        ], $data['categoryFutureSummaries']);
    }

    public function testConvertsAnEmptyFutureObjectToAnEmptyValidatorCollection(): void
    {
        $data = (new AiReportOutputSchema())->toValidatorData([
            'generalConclusion' => 'Conclusión.',
            'categorySummaries' => ['category:1' => ['summary' => 'Resumen.']],
            'categoryFutureSummaries' => [],
            'finalConclusion' => 'Cierre.',
        ]);

        self::assertSame([], $data['categoryFutureSummaries']);
    }
}
