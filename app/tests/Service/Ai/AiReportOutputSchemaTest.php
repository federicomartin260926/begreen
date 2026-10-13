<?php

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiReportOutputSchema;
use PHPUnit\Framework\TestCase;

final class AiReportOutputSchemaTest extends TestCase
{
    public function testUsesCompactArraySchemaIndependentOfCategoryCount(): void
    {
        $schema = (new AiReportOutputSchema())->get(
            ['category:1', 'category:2'],
            ['category:2'],
        );

        foreach (['categorySummaries', 'categoryFutureSummaries'] as $field) {
            $collection = $schema['properties'][$field];

            self::assertSame('array', $collection['type']);
            self::assertSame('object', $collection['items']['type']);
            self::assertFalse($collection['items']['additionalProperties']);
            self::assertSame(
                ['categoryKey', 'summary'],
                $collection['items']['required']
            );
            self::assertSame(
                ['categoryKey', 'summary'],
                array_keys($collection['items']['properties'])
            );
            self::assertSame(
                'string',
                $collection['items']['properties']['categoryKey']['type']
            );
            self::assertSame(
                'string',
                $collection['items']['properties']['summary']['type']
            );
        }
    }

    public function testFutureSchemaRemainsCompactWhenNoFutureCategoryExists(): void
    {
        $future = (new AiReportOutputSchema())
            ->get(['category:1'], [])['properties']['categoryFutureSummaries'];

        self::assertSame('array', $future['type']);
        self::assertSame('object', $future['items']['type']);
    }

    public function testLeavesNewListFormatReadyForStrictValidator(): void
    {
        $data = [
            'generalConclusion' => 'Conclusión.',
            'categorySummaries' => [
                ['categoryKey' => 'category:1', 'summary' => 'Resumen uno.'],
                ['categoryKey' => 'category:2', 'summary' => 'Resumen dos.'],
            ],
            'categoryFutureSummaries' => [
                ['categoryKey' => 'category:2', 'summary' => 'Horizonte dos.'],
            ],
            'finalConclusion' => 'Cierre.',
        ];

        self::assertSame(
            $data,
            (new AiReportOutputSchema())->toValidatorData($data)
        );
    }

    public function testKeepsLegacyObjectResponseCompatibility(): void
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

    public function testConvertsLegacyEmptyFutureObjectToEmptyList(): void
    {
        $data = (new AiReportOutputSchema())->toValidatorData([
            'generalConclusion' => 'Conclusión.',
            'categorySummaries' => [
                'category:1' => ['summary' => 'Resumen.'],
            ],
            'categoryFutureSummaries' => [],
            'finalConclusion' => 'Cierre.',
        ]);

        self::assertSame([], $data['categoryFutureSummaries']);
    }
}
