<?php

namespace App\Tests\Service;

use App\Service\MeasureTemplateV31Extractor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateV31ExtractorTest extends TestCase
{
    public function testExtractorBuildsStructuredSummaryAndWarnings(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plan de Sostenibilidad');

        $sheet->setCellValue('A1', 'IMPACTO AMBIENTAL');
        $sheet->setCellValue('G1', 'CATEGORÍA');
        $sheet->setCellValue('H1', 'MEDIDA');
        $sheet->setCellValue('I1', 'ACCIÓN DEPARTAMENTO');
        $sheet->setCellValue('J1', 'PTS');
        $sheet->setCellValue('K1', 'DEPARTAMENTO');
        $sheet->setCellValue('AG1', 'FUENTE DE VERIFICACIÓN');
        $sheet->setCellValue('AT1', 'ODS');
        $sheet->setCellValue('BK1', 'TRIPLE BALANCE');
        $sheet->setCellValue('BN1', 'DESCRIPCIÓN DETALLADA');

        $sheet->setCellValue('A2', 'Cambio Climático');
        $sheet->setCellValue('B2', 'Agotamiento Recursos Nat.');
        $sheet->setCellValue('K2', 'Producción');
        $sheet->setCellValue('L2', 'Dirección');
        $sheet->setCellValue('AG2', 'Factura / Albarán');
        $sheet->setCellValue('AH2', 'Foto');
        $sheet->setCellValue('AI2', 'Captura / Email');
        $sheet->setCellValue('AJ2', 'Declaración Resp.');
        $sheet->setCellValue('AK2', 'Informe Técnico');
        $sheet->setCellValue('AL2', 'Certif. / Licencia');
        $sheet->setCellValue('AM2', 'Listado / Invent.');
        $sheet->setCellValue('AN2', 'Ficha Técnica');
        $sheet->setCellValue('AO2', 'Contrato / Acuerdo');
        $sheet->setCellValue('AT2', '1');
        $sheet->setCellValue('AU2', '2');
        $sheet->setCellValue('BK2', 'Ambiental (E)');
        $sheet->setCellValue('BL2', 'Social (S)');
        $sheet->setCellValue('BM2', 'Económico (M)');

        $sheet->setCellValue('H3', 'ENERGÍA');
        $sheet->setCellValue('H4', 'Inventario y planificación');

        $sheet->setCellValue('G5', 'ENERGÍA');
        $sheet->setCellValue('H5', 'Realiza un inventario de consumo energético antes del rodaje por departamento.');
        $sheet->setCellValue('I5', 'Realiza un inventario de los equipos y consumos energéticos de tu departamento antes del rodaje.');
        $sheet->setCellValue('J5', '3');
        $sheet->setCellValue('K5', 'X');
        $sheet->setCellValue('L5', 'X');
        $sheet->setCellValue('A5', 'X');
        $sheet->setCellValue('AJ5', '3');
        $sheet->setCellValue('AK5', '2');
        $sheet->setCellValue('AM5', '1');
        $sheet->setCellValue('AT5', 'X');
        $sheet->setCellValue('BK5', 'X');
        $sheet->setCellValue('BN5', 'Descripción completa');

        $sheet->setCellValue('G6', 'ENERGÍA');
        $sheet->setCellValue('H6', 'Registra el consumo energético de oficinas, localizaciones y vehículos eléctricos.');
        $sheet->setCellValue('J6', '');

        $report = (new MeasureTemplateV31Extractor())->extractSpreadsheet($spreadsheet);

        self::assertSame(2, $report['summary']['measures_count']);
        self::assertSame(3, $report['summary']['total_points']);
        self::assertSame(['Producción', 'Dirección'], $report['summary']['departments']);
        self::assertSame(['ENERGÍA'], $report['summary']['categories']);
        self::assertSame(['ENERGÍA', 'Inventario y planificación'], $report['summary']['blocks']);
        self::assertSame(['Cambio Climático'], $report['summary']['environmental_impacts']);
        self::assertSame(['Listado / Invent.', 'Informe Técnico', 'Declaración Resp.'], $report['summary']['verification_sources']);
        self::assertSame(['1'], $report['summary']['ods']);
        self::assertSame(['Ambiental (E)'], $report['summary']['triple_balance']);

        self::assertCount(2, $report['measures']);
        self::assertSame('Inventario y planificación', $report['measures'][0]['block']);
        self::assertSame('Realiza un inventario de los equipos y consumos energéticos de tu departamento antes del rodaje.', $report['measures'][0]['department_action_text']);
        self::assertSame(['Cambio Climático'], $report['measures'][0]['environmental_impacts']);
        self::assertSame(['Producción', 'Dirección'], $report['measures'][0]['departments']);
        self::assertSame([
            ['priority' => 1, 'value' => 'Listado / Invent.'],
            ['priority' => 2, 'value' => 'Informe Técnico'],
            ['priority' => 3, 'value' => 'Declaración Resp.'],
        ], $report['measures'][0]['verification_sources']);

        self::assertSame(4, count($report['warnings']));
        self::assertSame('missing_points', $report['warnings'][0]['code']);
        self::assertSame(6, $report['warnings'][0]['row']);
        self::assertSame(['missing_points', 'missing_department_action_text', 'missing_description', 'missing_departments'], $report['summary']['rows_with_issues'][0]['issues']);
    }
}
