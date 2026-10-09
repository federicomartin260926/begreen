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
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Diésel', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Gasóleo B', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Biodiésel 100% (B100)', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'XTL (Biodiésel HVO)', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Gasolina', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Gas propano', 'unit' => 'kg'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Gas butano', 'unit' => 'kg'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Gasóleo', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Biodiésel ME (100% puro)', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'XTL (Biodiésel HVO)', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Gasolina', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Gas natural', 'unit' => 'm3'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Gas propano', 'unit' => 'kg'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Gas butano', 'unit' => 'kg'],
        ]);

        $configuration = (new EnergyUiCatalog($repository))->configuration();

        self::assertSame(['Proveedor A', 'Proveedor B'], $configuration['suppliers']);
        self::assertSame(
            ['GDO COGENERACIÓN ALTA EFICIENCIA', 'SIN GDO'],
            $configuration['labelings']
        );
        self::assertSame(['kWhPCS', 'm3'], $configuration['fuels']['ES']['Gas natural']);
        self::assertSame(['litros'], $configuration['fuels']['OUTSIDE']['Diésel']);
        $expectedSpainMatrix = [
            'generator' => ['Diésel', 'Gasóleo B', 'Biodiésel 100% (B100)', 'XTL (Biodiésel HVO)', 'Gasolina'],
            'heating' => ['Gas natural', 'Gas propano', 'Gas butano', 'Gasóleo B', 'Electricidad'],
            'cooking' => ['Gas natural', 'Gas propano', 'Gas butano', 'Electricidad'],
            'boiler' => ['Gas natural', 'Gas propano', 'Gas butano', 'Gasóleo B', 'Biodiésel 100% (B100)', 'XTL (Biodiésel HVO)', 'Electricidad'],
            'climate' => ['Electricidad', 'Gas natural'],
            'other' => ['Gas natural', 'Gasóleo B', 'Gas propano', 'Gas butano', 'Diésel', 'Biodiésel 100% (B100)', 'XTL (Biodiésel HVO)', 'Gasolina', 'Electricidad'],
        ];
        foreach ($expectedSpainMatrix as $equipment => $expectedFuels) {
            self::assertSame($expectedFuels, array_column($configuration['equipmentFuels']['ES'][$equipment], 'value'));
        }
        self::assertSame([
            'Diésel',
            'Gasóleo',
            'Biodiésel',
            'HVO',
            'Gasolina',
        ], array_column($configuration['equipmentFuels']['ES']['generator'], 'label'));
        self::assertSame([
            'Diésel',
            'Gasóleo',
            'Biodiésel ME (100% puro)',
            'XTL (Biodiésel HVO)',
            'Gasolina',
        ], array_column($configuration['equipmentFuels']['OUTSIDE']['generator'], 'value'));
        self::assertSame([
            'Gas natural',
            'Gas propano',
            'Gas butano',
            'Electricidad',
        ], array_column($configuration['equipmentFuels']['OUTSIDE']['cooking'], 'value'));
        self::assertSame([
            'Electricidad',
            'Gas natural',
        ], array_column($configuration['equipmentFuels']['OUTSIDE']['climate'], 'value'));
        self::assertSame(['kWh'], $configuration['equipmentFuels']['ES']['heating'][4]['units']);
    }
}
