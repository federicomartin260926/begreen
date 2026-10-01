<?php

namespace App\Service\CrewImport;

use App\Exception\CrewImport\CrewImportExtractionException;
use App\Service\CrewImport\Dto\CrewImportTabularDocument;
use App\Service\CrewImport\Dto\CrewImportTabularRow;
use App\Service\CrewImport\Dto\CrewImportTabularSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class CrewImportFreeSpreadsheetExtractor
{
    public const MAX_SHEETS = 10;
    public const MAX_ROWS = 2000;
    public const MAX_COLUMNS = 50;
    public const MAX_CHARACTERS = 200_000;

    public function extract(string $pathname): CrewImportTabularDocument
    {
        if (!is_file($pathname) || !is_readable($pathname)) {
            throw new CrewImportExtractionException('spreadsheet_unreadable');
        }

        try {
            $type = IOFactory::identify($pathname);
            if (!in_array($type, ['Xls', 'Xlsx'], true)) {
                throw new CrewImportExtractionException('spreadsheet_invalid');
            }
            $reader = IOFactory::createReader($type);
            $reader->setReadDataOnly(true);
            $sheetNames = $reader->listWorksheetNames($pathname);
            if (count($sheetNames) > self::MAX_SHEETS) {
                throw new CrewImportExtractionException('spreadsheet_limits');
            }
            foreach ($reader->listWorksheetInfo($pathname) as $info) {
                if (
                    (int) ($info['totalRows'] ?? 0) > self::MAX_ROWS
                    || (int) ($info['totalColumns'] ?? 0) > self::MAX_COLUMNS
                ) {
                    throw new CrewImportExtractionException('spreadsheet_limits');
                }
            }
            $reader->setLoadSheetsOnly($sheetNames);
            $reader->setReadFilter(new CrewImportSpreadsheetReadFilter(self::MAX_ROWS, self::MAX_COLUMNS));
            $spreadsheet = $reader->load($pathname);
            if ($spreadsheet->hasMacros()) {
                throw new CrewImportExtractionException('spreadsheet_invalid');
            }
        } catch (CrewImportExtractionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CrewImportExtractionException('spreadsheet_read_failed', previous: $exception);
        }

        $sheets = [];
        $characters = 0;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (
                $sheet->getHighestDataRow() > self::MAX_ROWS
                || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > self::MAX_COLUMNS
            ) {
                throw new CrewImportExtractionException('spreadsheet_limits');
            }

            $rows = [];
            $maxRow = min(self::MAX_ROWS, $sheet->getHighestDataRow());
            $maxColumn = min(
                self::MAX_COLUMNS,
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn())
            );
            for ($rowNumber = 1; $rowNumber <= $maxRow; ++$rowNumber) {
                $cells = [];
                for ($column = 1; $column <= $maxColumn; ++$column) {
                    $cell = $sheet->getCell([$column, $rowNumber]);
                    $value = $cell->getDataType() === DataType::TYPE_FORMULA ? '' : $cell->getValue();
                    $clean = $this->clean(is_scalar($value) ? (string) $value : '');
                    $characters += mb_strlen($clean);
                    if ($characters > self::MAX_CHARACTERS) {
                        throw new CrewImportExtractionException('spreadsheet_limits');
                    }
                    $cells[] = $clean;
                }
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                if ($cells !== [] && array_filter($cells, static fn (string $value): bool => $value !== '') !== []) {
                    $rows[] = new CrewImportTabularRow($rowNumber, $cells);
                }
            }
            if ($rows !== []) {
                $sheets[] = new CrewImportTabularSheet($this->clean($sheet->getTitle()), $rows);
            }
        }

        if ($sheets === []) {
            throw new CrewImportExtractionException('spreadsheet_empty');
        }

        return new CrewImportTabularDocument($sheets);
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value) ?? $value;
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
