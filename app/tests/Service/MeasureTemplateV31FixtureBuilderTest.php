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
                'verification_sources' => ['Listado / Invent.', 'Informe Técnico', 'Declaración Resp.'],
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
                    'question' => 'Pregunta de prueba',
                    'implementation' => 'Implementación de prueba',
                    'department_action_text' => 'Texto de acción',
                    'points' => 3,
                    'description' => 'Descripción de prueba',
                    'environmental_impacts' => ['Cambio Climático'],
                    'departments' => ['Producción', 'Dirección'],
                    'verification_sources' => [
                        ['priority' => 1, 'value' => 'Listado / Invent.'],
                        ['priority' => 2, 'value' => 'Informe Técnico'],
                        ['priority' => 3, 'value' => 'Declaración Resp.'],
                    ],
                    'ods' => ['7', '13'],
                    'triple_balance' => ['Ambiental (E)', 'Económico (M)'],
                ],
            ],
            'warnings' => [],
        ]);

        $sheet = $spreadsheet->getSheetByName('Plantilla estándar de medidas') ?? $spreadsheet->getActiveSheet();

        self::assertSame('Pregunta (futuro)', (string) $sheet->getCell('L1')->getValue());
        self::assertSame('Descripción', (string) $sheet->getCell('M1')->getValue());
        self::assertSame('Implementación', (string) $sheet->getCell('N1')->getValue());
        self::assertSame('Acción por departamento', (string) $sheet->getCell('O1')->getValue());
        self::assertSame('Acción por departamento EN (opcional)', (string) $sheet->getCell($sheet->getHighestColumn() . '1')->getValue());

        $report = $parser->parseSpreadsheet($spreadsheet);

        self::assertCount(1, $report->getRows());
        self::assertSame('Pregunta de prueba', $report->getRows()[0]['questionText']);
        self::assertSame('Implementación de prueba', $report->getRows()[0]['nameReview']);
        self::assertSame('Texto de acción', $report->getRows()[0]['departmentActionText']);
        self::assertSame('Medida de prueba', $report->getRows()[0]['name']);
        $departments = array_map('trim', explode(';', (string) $report->getRows()[0]['departments']));
        sort($departments);
        self::assertSame(['Dirección', 'Producción'], $departments);
        self::assertCount(3, $report->getRows()[0]['verificationSources']);
        self::assertSame(1, $report->getRows()[0]['verificationSources'][0]['priority']);
        self::assertSame('Listado / Invent.', $report->getRows()[0]['verificationSources'][0]['value']);
        self::assertSame(2, $report->getRows()[0]['verificationSources'][1]['priority']);
        self::assertSame('Informe Técnico', $report->getRows()[0]['verificationSources'][1]['value']);
        self::assertSame(3, $report->getRows()[0]['verificationSources'][2]['priority']);
        self::assertSame('Declaración Resp.', $report->getRows()[0]['verificationSources'][2]['value']);
    }

    public function testBuilderSortsMeasuresBySourceRowBeforeWriting(): void
    {
        $builder = new MeasureTemplateV31FixtureBuilder();
        $parser = new MeasureTemplateParser();

        $spreadsheet = $builder->build([
            'summary' => [
                'departments' => ['Producción'],
                'categories' => ['ENERGÍA'],
                'blocks' => ['Bloque A'],
                'environmental_impacts' => ['Cambio Climático'],
                'verification_sources' => ['Foto'],
                'ods' => ['7'],
                'triple_balance' => ['Ambiental (E)'],
            ],
            'measures' => [
                [
                    'source_row' => 20,
                    'protocol' => 'Be Green My Film',
                    'project_type' => 'rodaje',
                    'category' => 'ENERGÍA',
                    'block' => 'Bloque A',
                    'measure' => 'Medida tardía',
                    'question' => '',
                    'implementation' => '',
                    'department_action_text' => '',
                    'points' => 1,
                    'description' => '',
                    'environmental_impacts' => ['Cambio Climático'],
                    'departments' => ['Producción'],
                    'verification_sources' => [
                        ['priority' => 1, 'value' => 'Foto'],
                    ],
                    'ods' => ['7'],
                    'triple_balance' => ['Ambiental (E)'],
                ],
                [
                    'source_row' => 5,
                    'protocol' => 'Be Green My Film',
                    'project_type' => 'rodaje',
                    'category' => 'ENERGÍA',
                    'block' => 'Bloque A',
                    'measure' => 'Medida temprana',
                    'question' => '',
                    'implementation' => '',
                    'department_action_text' => '',
                    'points' => 2,
                    'description' => '',
                    'environmental_impacts' => ['Cambio Climático'],
                    'departments' => ['Producción'],
                    'verification_sources' => [
                        ['priority' => 1, 'value' => 'Foto'],
                    ],
                    'ods' => ['7'],
                    'triple_balance' => ['Ambiental (E)'],
                ],
            ],
            'warnings' => [],
        ]);

        $report = $parser->parseSpreadsheet($spreadsheet);

        self::assertCount(2, $report->getRows());
        self::assertSame('Medida temprana', $report->getRows()[0]['name']);
        self::assertSame('Medida tardía', $report->getRows()[1]['name']);
    }
}
