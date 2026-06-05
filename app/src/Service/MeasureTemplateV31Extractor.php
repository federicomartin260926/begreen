<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class MeasureTemplateV31Extractor
{
    private const SHEET_TITLE = 'Plan de Sostenibilidad';

    private const ENVIRONMENTAL_IMPACT_COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F'];
    private const MEASURE_COLUMNS = [
        'category' => 'G',
        'measure' => 'H',
        'department_action_text' => 'I',
        'points' => 'J',
        'description' => 'BN',
    ];
    private const DEPARTMENT_COLUMNS = ['K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF'];
    private const VERIFICATION_SOURCE_COLUMNS = ['AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AS'];
    private const ODS_COLUMNS = ['AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ', 'BA', 'BB', 'BC', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ'];
    private const TRIPLE_BALANCE_COLUMNS = ['BK', 'BL', 'BM'];

    public function extractFile(string $path): array
    {
        return $this->extractSpreadsheet(IOFactory::load($path));
    }

    public function extractSpreadsheet(Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheetByName(self::SHEET_TITLE) ?? $spreadsheet->getActiveSheet();

        return $this->extractSheet($sheet);
    }

    public function extractSheet(Worksheet $sheet): array
    {
        $highestRow = (int) $sheet->getHighestRow();
        $headerLabels = $this->readRow($sheet, 2);

        $summarySets = [
            'departments' => [],
            'categories' => [],
            'blocks' => [],
            'environmental_impacts' => [],
            'verification_sources' => [],
            'ods' => [],
            'triple_balance' => [],
        ];

        $warnings = [];
        $rowsWithIssues = [];
        $measures = [];
        $totalPoints = 0;
        $currentBlock = null;

        for ($rowNumber = 3; $rowNumber <= $highestRow; $rowNumber++) {
            $row = $this->readRow($sheet, $rowNumber);

            $category = $this->clean((string) ($row[self::MEASURE_COLUMNS['category']] ?? ''));
            $measure = $this->clean((string) ($row[self::MEASURE_COLUMNS['measure']] ?? ''));
            $actionText = $this->clean((string) ($row[self::MEASURE_COLUMNS['department_action_text']] ?? ''));
            $pointsRaw = $this->clean((string) ($row[self::MEASURE_COLUMNS['points']] ?? ''));
            $description = $this->clean((string) ($row[self::MEASURE_COLUMNS['description']] ?? ''));

            if ($category === '' && $measure !== '' && $pointsRaw === '') {
                $currentBlock = $measure;
                $this->addUnique($summarySets['blocks'], $currentBlock);
                continue;
            }

            if ($category === '' && $measure === '' && $actionText === '' && $pointsRaw === '' && $description === '') {
                continue;
            }

            if ($measure === '' || $category === '') {
                $warnings[] = $this->buildWarning(
                    $rowNumber,
                    'invalid_row',
                    'Fila sin estructura de medida válida (faltan Categoría o Medida).'
                );
                $rowsWithIssues[] = [
                    'row' => $rowNumber,
                    'issues' => ['invalid_row'],
                ];
                continue;
            }

            $points = $this->parsePoints($pointsRaw);
            if ($points === null) {
                $warnings[] = $this->buildWarning(
                    $rowNumber,
                    'missing_points',
                    'Fila sin puntuación válida.'
                );
            }

            $environmentalImpacts = $this->collectSelections($row, self::ENVIRONMENTAL_IMPACT_COLUMNS, $headerLabels);
            $departments = $this->collectSelections($row, self::DEPARTMENT_COLUMNS, $headerLabels);
            $verificationSources = $this->collectSelections($row, self::VERIFICATION_SOURCE_COLUMNS, $headerLabels);
            $ods = $this->collectSelections($row, self::ODS_COLUMNS, $headerLabels);
            $tripleBalance = $this->collectSelections($row, self::TRIPLE_BALANCE_COLUMNS, $headerLabels);

            if ($actionText === '') {
                $warnings[] = $this->buildWarning(
                    $rowNumber,
                    'missing_department_action_text',
                    'Fila sin Acción por departamento.'
                );
            }

            if ($description === '') {
                $warnings[] = $this->buildWarning(
                    $rowNumber,
                    'missing_description',
                    'Fila sin Descripción detallada.'
                );
            }

            if ($departments === []) {
                $warnings[] = $this->buildWarning(
                    $rowNumber,
                    'missing_departments',
                    'Fila sin departamentos marcados.'
                );
            }

            if ($currentBlock === null || $currentBlock === '') {
                $warnings[] = $this->buildWarning(
                    $rowNumber,
                    'missing_block',
                    'Fila sin bloque/cabecera previa.'
                );
            }

            $this->addUnique($summarySets['categories'], $category);
            $this->addUniqueMany($summarySets['environmental_impacts'], $environmentalImpacts);
            $this->addUniqueMany($summarySets['departments'], $departments);
            $this->addUniqueMany($summarySets['verification_sources'], $verificationSources);
            $this->addUniqueMany($summarySets['ods'], $ods);
            $this->addUniqueMany($summarySets['triple_balance'], $tripleBalance);

            if ($points !== null) {
                $totalPoints += $points;
            }

            $measures[] = [
                'source_row' => $rowNumber,
                'protocol' => 'Be Green My Film',
                'project_type' => 'rodaje',
                'category' => $category,
                'block' => $currentBlock,
                'measure' => $measure,
                'department_action_text' => $actionText,
                'points' => $points,
                'description' => $description,
                'environmental_impacts' => $environmentalImpacts,
                'departments' => $departments,
                'verification_sources' => $verificationSources,
                'ods' => $ods,
                'triple_balance' => $tripleBalance,
            ];

            if ($this->rowHasIssues($warnings, $rowNumber)) {
                $rowsWithIssues[] = [
                    'row' => $rowNumber,
                    'issues' => $this->issuesForRow($warnings, $rowNumber),
                ];
            }
        }

        $summary = [
            'measures_count' => count($measures),
            'total_points' => $totalPoints,
            'departments' => array_values($summarySets['departments']),
            'categories' => array_values($summarySets['categories']),
            'blocks' => array_values($summarySets['blocks']),
            'environmental_impacts' => array_values($summarySets['environmental_impacts']),
            'verification_sources' => array_values($summarySets['verification_sources']),
            'ods' => array_values($summarySets['ods']),
            'triple_balance' => array_values($summarySets['triple_balance']),
            'rows_with_issues' => $rowsWithIssues,
        ];

        return [
            'summary' => $summary,
            'measures' => $measures,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function readRow(Worksheet $sheet, int $rowNumber): array
    {
        $row = [];
        foreach ($this->allColumns() as $column) {
            $row[$column] = $this->clean((string) $sheet->getCell($column . $rowNumber)->getFormattedValue());
        }

        return $row;
    }

    /**
     * @return string[]
     */
    private function allColumns(): array
    {
        return array_merge(
            self::ENVIRONMENTAL_IMPACT_COLUMNS,
            [self::MEASURE_COLUMNS['category'], self::MEASURE_COLUMNS['measure'], self::MEASURE_COLUMNS['department_action_text'], self::MEASURE_COLUMNS['points']],
            self::DEPARTMENT_COLUMNS,
            self::VERIFICATION_SOURCE_COLUMNS,
            self::ODS_COLUMNS,
            self::TRIPLE_BALANCE_COLUMNS,
            [self::MEASURE_COLUMNS['description']]
        );
    }

    /**
     * @param string[] $columns
     * @param array<string, string|null> $labelsByColumn
     * @return string[]
     */
    private function collectSelections(array $row, array $columns, array $labelsByColumn): array
    {
        $values = [];
        foreach ($columns as $column) {
            if (!MeasureTemplateV23Schema::isSelectionMarker($row[$column] ?? null)) {
                continue;
            }

            $label = $this->clean((string) ($labelsByColumn[$column] ?? ''));
            if ($label !== '') {
                $values[] = $label;
            }
        }

        return $values;
    }

    private function parsePoints(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function addUnique(array &$set, string $value): void
    {
        $value = $this->clean($value);
        if ($value === '') {
            return;
        }

        $key = mb_strtolower($value);
        $set[$key] = $value;
    }

    /**
     * @param string[] $values
     */
    private function addUniqueMany(array &$set, array $values): void
    {
        foreach ($values as $value) {
            $this->addUnique($set, $value);
        }
    }

    private function clean(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value;
    }

    private function buildWarning(int $row, string $code, string $message): array
    {
        return [
            'row' => $row,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @param array<int, array{row:int, code:string, message:string}> $warnings
     */
    private function rowHasIssues(array $warnings, int $rowNumber): bool
    {
        foreach ($warnings as $warning) {
            if ($warning['row'] === $rowNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{row:int, code:string, message:string}> $warnings
     * @return string[]
     */
    private function issuesForRow(array $warnings, int $rowNumber): array
    {
        $issues = [];
        foreach ($warnings as $warning) {
            if ($warning['row'] !== $rowNumber) {
                continue;
            }

            $issues[] = $warning['code'];
        }

        return array_values(array_unique($issues));
    }
}
