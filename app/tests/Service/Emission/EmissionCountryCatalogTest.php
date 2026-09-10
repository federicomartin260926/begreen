<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission;

use App\Service\Emission\EmissionCountryCatalog;
use PHPUnit\Framework\TestCase;

final class EmissionCountryCatalogTest extends TestCase
{
    public function testLoadsCanonicalCountriesAndWasteRegions(): void
    {
        $catalog = new EmissionCountryCatalog();
        $choices = $catalog->choices('es');

        self::assertCount(215, $choices);
        self::assertCount(215, array_unique(array_keys($choices)));
        self::assertSame('España', $catalog->name('ESP'));
        self::assertSame('España', $catalog->wasteRegion('ESP'));
        self::assertSame('Fuera de España', $catalog->wasteRegion('FRA'));
        self::assertSame('FRA', $catalog->normalizeIso3(' fra '));
        self::assertSame('GBR', $catalog->normalizeIso3('GBR'));
        self::assertSame('DEU', $catalog->normalizeIso3('DEU'));
    }

    public function testRejectsIso3OutsideCanonicalCatalog(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EmissionCountryCatalog())->normalizeIso3('ZZZ');
    }

    public function testConvertsOnlyAtIso2Boundaries(): void
    {
        $catalog = new EmissionCountryCatalog();

        self::assertSame('ES', $catalog->iso2FromIso3('ESP'));
        self::assertSame('FRA', $catalog->iso3FromIso2('FR'));
    }
}
