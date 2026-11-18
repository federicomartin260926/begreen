<?php

namespace App\Tests\Service;

use App\Entity\MeasureVerificationSource;
use App\Service\MeasureTaxonomyOverlayReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class MeasureTaxonomyOverlayReaderTest extends TestCase
{
    public function testReadsCanonicalCountsAndAllFiveQualities(): void
    {
        $reader = new MeasureTaxonomyOverlayReader();
        $fixtures = [
            'be-green-my-film' => ['be_green_my_film_taxonomy.xlsx', 200, 663, 3217],
            'be-green-my-event' => ['be_green_my_event_taxonomy.xlsx', 200, 554, 3667],
            'be-green-my-animation' => ['be_green_my_animation_taxonomy.xlsx', 237, 1202, 4010],
        ];
        $qualities = [];

        foreach ($fixtures as $protocolCode => [$filename, $measures, $departments, $evidences]) {
            $overlay = $reader->read($this->fixturePath($filename), $protocolCode);

            self::assertCount($measures, $overlay['rows']);
            self::assertSame($departments, $overlay['departmentLinks']);
            self::assertSame($evidences, $overlay['evidenceLinks']);
            foreach ($overlay['rows'] as $row) {
                foreach ($row['evidences'] as $quality) {
                    $qualities[$quality] = true;
                }
            }
        }

        self::assertSame(MeasureVerificationSource::SUGGESTED_QUALITIES, array_values(array_intersect(
            MeasureVerificationSource::SUGGESTED_QUALITIES,
            array_keys($qualities)
        )));
    }

    public function testResolvesReorderedColumnsByHeader(): void
    {
        $spreadsheet = IOFactory::load($this->fixturePath('be_green_my_film_taxonomy.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();
        $headerRow = 4;
        $numberColumn = null;
        $departmentColumn = null;

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($column = 1; $column <= $highestColumn; ++$column) {
            $header = trim((string) $sheet->getCell([$column, $headerRow])->getValue());
            $numberColumn = $header === 'N.º' ? $column : $numberColumn;
            $departmentColumn = str_starts_with($header, 'DEP · ') && $departmentColumn === null ? $column : $departmentColumn;
        }
        self::assertNotNull($numberColumn);
        self::assertNotNull($departmentColumn);

        for ($row = 1; $row <= $sheet->getHighestDataRow(); ++$row) {
            $numberValue = $sheet->getCell([$numberColumn, $row])->getValue();
            $departmentValue = $sheet->getCell([$departmentColumn, $row])->getValue();
            $sheet->setCellValue([$numberColumn, $row], $departmentValue);
            $sheet->setCellValue([$departmentColumn, $row], $numberValue);
        }

        $overlay = (new MeasureTaxonomyOverlayReader())->readSpreadsheet($spreadsheet, 'be-green-my-film');
        self::assertCount(200, $overlay['rows']);
        self::assertSame(663, $overlay['departmentLinks']);
        self::assertSame(3217, $overlay['evidenceLinks']);
    }

    public function testRejectsUnknownEvidenceQuality(): void
    {
        $spreadsheet = IOFactory::load($this->fixturePath('be_green_my_film_taxonomy.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($column = 1; $column <= $highestColumn; ++$column) {
            if (str_starts_with(trim((string) $sheet->getCell([$column, 4])->getValue()), 'EVI · ')) {
                for ($row = 5; $row <= $sheet->getHighestDataRow(); ++$row) {
                    if (trim((string) $sheet->getCell([$column, $row])->getValue()) !== '') {
                        $sheet->setCellValue([$column, $row], 'Complementaria');
                        $this->expectException(\RuntimeException::class);
                        $this->expectExceptionMessage('Calidad EVI inválida');
                        (new MeasureTaxonomyOverlayReader())->readSpreadsheet($spreadsheet, 'be-green-my-film');

                        return;
                    }
                }
            }
        }

        self::fail('No se encontró ninguna asociación EVI en el fixture Film.');
    }

    private function fixturePath(string $filename): string
    {
        return dirname(__DIR__, 2).'/public/fixtures/'.$filename;
    }
}
