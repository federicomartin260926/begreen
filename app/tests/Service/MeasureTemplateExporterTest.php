<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryGhg;
use App\Entity\Department;
use App\Entity\EsG;
use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\MeasureVerificationSource;
use App\Entity\MeasureBlock;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\Scope;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use App\Service\MeasureTemplateExporter;
use App\Service\MeasureTemplateSchema;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateExporterTest extends TestCase
{
    public function testBuildSpreadsheetCreatesMatrixTemplateWithGroupedHeadersAndSelectionMarkers(): void
    {
        $protocol = (new Protocol())
            ->setCode('peach')
            ->setName('Peach')
            ->setType(Protocol::TYPE_RODAJE);

        $category = (new Category())->setName('Movilidad');
        $categoryGhg = (new CategoryGhg())->setName('Emisiones indirectas de GEI debido al transporte');
        $esg = (new EsG())->setName('Ambiental');
        $scope = (new Scope())->setName('Alcance 1');

        $impactAreaA = (new ImpactArea())->setCode('a')->setName('Cambio Climático');
        $impactAreaB = (new ImpactArea())->setCode('b')->setName('Recursos');

        $departmentProd = (new Department())->setCode('prod')->setName('Producción');
        $departmentArt = (new Department())->setCode('art')->setName('Arte');
        $departmentCam = (new Department())->setCode('cam')->setName('Cámara');

        $ods1 = (new Ods())->setCode('ODS1')->setName('Fin de la pobreza');
        $ods2 = (new Ods())->setCode('ODS2')->setName('Hambre cero');
        $ods10 = (new Ods())->setCode('ODS10')->setName('Reducción de las desigualdades');

        $axisEnv = (new TripleBalanceAxis())->setCode('ambiental')->setName('Ambiental');
        $axisSoc = (new TripleBalanceAxis())->setCode('social')->setName('Social');
        $axisEco = (new TripleBalanceAxis())->setCode('economico')->setName('Económico');

        $sourceFoto = (new VerificationSource())->setCode('foto')->setName('Foto');
        $sourceFactura = (new VerificationSource())->setCode('factura')->setName('Factura / Albarán');
        $sourceCert = (new VerificationSource())->setCode('certificado')->setName('Certif. / Licencia');

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('peach__movilidad')
            ->setName('Movilidad')
            ->setSortOrder(1);

        $spreadsheet = (new MeasureTemplateExporter())->buildSpreadsheet([
            'protocols' => [$protocol],
            'categories' => [$category],
            'categoryGhgs' => [$categoryGhg],
            'esg' => [$esg],
            'scopes' => [$scope],
            'impactAreas' => [$impactAreaA, $impactAreaB],
            'departments' => [$departmentProd, $departmentArt, $departmentCam],
            'ods' => [$ods1, $ods10, $ods2],
            'tripleBalanceAxes' => [$axisEnv, $axisSoc, $axisEco],
            'verificationSources' => [$sourceFoto, $sourceFactura, $sourceCert],
            'measureBlocks' => [$block],
        ]);

        $sheet = $spreadsheet->getActiveSheet();
        $listSheet = $spreadsheet->getSheetByName(MeasureTemplateSchema::LISTS_SHEET);

        self::assertSame(MeasureTemplateSchema::SHEET_TITLE, $sheet->getTitle());
        self::assertSame('Protocolo', (string) $sheet->getCell('A1')->getValue());
        self::assertSame('Tipo de proyecto', (string) $sheet->getCell('B1')->getValue());
        self::assertSame('Bloque', (string) $sheet->getCell('C1')->getValue());
        self::assertSame('Categoría', (string) $sheet->getCell('D1')->getValue());
        self::assertSame('Medida', (string) $sheet->getCell('F1')->getValue());
        self::assertSame('Pregunta (futuro)', (string) $sheet->getCell('L1')->getValue());
        self::assertSame('Acción por departamento', (string) $sheet->getCell('O1')->getValue());
        self::assertSame('Impacto ambiental', (string) $sheet->getCell('P1')->getValue());
        self::assertSame('Departamento', (string) $sheet->getCell('R1')->getValue());
        self::assertSame('Fuente de verificación', (string) $sheet->getCell('U1')->getValue());
        self::assertSame('ODS', (string) $sheet->getCell('X1')->getValue());
        self::assertSame('Triple balance', (string) $sheet->getCell('AA1')->getValue());
        self::assertSame('Nombre EN (opcional)', (string) $sheet->getCell('AD1')->getValue());
        self::assertSame('Pregunta (futuro) EN (opcional)', (string) $sheet->getCell('AF1')->getValue());
        self::assertSame('Acción por departamento EN (opcional)', (string) $sheet->getCell('AJ1')->getValue());

        self::assertSame('', (string) $sheet->getCell('A2')->getValue());
        self::assertSame('', (string) $sheet->getCell('B2')->getValue());
        self::assertSame('', (string) $sheet->getCell('C2')->getValue());
        self::assertSame('', (string) $sheet->getCell('D2')->getValue());
        self::assertSame('', (string) $sheet->getCell('G2')->getValue());
        self::assertSame('', (string) $sheet->getCell('H2')->getValue());
        self::assertSame('', (string) $sheet->getCell('I2')->getValue());
        self::assertSame('', (string) $sheet->getCell('J2')->getValue());
        self::assertSame('a - Cambio Climático', (string) $sheet->getCell('P2')->getValue());
        self::assertSame('b - Recursos', (string) $sheet->getCell('Q2')->getValue());
        self::assertSame('Arte', (string) $sheet->getCell('R2')->getValue());
        self::assertSame('Cámara', (string) $sheet->getCell('S2')->getValue());
        self::assertSame('Producción', (string) $sheet->getCell('T2')->getValue());
        self::assertSame('Certif. / Licencia', (string) $sheet->getCell('U2')->getValue());
        self::assertSame('Factura / Albarán', (string) $sheet->getCell('V2')->getValue());
        self::assertSame('Foto', (string) $sheet->getCell('W2')->getValue());
        self::assertSame('1', (string) $sheet->getCell('X2')->getValue());
        self::assertSame('2', (string) $sheet->getCell('Y2')->getValue());
        self::assertSame('10', (string) $sheet->getCell('Z2')->getValue());
        self::assertSame('Ambiental (E)', (string) $sheet->getCell('AA2')->getValue());
        self::assertSame('Económico (M)', (string) $sheet->getCell('AB2')->getValue());
        self::assertSame('Social (S)', (string) $sheet->getCell('AC2')->getValue());

        self::assertArrayHasKey('A1:A2', $sheet->getMergeCells());
        self::assertArrayHasKey('O1:O2', $sheet->getMergeCells());
        self::assertArrayHasKey('P1:Q1', $sheet->getMergeCells());
        self::assertArrayHasKey('R1:T1', $sheet->getMergeCells());
        self::assertArrayHasKey('U1:W1', $sheet->getMergeCells());
        self::assertArrayHasKey('X1:Z1', $sheet->getMergeCells());
        self::assertArrayHasKey('AA1:AC1', $sheet->getMergeCells());

        self::assertNotNull($listSheet);
        self::assertSame('peach - Peach', (string) $listSheet->getCell('A1')->getValue());
        self::assertSame('rodaje', (string) $listSheet->getCell('B1')->getValue());
        self::assertSame('peach__movilidad - Movilidad', (string) $listSheet->getCell('C1')->getValue());
        self::assertSame('Movilidad', (string) $listSheet->getCell('D1')->getValue());
        self::assertSame('Ambiental', (string) $listSheet->getCell('F1')->getValue());
        self::assertSame('Alcance 1', (string) $listSheet->getCell('G1')->getValue());

        self::assertSame('between', $sheet->getCell('G3')->getDataValidation()->getOperator());
        self::assertSame('1', $sheet->getCell('G3')->getDataValidation()->getFormula1());
        self::assertSame('5', $sheet->getCell('G3')->getDataValidation()->getFormula2());
        self::assertSame('list', $sheet->getCell('I3')->getDataValidation()->getType());
        self::assertStringContainsString("'Listas'!\$F\$1:\$F\$", $sheet->getCell('I3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('J3')->getDataValidation()->getType());
        self::assertStringContainsString("'Listas'!\$G\$1:\$G\$", $sheet->getCell('J3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('P3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('P3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('R3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('R3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('U3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('U3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('X3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('X3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('AA3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('AA3')->getDataValidation()->getFormula1());
    }

    public function testSpreadsheetCanBeWrittenToXlsxFile(): void
    {
        $spreadsheet = (new MeasureTemplateExporter())->buildSpreadsheet([
            'protocols' => [],
            'categories' => [],
            'categoryGhgs' => [],
            'esg' => [],
            'scopes' => [],
            'impactAreas' => [],
            'departments' => [],
            'ods' => [],
            'tripleBalanceAxes' => [],
            'verificationSources' => [],
            'measureBlocks' => [],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'measure_template_');
        self::assertNotFalse($path);
        $xlsxPath = $path . '.xlsx';

        (new Xlsx($spreadsheet))->save($xlsxPath);

        self::assertFileExists($xlsxPath);
        self::assertGreaterThan(0, filesize($xlsxPath));

        @unlink($path);
        @unlink($xlsxPath);
    }

    public function testBuildMeasuresSpreadsheetExportsStandardLayoutAndTranslations(): void
    {
        $protocol = (new Protocol())
            ->setCode('peach')
            ->setName('Peach')
            ->setType(Protocol::TYPE_RODAJE);

        $category = (new Category())->setName('Movilidad');
        $categoryGhg = (new CategoryGhg())->setName('Emisiones indirectas de GEI debido al transporte');
        $esg = (new EsG())->setName('Ambiental');
        $scope = (new Scope())->setName('Alcance 1');

        $impactAreaA = (new ImpactArea())->setCode('a')->setName('Cambio Climático');
        $impactAreaB = (new ImpactArea())->setCode('b')->setName('Recursos');

        $departmentProd = (new Department())->setCode('prod')->setName('Producción');
        $departmentArt = (new Department())->setCode('art')->setName('Arte');
        $departmentCam = (new Department())->setCode('cam')->setName('Cámara');

        $ods1 = (new Ods())->setCode('ODS1')->setName('Fin de la pobreza');
        $ods2 = (new Ods())->setCode('ODS2')->setName('Hambre cero');
        $ods10 = (new Ods())->setCode('ODS10')->setName('Reducción de las desigualdades');

        $axisEnv = (new TripleBalanceAxis())->setCode('ambiental')->setName('Ambiental');
        $axisSoc = (new TripleBalanceAxis())->setCode('social')->setName('Social');
        $axisEco = (new TripleBalanceAxis())->setCode('economico')->setName('Económico');

        $sourceFoto = (new VerificationSource())->setCode('foto')->setName('Foto');
        $sourceFactura = (new VerificationSource())->setCode('factura')->setName('Factura / Albarán');
        $sourceCert = (new VerificationSource())->setCode('certificado')->setName('Certif. / Licencia');

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('peach__movilidad')
            ->setName('Movilidad')
            ->setSortOrder(1);

        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setMeasureBlock($block)
            ->setCategory($category)
            ->setCategoryGhg($categoryGhg)
            ->setName('Reducir consumo de combustible')
            ->setNameReview('Se redujo el consumo de combustible')
            ->setQuestionText('¿Realizarás un inventario?')
            ->setDescription('Descripción Peach')
            ->setImplementation('Impl Peach')
            ->setDepartmentActionText('Acción por departamento')
            ->setScore(5)
            ->setMandatory(true)
            ->setEsg($esg)
            ->setScope($scope);

        $measure->addImpactArea($impactAreaA);
        $measure->addDepartment($departmentArt);
        $measure->addDepartment($departmentProd);
        $measure->addOdsItem($ods10);
        $measure->addTripleBalanceAxis($axisEnv);
        $measure->addTripleBalanceAxis($axisSoc);

        $linkFoto = (new MeasureVerificationSource())
            ->setVerificationSource($sourceFoto)
            ->setPriority(1);
        $linkFoto->setMeasure($measure);
        $measure->addVerificationSourceLink($linkFoto);

        $linkFactura = (new MeasureVerificationSource())
            ->setVerificationSource($sourceFactura)
            ->setPriority(2);
        $linkFactura->setMeasure($measure);
        $measure->addVerificationSourceLink($linkFactura);

        $linkCert = (new MeasureVerificationSource())
            ->setVerificationSource($sourceCert)
            ->setPriority(3);
        $linkCert->setMeasure($measure);
        $measure->addVerificationSourceLink($linkCert);

        $translator = static fn (Measure $current): array => spl_object_hash($current) === spl_object_hash($measure)
            ? [
                'name' => 'Reducir consumo de combustible EN',
                'nameReview' => 'Fuel consumption was reduced',
                'questionText' => 'Will you do an inventory?',
                'description' => 'Description EN',
                'implementation' => 'Implementation EN',
                'verificationSources' => '1. Foto | 2. Factura / Albarán | 3. Certif. / Licencia',
                'departmentActionText' => 'Department action EN',
            ]
            : [];

        $spreadsheet = (new MeasureTemplateExporter())->buildMeasuresSpreadsheet([
            'protocols' => [$protocol],
            'categories' => [$category],
            'categoryGhgs' => [$categoryGhg],
            'esg' => [$esg],
            'scopes' => [$scope],
            'impactAreas' => [$impactAreaA, $impactAreaB],
            'departments' => [$departmentProd, $departmentArt, $departmentCam],
            'ods' => [$ods1, $ods10, $ods2],
            'tripleBalanceAxes' => [$axisEnv, $axisSoc, $axisEco],
            'verificationSources' => [$sourceFoto, $sourceFactura, $sourceCert],
            'measureBlocks' => [$block],
        ], [$measure], $translator);

        $sheet = $spreadsheet->getActiveSheet();

        self::assertSame('peach - Peach', (string) $sheet->getCell('A3')->getValue());
        self::assertSame('rodaje', (string) $sheet->getCell('B3')->getValue());
        self::assertSame('peach__movilidad - Movilidad', (string) $sheet->getCell('C3')->getValue());
        self::assertSame('Movilidad', (string) $sheet->getCell('D3')->getValue());
        self::assertSame('Emisiones indirectas de GEI debido al transporte', (string) $sheet->getCell('E3')->getValue());
        self::assertSame('Reducir consumo de combustible', (string) $sheet->getCell('F3')->getValue());
        self::assertSame('5', (string) $sheet->getCell('G3')->getValue());
        self::assertSame('Sí', (string) $sheet->getCell('H3')->getValue());
        self::assertSame('Ambiental', (string) $sheet->getCell('I3')->getValue());
        self::assertSame('Alcance 1', (string) $sheet->getCell('J3')->getValue());
        self::assertSame('Se redujo el consumo de combustible', (string) $sheet->getCell('K3')->getValue());
        self::assertSame('¿Realizarás un inventario?', (string) $sheet->getCell('L3')->getValue());
        self::assertSame('Descripción Peach', (string) $sheet->getCell('M3')->getValue());
        self::assertSame('Impl Peach', (string) $sheet->getCell('N3')->getValue());
        self::assertSame('Acción por departamento', (string) $sheet->getCell('O3')->getValue());

        self::assertSame('X', (string) $sheet->getCell('P3')->getValue());
        self::assertSame('', (string) $sheet->getCell('Q3')->getValue());
        self::assertSame('X', (string) $sheet->getCell('R3')->getValue());
        self::assertSame('', (string) $sheet->getCell('S3')->getValue());
        self::assertSame('X', (string) $sheet->getCell('T3')->getValue());
        self::assertSame('3', (string) $sheet->getCell('U3')->getValue());
        self::assertSame('2', (string) $sheet->getCell('V3')->getValue());
        self::assertSame('1', (string) $sheet->getCell('W3')->getValue());
        self::assertSame('', (string) $sheet->getCell('X3')->getValue());
        self::assertSame('', (string) $sheet->getCell('Y3')->getValue());
        self::assertSame('X', (string) $sheet->getCell('Z3')->getValue());
        self::assertSame('X', (string) $sheet->getCell('AA3')->getValue());
        self::assertSame('', (string) $sheet->getCell('AB3')->getValue());
        self::assertSame('X', (string) $sheet->getCell('AC3')->getValue());
        self::assertSame('Reducir consumo de combustible EN', (string) $sheet->getCell('AD3')->getValue());
        self::assertSame('Fuel consumption was reduced', (string) $sheet->getCell('AE3')->getValue());
        self::assertSame('Will you do an inventory?', (string) $sheet->getCell('AF3')->getValue());
        self::assertSame('Description EN', (string) $sheet->getCell('AG3')->getValue());
        self::assertSame('Implementation EN', (string) $sheet->getCell('AH3')->getValue());
        self::assertSame('1. Foto | 2. Factura / Albarán | 3. Certif. / Licencia', (string) $sheet->getCell('AI3')->getValue());
        self::assertSame('Department action EN', (string) $sheet->getCell('AJ3')->getValue());
    }
}
