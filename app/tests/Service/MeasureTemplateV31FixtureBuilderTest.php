<?php

namespace App\Tests\Service;

use App\Service\MeasureTemplateParser;
use App\Service\MeasureTemplateV31FixtureBuilder;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateV31FixtureBuilderTest extends TestCase
{
    public function testBuilderPlacesDepartmentActionTextColumnsAndProducesParsableWorkbook(): void
    {
        $builder = new MeasureTemplateV31FixtureBuilder();
        $parser = new MeasureTemplateParser();

        $spreadsheet = $builder->build([
            'summary' => [
                'departments' => ['Producción', 'Dirección'],
                'categories' => ['ENERGÍA'],
                'blocks' => ['Inventario y planificación'],
                'environmental_impacts' => ['Cambio Climático'],
                'verification_sources' => [],
                'ods' => ['7', '13'],
                'triple_balance' => ['Ambiental (E)', 'Económico (M)'],
            ],
            'measures' => [
                [
                    'source_row' => 5,
                    'protocol' => 'Be Green My Film',
                    'project_type' => 'rodaje',
                    'category' => 'ENERGÍA',
                    'block' => 'Inventario y planificación',
                    'measure' => 'Medida de prueba',
                    'department_action_text' => 'Texto de acción',
                    'points' => 3,
                    'description' => 'Descripción de prueba',
                    'environmental_impacts' => ['Cambio Climático'],
                    'departments' => ['Producción', 'Dirección'],
                    'verification_sources' => [],
                    'ods' => ['7', '13'],
                    'triple_balance' => ['Ambiental (E)', 'Económico (M)'],
                ],
            ],
            'warnings' => [],
        ]);

        $sheet = $spreadsheet->getSheetByName('Plantilla estándar de medidas') ?? $spreadsheet->getActiveSheet();

        self::assertSame('Implementación', (string) $sheet->getCell('M1')->getValue());
        self::assertSame('Acción por departamento', (string) $sheet->getCell('N1')->getValue());
        self::assertSame('Acción por departamento EN (opcional)', (string) $sheet->getCell($sheet->getHighestColumn() . '1')->getValue());

        $report = $parser->parseSpreadsheet($spreadsheet);

        self::assertCount(1, $report->getRows());
        self::assertSame('Texto de acción', $report->getRows()[0]['departmentActionText']);
        self::assertSame('Medida de prueba', $report->getRows()[0]['name']);
        $departments = array_map('trim', explode(';', (string) $report->getRows()[0]['departments']));
        sort($departments);
        self::assertSame(['Dirección', 'Producción'], $departments);
    }
}
