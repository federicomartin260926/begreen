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
use PHPUnit\Framework\TestCase;

final class MeasureTemplateV23ExporterTest extends TestCase
{
    public function testBuildSpreadsheetCreatesStandardV23Template(): void
    {
        $protocol = (new Protocol())
            ->setCode('peach')
            ->setName('Peach')
            ->setType(Protocol::TYPE_RODAJE);

        $category = (new Category())->setName('Movilidad');
        $categoryGhg = (new CategoryGhg())->setName('Emisiones indirectas de GEI debido al transporte');
        $department = (new Department())->setCode('prod')->setName('Producción');
        $ods = (new Ods())->setCode('ODS12')->setName('Producción y consumo responsables');
        $esg = (new EsG())->setName('Ambiental');
        $scope = (new Scope())->setName('Alcance 1');
        $impactArea = (new ImpactArea())->setCode('a')->setName('Cambio Climático');
        $axis = (new TripleBalanceAxis())->setCode('ambiental')->setName('Ambiental');
        $source1 = (new VerificationSource())->setCode('foto')->setName('Foto');
        $source2 = (new VerificationSource())->setCode('factura')->setName('Factura / Albarán');
        $source3 = (new VerificationSource())->setCode('certificado')->setName('Certif. / Licencia');

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('peach__movilidad')
            ->setName('Movilidad')
            ->setSortOrder(1);

        $spreadsheet = (new MeasureTemplateV23Exporter())->buildSpreadsheet([
            'protocols' => [$protocol],
            'categories' => [$category],
            'categoryGhgs' => [$categoryGhg],
            'departments' => [$department],
            'ods' => [$ods],
            'esg' => [$esg],
            'scopes' => [$scope],
            'impactAreas' => [$impactArea],
            'tripleBalanceAxes' => [$axis],
            'verificationSources' => [$source1, $source2, $source3],
            'measureBlocks' => [$block],
        ]);

        $sheet = $spreadsheet->getActiveSheet();

        self::assertSame(MeasureTemplateV23Schema::SHEET_TITLE, $sheet->getTitle());
        self::assertSame('Protocolo', (string) $sheet->getCell('A1')->getValue());
        self::assertSame('Tipo de proyecto', (string) $sheet->getCell('B1')->getValue());
        self::assertSame('Bloque', (string) $sheet->getCell('C1')->getValue());
        self::assertSame('Puntuación', (string) $sheet->getCell('J1')->getValue());
        self::assertSame('Obligatoria', (string) $sheet->getCell('K1')->getValue());
        self::assertSame('Nombre EN (opcional)', (string) $sheet->getCell('S1')->getValue());

        self::assertSame('peach - Peach', (string) $sheet->getCell('A2')->getValue());
        self::assertSame(Protocol::TYPE_RODAJE, (string) $sheet->getCell('B2')->getValue());
        self::assertSame('peach__movilidad - Movilidad', (string) $sheet->getCell('C2')->getValue());
        self::assertSame(5, $sheet->getCell('J2')->getValue());
        self::assertSame('No', (string) $sheet->getCell('K2')->getValue());
        self::assertSame('prod - Producción', (string) $sheet->getCell('L2')->getValue());
        self::assertSame('ODS12 - Producción y consumo responsables', (string) $sheet->getCell('M2')->getValue());
        self::assertSame('Ambiental', (string) $sheet->getCell('N2')->getValue());
        self::assertSame('Alcance 1', (string) $sheet->getCell('O2')->getValue());
        self::assertSame('a - Cambio Climático', (string) $sheet->getCell('P2')->getValue());
        self::assertSame('ambiental - Ambiental', (string) $sheet->getCell('Q2')->getValue());
        self::assertSame('1. Foto | 2. Factura / Albarán | 3. Certif. / Licencia', (string) $sheet->getCell('R2')->getValue());

        self::assertNotNull($spreadsheet->getSheetByName(MeasureTemplateV23Schema::LISTS_SHEET));
        self::assertSame('between', $sheet->getCell('J2')->getDataValidation()->getOperator());
        self::assertSame('1', $sheet->getCell('J2')->getDataValidation()->getFormula1());
        self::assertSame('5', $sheet->getCell('J2')->getDataValidation()->getFormula2());
        self::assertSame('list', $sheet->getCell('P2')->getDataValidation()->getType());
        self::assertSame('list', $sheet->getCell('Q2')->getDataValidation()->getType());
        self::assertSame('list', $sheet->getCell('R2')->getDataValidation()->getType());
    }
}
