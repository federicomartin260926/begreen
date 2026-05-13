<?php

namespace App\Tests\Import;

use App\Service\Import\BeGreenMyFilmV23Parser;
use App\Service\Import\BeGreenMyFilmV23Report;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class BeGreenMyFilmV23ParserTest extends TestCase
{
    public function testReportFinalizesAsOkWithMockData(): void
    {
        $report = new BeGreenMyFilmV23Report();

        $row = 5;
        foreach (BeGreenMyFilmV23Report::EXPECTED_SCORE_DISTRIBUTION as $score => $count) {
            for ($i = 0; $i < $count; $i++) {
                $report->registerMeasure(['row' => $row++, 'score' => $score]);
            }
        }

        $report->finalize();

        self::assertSame('OK', $report->getStatus());
        self::assertSame(BeGreenMyFilmV23Report::EXPECTED_MEASURES, $report->getMeasureCount());
        self::assertSame(BeGreenMyFilmV23Report::EXPECTED_POINTS, $report->getTotalPoints());
        self::assertSame(BeGreenMyFilmV23Report::EXPECTED_SCORE_DISTRIBUTION, $report->getScoreDistribution());
    }

    public function testParserReadsReducedWorkbookAndDetectsMissingGlobalCounts(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plan de Sostenibilidad');

        $sheet->setCellValue('A1', 'IMPACTO AMBIENTAL');
        $sheet->setCellValue('G1', 'CATEGORÍA');
        $sheet->setCellValue('H1', 'MEDIDA');
        $sheet->setCellValue('I1', 'PTS');
        $sheet->setCellValue('J1', 'DEPARTAMENTO');
        $sheet->setCellValue('AF1', 'FUENTE DE VERIFICACIÓN');
        $sheet->setCellValue('AS1', 'ODS');
        $sheet->setCellValue('BJ1', 'TRIPLE BALANCE');
        $sheet->setCellValue('BM1', 'DESCRIPCIÓN DETALLADA');

        $sheet->setCellValue('A2', 'Cambio Climático');
        $sheet->setCellValue('B2', 'Agotamiento Recursos Nat.');
        $sheet->setCellValue('C2', 'Biodiversidad');
        $sheet->setCellValue('D2', 'Contaminación');
        $sheet->setCellValue('E2', 'Cambio Uso Suelo');
        $sheet->setCellValue('F2', 'Comunicación y Sensib.');
        $sheet->setCellValue('J2', 'Prod');
        $sheet->setCellValue('AF2', 'Factura / Albarán');
        $sheet->setCellValue('AG2', 'Foto');
        $sheet->setCellValue('AH2', 'Captura / Email');
        $sheet->setCellValue('AS2', '1');
        $sheet->setCellValue('AT2', '2');
        $sheet->setCellValue('BJ2', 'Ambiental');
        $sheet->setCellValue('BK2', 'Social');
        $sheet->setCellValue('BL2', 'Económico');

        $sheet->setCellValue('H3', 'ENERGÍA');
        $sheet->setCellValue('H4', 'Inventario y planificación');

        $sheet->setCellValue('A5', 'X');
        $sheet->setCellValue('G5', 'ENERGÍA');
        $sheet->setCellValue('H5', 'Reducir consumo energético');
        $sheet->setCellValue('I5', 5);
        $sheet->setCellValue('J5', 'X');
        $sheet->setCellValue('AF5', '1');
        $sheet->setCellValue('AG5', '2');
        $sheet->setCellValue('AH5', '3');
        $sheet->setCellValue('AS5', 'X');
        $sheet->setCellValue('BJ5', 'X');
        $sheet->setCellValue('BK5', 'X');
        $sheet->setCellValue('BM5', 'Descripción corta de prueba');

        $sheet->setCellValue('H255', 'TOTAL PUNTOS');
        $sheet->setCellValue('I255', 5);

        $parser = new BeGreenMyFilmV23Parser();
        $report = $parser->parseSpreadsheet($spreadsheet);

        self::assertSame(1, $report->getMeasureCount());
        self::assertSame('FAILED', $report->getStatus());
        self::assertNotEmpty($report->getErrors());
        $hasCountMismatch = false;
        foreach ($report->getErrors() as $error) {
            if (($error['code'] ?? null) === 'measure_count_mismatch') {
                $hasCountMismatch = true;
                break;
            }
        }
        self::assertTrue($hasCountMismatch);
    }
}
