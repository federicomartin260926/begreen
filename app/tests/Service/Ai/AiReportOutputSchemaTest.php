<?php

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiReportOutputSchema;
use PHPUnit\Framework\TestCase;

final class AiReportOutputSchemaTest extends TestCase
{
    public function testBindsCategorySummariesToEveryExpectedCategory(): void
    {
        $schema = (new AiReportOutputSchema())->get(['category:1', 'category:2']);
        $categorySchema = $schema['properties']['categorySummaries'];

        self::assertSame('object', $categorySchema['type']);
        self::assertFalse($categorySchema['additionalProperties']);
        self::assertSame(['category:1', 'category:2'], $categorySchema['required']);
        self::assertSame(['category:1', 'category:2'], array_keys($categorySchema['properties']));
    }

    public function testConvertsCompleteStructuredOutputForTheStrictValidator(): void
    {
        $data = (new AiReportOutputSchema())->toValidatorData([
            'generalConclusion' => 'Conclusión.',
            'categorySummaries' => [
                'category:1' => ['summary' => 'Resumen uno.'],
                'category:2' => ['summary' => 'Resumen dos.'],
            ],
            'finalConclusion' => 'Cierre.',
        ]);

        self::assertSame([
            ['categoryKey' => 'category:1', 'summary' => 'Resumen uno.'],
            ['categoryKey' => 'category:2', 'summary' => 'Resumen dos.'],
        ], $data['categorySummaries']);
    }
}
