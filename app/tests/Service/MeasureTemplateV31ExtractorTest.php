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
        $sheet->setCellValue('H1', 'PREGUNTA');
        $sheet->setCellValue('I1', 'IMPLEMENTACIÓN');
        $sheet->setCellValue('J1', 'MEDIDA');
        $sheet->setCellValue('K1', 'ACCIÓN DEPARTAMENTO');
        $sheet->setCellValue('L1', 'PTS');
        $sheet->setCellValue('M1', 'DEPARTAMENTO');
        $sheet->setCellValue('AI1', 'FUENTE DE VERIFICACIÓN');
        $sheet->setCellValue('AV1', 'ODS');
        $sheet->setCellValue('BM1', 'TRIPLE BALANCE');
        $sheet->setCellValue('BP1', 'DESCRIPCIÓN DETALLADA');

        $sheet->setCellValue('A2', 'Cambio Climático');
        $sheet->setCellValue('B2', 'Agotamiento Recursos Nat.');
        $sheet->setCellValue('M2', 'Producción');
        $sheet->setCellValue('N2', 'Dirección');
        $sheet->setCellValue('AI2', 'Factura / Albarán');
        $sheet->setCellValue('AJ2', 'Foto');
        $sheet->setCellValue('AK2', 'Captura / Email');
        $sheet->setCellValue('AL2', 'Declaración Resp.');
        $sheet->setCellValue('AM2', 'Informe Técnico');
        $sheet->setCellValue('AN2', 'Certif. / Licencia');
        $sheet->setCellValue('AO2', 'Listado / Invent.');
        $sheet->setCellValue('AP2', 'Ficha Técnica');
        $sheet->setCellValue('AQ2', 'Contrato / Acuerdo');
        $sheet->setCellValue('AR2', 'Doc. Producción');
        $sheet->setCellValue('AS2', 'Plan / Protocolo');
        $sheet->setCellValue('AT2', 'Acta / Registro');
        $sheet->setCellValue('AU2', 'Permiso Admin.');
        $sheet->setCellValue('AV2', '1');
        $sheet->setCellValue('AW2', '2');
        $sheet->setCellValue('AX2', '3');
        $sheet->setCellValue('AY2', '4');
        $sheet->setCellValue('AZ2', '5');
        $sheet->setCellValue('BA2', '6');
        $sheet->setCellValue('BB2', '7');
        $sheet->setCellValue('BC2', '8');
        $sheet->setCellValue('BD2', '9');
        $sheet->setCellValue('BE2', '10');
        $sheet->setCellValue('BF2', '11');
        $sheet->setCellValue('BG2', '12');
        $sheet->setCellValue('BH2', '13');
        $sheet->setCellValue('BI2', '14');
        $sheet->setCellValue('BJ2', '15');
        $sheet->setCellValue('BK2', '16');
        $sheet->setCellValue('BL2', '17');
        $sheet->setCellValue('BM2', 'Ambiental (E)');
        $sheet->setCellValue('BN2', 'Social (S)');
        $sheet->setCellValue('BO2', 'Económico (M)');
        $sheet->setCellValue('BP2', 'Descripción detallada');

        $sheet->setCellValue('H3', '¿Realizarás un inventario?');
        $sheet->setCellValue('I3', 'Realizaste un inventario');
        $sheet->setCellValue('J3', 'ENERGÍA');
        $sheet->setCellValue('H4', '¿Registrarás el consumo?');
        $sheet->setCellValue('I4', 'Registraste el consumo');
        $sheet->setCellValue('J4', 'Inventario y planificación');

        $sheet->setCellValue('G5', 'ENERGÍA');
        $sheet->setCellValue('H5', '¿Realizarás un inventario de consumo energético antes del rodaje por departamento?');
        $sheet->setCellValue('I5', '¿Realizaste un inventario de consumo energético antes del rodaje por departamento?');
        $sheet->setCellValue('J5', 'Realiza un inventario de consumo energético antes del rodaje por departamento.');
        $sheet->setCellValue('K5', 'Realiza un inventario de los equipos y consumos energéticos de tu departamento antes del rodaje.');
        $sheet->setCellValue('L5', '3');
        $sheet->setCellValue('M5', 'X');
        $sheet->setCellValue('N5', 'X');
        $sheet->setCellValue('A5', 'X');
        $sheet->setCellValue('AL5', '3');
        $sheet->setCellValue('AM5', '2');
        $sheet->setCellValue('AO5', '1');
        $sheet->setCellValue('AV5', 'X');
        $sheet->setCellValue('BM5', 'X');
        $sheet->setCellValue('BP5', 'Descripción completa');

        $sheet->setCellValue('G6', 'ENERGÍA');
        $sheet->setCellValue('H6', '¿Registrarás el consumo energético de oficinas, localizaciones y vehículos eléctricos?');
        $sheet->setCellValue('I6', 'Registraste el consumo energético de oficinas, localizaciones y vehículos eléctricos');
        $sheet->setCellValue('J6', 'Registra el consumo energético de oficinas, localizaciones y vehículos eléctricos.');
        $sheet->setCellValue('L6', '');

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
        self::assertSame('¿Realizarás un inventario de consumo energético antes del rodaje por departamento?', $report['measures'][0]['question']);
        self::assertSame('¿Realizaste un inventario de consumo energético antes del rodaje por departamento?', $report['measures'][0]['implementation']);
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
