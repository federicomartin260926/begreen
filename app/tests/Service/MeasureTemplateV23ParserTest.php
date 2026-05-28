<?php

namespace App\Tests\Service;

use App\Service\MeasureTemplateV23Parser;
use App\Service\MeasureTemplateV23Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateV23ParserTest extends TestCase
{
    public function testParserReadsStandardV23WorkbookWithMultiValueCells(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(MeasureTemplateV23Schema::SHEET_TITLE);

        $sheet->fromArray(array_values(MeasureTemplateV23Schema::headers()), null, 'A1');
        $sheet->fromArray([
            'Peach',
            'rodaje',
            'peach__movilidad - Movilidad',
            'Movilidad',
            'Emisiones indirectas de GEI debido al transporte',
            'Reducir consumo de combustible',
            'Se redujo el consumo',
            'Plan de movilidad',
            'Implementación de medidas de movilidad',
            4,
            'Sí',
            'prod; post',
            'ODS12; ODS13',
            'Ambiental',
            'Alcance 1',
            'a; b',
            'ambiental; social',
            '1. Foto | 2. Factura / Albarán | 3. Certif. / Licencia',
            '',
            '',
            '',
            '',
            '',
        ], null, 'A2');

        $report = (new MeasureTemplateV23Parser())->parseSpreadsheet($spreadsheet);

        self::assertSame('OK', $report->getStatus());
        self::assertCount(1, $report->getRows());
        self::assertSame('Peach', $report->getRows()[0]['protocol']);
        self::assertSame(4, $report->getRows()[0]['score']);
        self::assertCount(3, $report->getRows()[0]['verificationSources']);
        self::assertSame(2, $report->getRows()[0]['verificationSources'][1]['priority']);
        self::assertSame('Factura / Albarán', $report->getRows()[0]['verificationSources'][1]['value']);
    }
}
