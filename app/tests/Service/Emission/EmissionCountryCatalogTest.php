<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission;

use App\Service\Emission\EmissionCountryCatalog;
use App\Service\Emission\Water\WaterEmissionRequestMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EmissionCountryCatalogTest extends TestCase
{
    public function testLoadsCanonicalCountriesAndWasteRegions(): void
    {
        $catalog = new EmissionCountryCatalog();
        $choices = $catalog->choices('es');

        self::assertCount(217, $choices);
        self::assertCount(217, array_unique(array_keys($choices)));
        self::assertCount(217, array_unique(array_values($choices)));
        self::assertSame('Antillas Neerlandesas', $choices['ANT']);
        self::assertSame('Kosovo', $choices['XKX']);
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
        self::assertSame('AN', $catalog->iso2FromIso3('ANT'));
        self::assertSame('XK', $catalog->iso2FromIso3('XKX'));
        self::assertSame('ANT', $catalog->iso3FromIso2('AN'));
        self::assertSame('XKX', $catalog->iso3FromIso2('XK'));
    }

    public function testNonStandardCountriesAreAcceptedByIso2BasedEmissionFlows(): void
    {
        $mapper = new WaterEmissionRequestMapper();

        foreach (['ANT' => 'AN', 'XKX' => 'XK'] as $iso3 => $expectedCountry) {
            $input = $mapper->map(new Request([], [
                'startDate' => '2025-01-01',
                'endDate' => '2025-01-02',
                'country' => $iso3,
                'waterUseType' => 'sanitarios',
                'volumeInput' => '1',
                'volumeInputUnit' => 'm3',
                'destination' => 'sewer',
            ]));

            self::assertSame($expectedCountry, $input->country);
        }
    }
}
