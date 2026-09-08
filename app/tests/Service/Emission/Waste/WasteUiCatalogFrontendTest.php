<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Waste;

use App\Service\Emission\Waste\WasteUiCatalog;
use PHPUnit\Framework\TestCase;

final class WasteUiCatalogFrontendTest extends TestCase
{
    public function testFrontendCatalogKeepsSpainAndOutsideSpainSeparated(): void
    {
        $catalog = (new WasteUiCatalog())->frontendCatalog();

        self::assertSame(['spain', 'outside_spain'], array_keys($catalog));
        self::assertCount(25, $catalog['spain']['types']);
        self::assertCount(14, $catalog['outside_spain']['types']);

        $spain = array_column($catalog['spain']['types'], null, 'value');
        $outside = array_column($catalog['outside_spain']['types'], null, 'value');

        self::assertArrayHasKey('Residuos químicos (peligrosos)', $spain);
        self::assertArrayNotHasKey('Residuos químicos (peligrosos)', $outside);
        self::assertTrue($spain['Textil']['hasSubactivity']);
        self::assertFalse($outside['Textil']['hasSubactivity']);
    }
}
