<?php

namespace App\Tests\Service\CrewImport;

use App\Exception\CrewImport\CrewImportExtractionException;
use App\Service\CrewImport\CrewImportFreeSpreadsheetExtractor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class CrewImportFreeSpreadsheetExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testExtractsArbitraryMultiSheetXlsxAndCleansOnlyTechnicalNoise(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle(' Equipo ')
            ->fromArray([
                ['Whatever', 'Contact', 'Irrelevant'],
                ["\u{FEFF}Synthetic Person", 'synthetic@example.test', '  keep   words  '],
            ]);
        $spreadsheet->createSheet()->setTitle('Empty');
        $spreadsheet->createSheet()->setTitle('Second')->fromArray([
            ['Phone', 'Role'],
            ['600 000 001', 'Camera'],
        ]);
        $document = (new CrewImportFreeSpreadsheetExtractor())->extract($this->save($spreadsheet, 'xlsx'));

        self::assertCount(2, $document->sheets);
        self::assertSame('Equipo', $document->sheets[0]->name);
        self::assertSame(2, $document->sheets[0]->rows[1]->rowNumber);
        self::assertSame(['Synthetic Person', 'synthetic@example.test', 'keep words'], $document->sheets[0]->rows[1]->cells);
        self::assertSame('Second', $document->sheets[1]->name);
    }

    public function testExtractsLegacyXlsAndNeverEvaluatesFormula(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'Computed'],
            ['Synthetic', '=CONCATENATE("secret","value")'],
        ]);

        $document = (new CrewImportFreeSpreadsheetExtractor())->extract($this->save($spreadsheet, 'xls'));

        self::assertSame(['Synthetic'], $document->sheets[0]->rows[1]->cells);
    }

    public function testRejectsSheetAndRowLimits(): void
    {
        $tooManySheets = new Spreadsheet();
        for ($i = 1; $i < CrewImportFreeSpreadsheetExtractor::MAX_SHEETS + 1; ++$i) {
            $tooManySheets->createSheet()->setTitle('Sheet'.$i);
        }
        $this->assertReason(
            fn () => (new CrewImportFreeSpreadsheetExtractor())->extract($this->save($tooManySheets, 'xlsx')),
            'spreadsheet_limits'
        );

        $tooManyRows = new Spreadsheet();
        $tooManyRows->getActiveSheet()->setCellValue('A'.(CrewImportFreeSpreadsheetExtractor::MAX_ROWS + 1), 'outside');
        $this->assertReason(
            fn () => (new CrewImportFreeSpreadsheetExtractor())->extract($this->save($tooManyRows, 'xlsx')),
            'spreadsheet_limits'
        );
    }

    private function save(Spreadsheet $spreadsheet, string $type): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crew_free_');
        self::assertNotFalse($path);
        $this->files[] = $path;
        $type === 'xls' ? (new Xls($spreadsheet))->save($path) : (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function assertReason(\Closure $callback, string $reason): void
    {
        try {
            $callback();
            self::fail('Expected controlled extraction failure.');
        } catch (CrewImportExtractionException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
