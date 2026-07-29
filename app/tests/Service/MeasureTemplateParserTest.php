<?php

namespace App\Tests\Service;

use App\Service\MeasureTemplateParser;
use App\Service\MeasureTemplateSchema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateParserTest extends TestCase
{
    public function testParserReadsFilmAndEventCatalogGamificationMessagesAndOdsOneWithoutCollisions(): void
    {
        $parser = new MeasureTemplateParser();
        $rowsByCatalog = [];

        foreach ([
            'film' => 'be_green_my_film_measures.xlsx',
            'event' => 'be_green_my_event_measures.xlsx',
        ] as $catalog => $filename) {
            $report = $parser->parseFile(__DIR__.'/../../public/fixtures/'.$filename);

            self::assertSame('OK', $report->getStatus(), json_encode($report->getErrors(), JSON_UNESCAPED_UNICODE));
            self::assertCount(200, $report->getRows());
            self::assertNotSame('', $report->getRows()[0]['gamificationMessage']);
            self::assertNotSame('', $report->getRows()[0]['gamificationMessageEn']);
            self::assertNotEmpty(array_filter(
                $report->getRows(),
                static fn (array $row): bool => in_array('1', MeasureTemplateSchema::splitMultiValueCell($row['odsItems']), true)
            ));

            $rowsByCatalog[$catalog] = $report->getRows();
        }

        self::assertSame('Be Green My Film', $rowsByCatalog['film'][0]['protocol']);
        self::assertSame('rodaje', $rowsByCatalog['film'][0]['projectType']);
        self::assertSame('Be Green My Event', $rowsByCatalog['event'][0]['protocol']);
        self::assertSame('evento', $rowsByCatalog['event'][0]['projectType']);

        $identities = [];
        foreach (array_merge($rowsByCatalog['film'], $rowsByCatalog['event']) as $row) {
            $identities[] = $row['protocol'].':'.$row['row'];
        }
        self::assertCount(400, array_unique($identities));
    }

    public function testParserReadsMatrixWorkbookWithSelectionColumns(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(MeasureTemplateSchema::SHEET_TITLE);

        $sheet->setCellValue('A1', 'Protocolo');
        $sheet->setCellValue('B1', 'Tipo de proyecto');
        $sheet->setCellValue('C1', 'Bloque');
        $sheet->setCellValue('D1', 'Categoría');
        $sheet->setCellValue('E1', 'Categoría GHG');
        $sheet->setCellValue('F1', 'Medida');
        $sheet->setCellValue('G1', 'Puntuación');
        $sheet->setCellValue('H1', 'Obligatoria');
        $sheet->setCellValue('I1', 'ESG');
        $sheet->setCellValue('J1', 'Alcance');
        $sheet->setCellValue('K1', 'Nombre revisión');
        $sheet->setCellValue('L1', 'Pregunta (futuro)');
        $sheet->setCellValue('M1', 'Descripción');
        $sheet->setCellValue('N1', 'Implementación');
        $sheet->setCellValue('O1', 'Acción por departamento');
        $sheet->setCellValue('P1', 'Impacto ambiental');
        $sheet->setCellValue('R1', 'Departamento');
        $sheet->setCellValue('U1', 'Fuente de verificación');
        $sheet->setCellValue('X1', 'ODS');
        $sheet->setCellValue('AA1', 'Triple balance');
        $sheet->setCellValue('AD1', 'Nombre EN (opcional)');
        $sheet->setCellValue('AE1', 'Nombre revisión EN (opcional)');
        $sheet->setCellValue('AF1', 'Pregunta (futuro) EN (opcional)');
        $sheet->setCellValue('AG1', 'Descripción EN (opcional)');
        $sheet->setCellValue('AH1', 'Implementación EN (opcional)');
        $sheet->setCellValue('AI1', 'Fuentes de verificación EN (opcional)');
        $sheet->setCellValue('AJ1', 'Acción por departamento EN (opcional)');

        $sheet->setCellValue('P2', 'Cambio Climático');
        $sheet->setCellValue('Q2', 'Recursos');
        $sheet->setCellValue('R2', 'Producción');
        $sheet->setCellValue('S2', 'Arte');
        $sheet->setCellValue('T2', 'Cámara');
        $sheet->setCellValue('U2', 'Foto');
        $sheet->setCellValue('V2', 'Factura / Albarán');
        $sheet->setCellValue('W2', 'Certif. / Licencia');
        $sheet->setCellValue('X2', '12');
        $sheet->setCellValue('Y2', '13');
        $sheet->setCellValue('AA2', 'Ambiental (E)');
        $sheet->setCellValue('AB2', 'Social (S)');
        $sheet->setCellValue('AC2', 'Económico (M)');

        $sheet->setCellValue('A3', 'peach - Peach');
        $sheet->setCellValue('B3', 'rodaje');
        $sheet->setCellValue('C3', 'peach__movilidad - Movilidad');
        $sheet->setCellValue('D3', 'Movilidad');
        $sheet->setCellValue('E3', 'Emisiones indirectas de GEI debido al transporte');
        $sheet->setCellValue('F3', 'Reducir consumo de combustible');
        $sheet->setCellValue('G3', 4);
        $sheet->setCellValue('H3', 'Sí');
        $sheet->setCellValue('I3', 'Ambiental');
        $sheet->setCellValue('J3', 'Alcance 1');
        $sheet->setCellValue('K3', 'Se redujo el consumo');
        $sheet->setCellValue('L3', '¿Realizarás un inventario?');
        $sheet->setCellValue('M3', 'Descripción de prueba');
        $sheet->setCellValue('N3', 'Implementación de prueba');
        $sheet->setCellValue('O3', 'Acción por departamento de prueba');
        $sheet->setCellValue('P3', 'X');
        $sheet->setCellValue('Q3', 'X');
        $sheet->setCellValue('R3', 'X');
        $sheet->setCellValue('S3', 'X');
        $sheet->setCellValue('T3', 'X');
        $sheet->setCellValue('U3', '1');
        $sheet->setCellValue('V3', '2');
        $sheet->setCellValue('W3', '3');
        $sheet->setCellValue('X3', 'X');
        $sheet->setCellValue('Y3', 'X');
        $sheet->setCellValue('AA3', 'X');
        $sheet->setCellValue('AB3', 'X');
        $sheet->setCellValue('AC3', 'X');
        $sheet->setCellValue('AD3', 'Peach EN');
        $sheet->setCellValue('AE3', 'Se redujo el consumo EN');
        $sheet->setCellValue('AF3', 'Will you do an inventory?');
        $sheet->setCellValue('AG3', 'Description EN');
        $sheet->setCellValue('AH3', 'Implementation EN');
        $sheet->setCellValue('AI3', '1. Foto | 2. Factura / Albarán | 3. Certif. / Licencia');
        $sheet->setCellValue('AJ3', 'Department action EN');

        $report = (new MeasureTemplateParser())->parseSpreadsheet($spreadsheet);

        self::assertSame('OK', $report->getStatus());
        self::assertCount(1, $report->getRows());

        $row = $report->getRows()[0];
        self::assertSame('peach - Peach', $row['protocol']);
        self::assertSame('rodaje', $row['projectType']);
        self::assertSame('peach__movilidad - Movilidad', $row['measureBlock']);
        self::assertSame('Reducir consumo de combustible', $row['name']);
        self::assertSame(4, $row['score']);
        self::assertSame('¿Realizarás un inventario?', $row['questionText']);
        self::assertSame('Acción por departamento de prueba', $row['departmentActionText']);
        self::assertSame('Department action EN', $row['departmentActionTextEn']);
        self::assertSame('Cambio Climático; Recursos', $row['impactAreas']);
        self::assertSame('Producción; Arte; Cámara', $row['departments']);
        self::assertSame('12; 13', $row['odsItems']);
        self::assertSame('Ambiental (E); Social (S); Económico (M)', $row['tripleBalanceAxes']);
        self::assertCount(3, $row['verificationSources']);
        self::assertSame(1, $row['verificationSources'][0]['priority']);
        self::assertSame('Foto', $row['verificationSources'][0]['value']);
        self::assertSame(2, $row['verificationSources'][1]['priority']);
        self::assertSame('Factura / Albarán', $row['verificationSources'][1]['value']);
        self::assertSame(3, $row['verificationSources'][2]['priority']);
        self::assertSame('Certif. / Licencia', $row['verificationSources'][2]['value']);
    }

    public function testParserReadsLegacyWorkbookWithMultiValueCells(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(MeasureTemplateSchema::SHEET_TITLE);

        $sheet->fromArray(array_values(MeasureTemplateSchema::headers()), null, 'A1');
        $sheet->fromArray([
            'Peach',
            'rodaje',
            'peach__movilidad - Movilidad',
            'Movilidad',
            'Emisiones indirectas de GEI debido al transporte',
            'Reducir consumo de combustible',
            'Se redujo el consumo',
            'Pregunta de prueba',
            'Mensaje de gamificación',
            'Descripción de prueba',
            'Implementación de medidas de movilidad',
            'Acción por departamento',
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
            'Gamification message',
            '',
            '',
            '',
            '',
            '',
            'Department action text',
        ], null, 'A2');

        $report = (new MeasureTemplateParser())->parseSpreadsheet($spreadsheet);

        self::assertSame('OK', $report->getStatus());
        self::assertCount(1, $report->getRows());
        self::assertSame('Peach', $report->getRows()[0]['protocol']);
        self::assertSame(4, $report->getRows()[0]['score']);
        self::assertCount(3, $report->getRows()[0]['verificationSources']);
        self::assertSame(2, $report->getRows()[0]['verificationSources'][1]['priority']);
        self::assertSame('Factura / Albarán', $report->getRows()[0]['verificationSources'][1]['value']);
        self::assertSame('Acción por departamento', $report->getRows()[0]['departmentActionText']);
        self::assertSame('Department action text', $report->getRows()[0]['departmentActionTextEn']);
    }

    public function testParserKeepsWorkingWhenDepartmentActionColumnsAreMissing(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(MeasureTemplateSchema::SHEET_TITLE);

        $legacyHeaders = array_values(array_filter(
            MeasureTemplateSchema::headers(),
            static fn (string $key): bool => !in_array($key, ['department_action_text', 'department_action_text_en'], true),
            ARRAY_FILTER_USE_KEY
        ));
        $sheet->fromArray($legacyHeaders, null, 'A1');
        $sheet->fromArray([
            'Peach',
            'rodaje',
            'peach__movilidad - Movilidad',
            'Movilidad',
            'Emisiones indirectas de GEI debido al transporte',
            'Reducir consumo de combustible',
            'Se redujo el consumo',
            'Pregunta de prueba',
            'Mensaje de gamificación',
            'Descripción de prueba',
            'Implementación de prueba',
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
            'Gamification message',
            '',
            '',
            '',
            '',
            '',
        ], null, 'A2');

        $report = (new MeasureTemplateParser())->parseSpreadsheet($spreadsheet);

        self::assertSame('OK', $report->getStatus());
        self::assertCount(1, $report->getRows());
        self::assertSame('', $report->getRows()[0]['departmentActionText']);
        self::assertSame('', $report->getRows()[0]['departmentActionTextEn']);
    }
}
