<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiReportOutputSchema;
use PHPUnit\Framework\TestCase;

final class AiReportOutputSchemaCardinalityTest extends TestCase
{
    public function testConstrainsCategoryCollectionsToExpectedCountAndKeys(): void
    {
        $schema = (new AiReportOutputSchema())->get(
            ['category:1', 'category:2', 'category:3'],
            ['category:2'],
        );

        $general = $schema['properties']['categorySummaries'];

        self::assertSame(3, $general['minItems']);
        self::assertSame(3, $general['maxItems']);
        self::assertSame(
            ['category:1', 'category:2', 'category:3'],
            $general['items']['properties']['categoryKey']['enum'],
        );

        $future = $schema['properties']['categoryFutureSummaries'];

        self::assertSame(1, $future['minItems']);
        self::assertSame(1, $future['maxItems']);
        self::assertSame(
            ['category:2'],
            $future['items']['properties']['categoryKey']['enum'],
        );
    }

    public function testEmptyFutureCollectionRequiresZeroItemsWithoutEmptyEnum(): void
    {
        $future = (new AiReportOutputSchema())
            ->get(['category:1'], [])
            ['properties']['categoryFutureSummaries'];

        self::assertSame(0, $future['minItems']);
        self::assertSame(0, $future['maxItems']);
        self::assertArrayNotHasKey(
            'enum',
            $future['items']['properties']['categoryKey'],
        );
    }
}
