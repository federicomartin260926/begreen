<?php

namespace App\Service\CrewImport;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final readonly class CrewImportSpreadsheetReadFilter implements IReadFilter
{
    public function __construct(private int $maxRows, private int $maxColumns)
    {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row <= $this->maxRows
            && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
    }
}
