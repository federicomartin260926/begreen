<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiReportOutputSchema;
use PHPUnit\Framework\TestCase;

final class AiReportOutputSchemaOpenAiTest extends TestCase
{
    public function testBindsEveryOpenAiCategoryExactlyOnce(): void
    {
        $schema = (new AiReportOutputSchema())->getForOpenAi(
            ['category:1', 'category:2'],
            ['category:2'],
        );

        $general = $schema['properties']['categorySummaries'];

        self::assertSame('object', $general['type']);
        self::assertFalse($general['additionalProperties']);
        self::assertSame(
            ['category:1', 'category:2'],
            $general['required'],
        );
        self::assertSame(
            ['category:1', 'category:2'],
            array_keys($general['properties']),
        );

        self::assertSame(
            ['summary'],
            $general['properties']['category:1']['required'],
        );
        self::assertFalse(
            $general['properties']['category:1']['additionalProperties'],
        );

        $future = $schema['properties']['categoryFutureSummaries'];

        self::assertSame('object', $future['type']);
        self::assertSame(['category:2'], $future['required']);
        self::assertSame(
            ['category:2'],
            array_keys($future['properties']),
        );
    }

    public function testOpenAiEmptyFutureCollectionIsStrictlyEmpty(): void
    {
        $future = (new AiReportOutputSchema())
            ->getForOpenAi(['category:1'], [])
            ['properties']['categoryFutureSummaries'];

        self::assertSame('array', $future['type']);
        self::assertSame(0, $future['minItems']);
        self::assertSame(0, $future['maxItems']);
        self::assertSame('object', $future['items']['type']);
        self::assertFalse($future['items']['additionalProperties']);
        self::assertSame(['categoryKey', 'summary'], $future['items']['required']);
        self::assertSame(['categoryKey', 'summary'], array_keys($future['items']['properties']));

        $encoded = json_encode($future, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"properties":[]', $encoded);
    }
}
