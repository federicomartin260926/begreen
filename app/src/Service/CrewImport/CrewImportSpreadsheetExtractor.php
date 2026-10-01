<?php

namespace App\Service\CrewImport;

use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportRow;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class CrewImportSpreadsheetExtractor
{
    private const HEADERS = [
        ['nombre', 'apellido', 'cargo', 'departamento', 'email', 'telefono'],
        ['first name', 'last name', 'position', 'department', 'email', 'phone'],
    ];

    public function extract(string $pathname): CrewImportExtraction
    {
        try {
            $readerType = IOFactory::identify($pathname);
            if (!in_array($readerType, ['Xls', 'Xlsx'], true)) {
                return new CrewImportExtraction(CrewImportExtraction::UNSUPPORTED_TEMPLATE);
            }

            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($pathname);
            if ($spreadsheet->hasMacros()) {
                return new CrewImportExtraction(CrewImportExtraction::UNSUPPORTED_TEMPLATE);
            }
        } catch (\Throwable) {
            return new CrewImportExtraction(CrewImportExtraction::READ_ERROR);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $headers = [];
        for ($column = 1; $column <= $highestColumn; ++$column) {
            $headers[] = self::normalizeHeader($this->cellValue($sheet->getCell([$column, 1])));
        }
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        if (!in_array($headers, self::HEADERS, true)) {
            return new CrewImportExtraction(CrewImportExtraction::UNSUPPORTED_TEMPLATE);
        }

        $rows = [];
        for ($rowNumber = 2; $rowNumber <= $sheet->getHighestRow(); ++$rowNumber) {
            $values = [];
            for ($column = 1; $column <= 6; ++$column) {
                $values[] = trim($this->cellValue($sheet->getCell([$column, $rowNumber])));
            }

            if (count(array_filter($values, static fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }

            $rows[] = new CrewImportRow($rowNumber, ...$values);
        }

        return new CrewImportExtraction(CrewImportExtraction::OFFICIAL_TEMPLATE, $rows);
    }

    private function cellValue(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return '';
        }

        $value = $cell->getValue();

        return is_scalar($value) ? (string) $value : '';
    }

    private static function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }
}
