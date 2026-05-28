<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\CategoryGhg;
use App\Entity\Department;
use App\Entity\EsG;
use App\Entity\ImpactArea;
use App\Entity\MeasureBlock;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\Scope;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use App\Service\MeasureTemplateV23Exporter;
use App\Service\MeasureTemplateV23Schema;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateV23ExporterTest extends TestCase
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

        $ods12 = (new Ods())->setCode('ODS12')->setName('Producción y consumo responsables');
        $ods13 = (new Ods())->setCode('ODS13')->setName('Acción por el clima');

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

        $spreadsheet = (new MeasureTemplateV23Exporter())->buildSpreadsheet([
            'protocols' => [$protocol],
            'categories' => [$category],
            'categoryGhgs' => [$categoryGhg],
            'esg' => [$esg],
            'scopes' => [$scope],
            'impactAreas' => [$impactAreaA, $impactAreaB],
            'departments' => [$departmentProd, $departmentArt, $departmentCam],
            'ods' => [$ods12, $ods13],
            'tripleBalanceAxes' => [$axisEnv, $axisSoc, $axisEco],
            'verificationSources' => [$sourceFoto, $sourceFactura, $sourceCert],
            'measureBlocks' => [$block],
        ]);

        $sheet = $spreadsheet->getActiveSheet();
        $listSheet = $spreadsheet->getSheetByName(MeasureTemplateV23Schema::LISTS_SHEET);

        self::assertSame(MeasureTemplateV23Schema::SHEET_TITLE, $sheet->getTitle());
        self::assertSame('Protocolo', (string) $sheet->getCell('A1')->getValue());
        self::assertSame('Tipo de proyecto', (string) $sheet->getCell('B1')->getValue());
        self::assertSame('Bloque', (string) $sheet->getCell('C1')->getValue());
        self::assertSame('Categoría', (string) $sheet->getCell('D1')->getValue());
        self::assertSame('Medida', (string) $sheet->getCell('F1')->getValue());
        self::assertSame('Impacto ambiental', (string) $sheet->getCell('N1')->getValue());
        self::assertSame('Departamento', (string) $sheet->getCell('P1')->getValue());
        self::assertSame('Fuente de verificación', (string) $sheet->getCell('S1')->getValue());
        self::assertSame('ODS', (string) $sheet->getCell('V1')->getValue());
        self::assertSame('Triple balance', (string) $sheet->getCell('X1')->getValue());
        self::assertSame('Nombre EN (opcional)', (string) $sheet->getCell('AA1')->getValue());

        self::assertSame('', (string) $sheet->getCell('A2')->getValue());
        self::assertSame('', (string) $sheet->getCell('B2')->getValue());
        self::assertSame('', (string) $sheet->getCell('C2')->getValue());
        self::assertSame('', (string) $sheet->getCell('D2')->getValue());
        self::assertSame('', (string) $sheet->getCell('G2')->getValue());
        self::assertSame('', (string) $sheet->getCell('H2')->getValue());
        self::assertSame('', (string) $sheet->getCell('I2')->getValue());
        self::assertSame('', (string) $sheet->getCell('J2')->getValue());
        self::assertSame('a - Cambio Climático', (string) $sheet->getCell('N2')->getValue());
        self::assertSame('b - Recursos', (string) $sheet->getCell('O2')->getValue());
        self::assertSame('art - Arte', (string) $sheet->getCell('P2')->getValue());
        self::assertSame('cam - Cámara', (string) $sheet->getCell('Q2')->getValue());
        self::assertSame('prod - Producción', (string) $sheet->getCell('R2')->getValue());
        self::assertSame('Certif. / Licencia', (string) $sheet->getCell('S2')->getValue());
        self::assertSame('Factura / Albarán', (string) $sheet->getCell('T2')->getValue());
        self::assertSame('Foto', (string) $sheet->getCell('U2')->getValue());
        self::assertSame('12', (string) $sheet->getCell('V2')->getValue());
        self::assertSame('13', (string) $sheet->getCell('W2')->getValue());
        self::assertSame('Ambiental (E)', (string) $sheet->getCell('X2')->getValue());
        self::assertSame('Económico (M)', (string) $sheet->getCell('Y2')->getValue());
        self::assertSame('Social (S)', (string) $sheet->getCell('Z2')->getValue());

        self::assertArrayHasKey('A1:A2', $sheet->getMergeCells());
        self::assertArrayHasKey('N1:O1', $sheet->getMergeCells());
        self::assertArrayHasKey('P1:R1', $sheet->getMergeCells());
        self::assertArrayHasKey('S1:U1', $sheet->getMergeCells());
        self::assertArrayHasKey('V1:W1', $sheet->getMergeCells());
        self::assertArrayHasKey('X1:Z1', $sheet->getMergeCells());

        self::assertNotNull($listSheet);
        self::assertSame('peach - Peach', (string) $listSheet->getCell('A1')->getValue());
        self::assertSame('rodaje', (string) $listSheet->getCell('B1')->getValue());
        self::assertSame('peach__movilidad - Movilidad', (string) $listSheet->getCell('C1')->getValue());
        self::assertSame('Movilidad', (string) $listSheet->getCell('D1')->getValue());

        self::assertSame('between', $sheet->getCell('G3')->getDataValidation()->getOperator());
        self::assertSame('1', $sheet->getCell('G3')->getDataValidation()->getFormula1());
        self::assertSame('5', $sheet->getCell('G3')->getDataValidation()->getFormula2());
        self::assertSame('list', $sheet->getCell('P3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('P3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('S3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('S3')->getDataValidation()->getFormula1());
        self::assertSame('list', $sheet->getCell('X3')->getDataValidation()->getType());
        self::assertSame('"X"', $sheet->getCell('X3')->getDataValidation()->getFormula1());
    }

    public function testSpreadsheetCanBeWrittenToXlsxFile(): void
    {
        $spreadsheet = (new MeasureTemplateV23Exporter())->buildSpreadsheet([
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
}
