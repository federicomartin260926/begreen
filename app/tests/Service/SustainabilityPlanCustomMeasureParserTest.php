<?php

namespace App\Tests\Service;

use App\Service\SustainabilityPlanCustomMeasureParser;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanCustomMeasureParserTest extends TestCase
{
    public function testParseCustomMeasuresSupportsScoreAndState(): void
    {
        $parser = new SustainabilityPlanCustomMeasureParser();

        $items = $parser->parse("Instalar paneles solares | Reducir consumo | 5 | implemented\nMedida simple");

        self::assertCount(2, $items);
        self::assertSame('Instalar paneles solares', $items[0]['title']);
        self::assertSame('Reducir consumo', $items[0]['description']);
        self::assertSame(5, $items[0]['score']);
        self::assertSame('implemented', $items[0]['state']);
        self::assertSame('Medida simple', $items[1]['title']);
        self::assertSame('planned', $items[1]['state']);
    }
}
