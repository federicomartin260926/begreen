<?php

namespace App\Tests\Service\Emission\Energy;

use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Energy\EnergyUiCatalog;
use PHPUnit\Framework\TestCase;

final class EnergyUiCatalogTest extends TestCase
{
    public function testBuildsSupplierFuelAndUnitChoicesFromFactorCriteria(): void
    {
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findCriteriaByCategoryKey')->with('energy')->willReturn([
            ['category' => 'ELECTRICIDAD', 'supplier' => 'Proveedor B', 'labeling' => 'GDO COGENERACIÓN ALTA EFICIENCIA'],
            ['category' => 'ELECTRICIDAD', 'supplier' => 'Proveedor A', 'labeling' => 'SIN GDO'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Gas natural', 'unit' => 'm3'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Gas natural', 'unit' => 'kWhPCS'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Diésel', 'unit' => 'litros'],
        ]);

        $configuration = (new EnergyUiCatalog($repository))->configuration();

        self::assertSame(['Proveedor A', 'Proveedor B'], $configuration['suppliers']);
        self::assertSame(
            ['GDO COGENERACIÓN ALTA EFICIENCIA', 'SIN GDO'],
            $configuration['labelings']
        );
        self::assertSame(['kWhPCS', 'm3'], $configuration['fuels']['ES']['Gas natural']);
        self::assertSame(['litros'], $configuration['fuels']['OUTSIDE']['Diésel']);
    }
}
