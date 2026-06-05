<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class MeasureTemplateParser
{
    public function parseFile(string $path): MeasureTemplateReport
    {
        return $this->parseSpreadsheet(IOFactory::load($path));
    }

    public function parseSpreadsheet(Spreadsheet $spreadsheet): MeasureTemplateReport
    {
        $sheet = $spreadsheet->getSheetByName(MeasureTemplateSchema::SHEET_TITLE) ?? $spreadsheet->getActiveSheet();

        return $this->parseSheet($sheet);
    }

    public function parseSheet(Worksheet $sheet): MeasureTemplateReport
    {
        $report = new MeasureTemplateReport();
        $report->setSheetName($sheet->getTitle());
        $report->setDimension(sprintf('A1:%s%d', $sheet->getHighestColumn(), $sheet->getHighestRow()));

        $rows = $sheet->toArray(null, true, true, true);
        $headerRow = $rows[1] ?? [];
        $report->setHeaders($headerRow);

        $secondRow = $rows[2] ?? [];
        if ($this->looksLikeMatrixTemplate($headerRow, $secondRow)) {
            return $this->parseMatrixSheet($sheet, $rows, $report);
        }

        return $this->parseLegacySheet($sheet, $rows, $report);
    }

    /**
     * @param array<string, string|null> $headerRow
     * @param array<string, string|null> $secondRow
     */
    private function looksLikeMatrixTemplate(array $headerRow, array $secondRow): bool
    {
        $scalarLookup = MeasureTemplateSchema::scalarHeaderLookup();
        $groupLookup = MeasureTemplateSchema::matrixGroupLookup();
        $hasMatrixGroup = false;
        $hasMatrixOptions = false;
        $hasScalarDataInSecondRow = false;

        foreach ($headerRow as $column => $header) {
            $normalized = MeasureTemplateSchema::normalizeHeader((string) $header);
            if (isset($groupLookup[$normalized])) {
                $hasMatrixGroup = true;
                if (trim((string) ($secondRow[$column] ?? '')) !== '') {
                    $hasMatrixOptions = true;
                }
                continue;
            }

            if (isset($scalarLookup[$normalized]) && trim((string) ($secondRow[$column] ?? '')) !== '') {
                $hasScalarDataInSecondRow = true;
            }
        }

        return $hasMatrixGroup && $hasMatrixOptions && !$hasScalarDataInSecondRow;
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     */
    private function parseLegacySheet(Worksheet $sheet, array $rows, MeasureTemplateReport $report): MeasureTemplateReport
    {
        $columnMap = $this->buildLegacyColumnMap($rows[1] ?? [], $report);
        if ($columnMap === []) {
            $report->finalize();
            return $report;
        }

        $highestRow = $sheet->getHighestRow();
        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $row = $rows[$rowNumber] ?? [];
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rowData = $this->buildLegacyRowData($row, $columnMap, $rowNumber, $report);
            $report->addRow($rowData);
        }

        $report->finalize();

        return $report;
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     */
    private function parseMatrixSheet(Worksheet $sheet, array $rows, MeasureTemplateReport $report): MeasureTemplateReport
    {
        $layout = $this->buildMatrixLayout($rows[1] ?? [], $rows[2] ?? [], $report);
        if ($layout === []) {
            $report->finalize();
            return $report;
        }

        $highestRow = $sheet->getHighestRow();
        for ($rowNumber = 3; $rowNumber <= $highestRow; $rowNumber++) {
            $row = $rows[$rowNumber] ?? [];
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rowData = $this->buildMatrixRowData($row, $layout, $rowNumber, $report);
            $report->addRow($rowData);
        }

        $report->finalize();

        return $report;
    }

    /**
     * @param array<string, string|null> $headerRow
     *
     * @return array<string, string>
     */
    private function buildLegacyColumnMap(array $headerRow, MeasureTemplateReport $report): array
    {
        $normalized = [];
        foreach ($headerRow as $column => $header) {
            $normalized[MeasureTemplateSchema::normalizeHeader((string) $header)] = $column;
        }

        $columnMap = [];
        foreach (MeasureTemplateSchema::headers() as $key => $label) {
            $normalizedKey = MeasureTemplateSchema::normalizeHeader($label);
            if (!isset($normalized[$normalizedKey])) {
                if (!MeasureTemplateSchema::isOptionalHeader($key)) {
                    $report->addError('missing_header', sprintf('Falta la columna requerida "%s".', $label), ['header' => $label]);
                }
                continue;
            }

            $columnMap[$key] = $normalized[$normalizedKey];
        }

        return $columnMap;
    }

    /**
     * @param array<string, string|null> $row
     * @param array<string, string> $columnMap
     *
     * @return array<string, mixed>
     */
    private function buildLegacyRowData(array $row, array $columnMap, int $rowNumber, MeasureTemplateReport $report): array
    {
        $rowData = [
            'row' => $rowNumber,
            'protocol' => $this->cell($row, $columnMap['protocol'] ?? null),
            'projectType' => $this->cell($row, $columnMap['project_type'] ?? null),
            'measureBlock' => $this->cell($row, $columnMap['measure_block'] ?? null),
            'category' => $this->cell($row, $columnMap['category'] ?? null),
            'categoryGhg' => $this->cell($row, $columnMap['category_ghg'] ?? null),
            'name' => $this->cell($row, $columnMap['name'] ?? null),
            'nameReview' => $this->cell($row, $columnMap['name_review'] ?? null),
            'description' => $this->cell($row, $columnMap['description'] ?? null),
            'implementation' => $this->cell($row, $columnMap['implementation'] ?? null),
            'score' => $this->parseScore($this->cell($row, $columnMap['score'] ?? null), $rowNumber, $report),
            'mandatory' => $this->cell($row, $columnMap['mandatory'] ?? null),
            'departments' => $this->cell($row, $columnMap['departments'] ?? null),
            'odsItems' => $this->cell($row, $columnMap['ods_items'] ?? null),
            'esg' => $this->cell($row, $columnMap['esg'] ?? null),
            'scope' => $this->cell($row, $columnMap['scope'] ?? null),
            'impactAreas' => $this->cell($row, $columnMap['impact_areas'] ?? null),
            'tripleBalanceAxes' => $this->cell($row, $columnMap['triple_balance_axes'] ?? null),
            'verificationSources' => $this->cell($row, $columnMap['verification_sources'] ?? null),
            'nameEn' => $this->cell($row, $columnMap['name_en'] ?? null),
            'nameReviewEn' => $this->cell($row, $columnMap['name_review_en'] ?? null),
            'descriptionEn' => $this->cell($row, $columnMap['description_en'] ?? null),
            'implementationEn' => $this->cell($row, $columnMap['implementation_en'] ?? null),
            'verificationSourcesEn' => $this->cell($row, $columnMap['verification_sources_en'] ?? null),
            'departmentActionText' => $this->cell($row, $columnMap['department_action_text'] ?? null),
            'departmentActionTextEn' => $this->cell($row, $columnMap['department_action_text_en'] ?? null),
        ];

        if ($rowData['protocol'] === '') {
            $report->addError('missing_protocol', sprintf('Fila %d sin protocolo.', $rowNumber), ['row' => $rowNumber]);
        }
        if ($rowData['name'] === '') {
            $report->addError('missing_name', sprintf('Fila %d sin nombre de medida.', $rowNumber), ['row' => $rowNumber]);
        }

        $mandatory = mb_strtolower(trim($rowData['mandatory']));
        if ($mandatory !== '' && !in_array($mandatory, ['sí', 'si', 'yes', 'y', 'true', '1', 'no', 'n', 'false', '0'], true)) {
            $report->addError('invalid_mandatory', sprintf('Fila %d con valor de obligatoria inválido: "%s".', $rowNumber, $rowData['mandatory']), ['row' => $rowNumber]);
        }

        $verificationSources = [];
        try {
            $verificationSources = MeasureTemplateSchema::splitVerificationSourcesCell($rowData['verificationSources']);
        } catch (\InvalidArgumentException $e) {
            $report->addError('invalid_verification_sources', sprintf('Fila %d: %s', $rowNumber, $e->getMessage()), ['row' => $rowNumber]);
        }

        $rowData['verificationSources'] = $verificationSources;

        return $rowData;
    }

    /**
     * @param array<string, string|null> $row1
     * @param array<string, string|null> $row2
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMatrixLayout(array $row1, array $row2, MeasureTemplateReport $report): array
    {
        $scalarLookup = MeasureTemplateSchema::scalarHeaderLookup();
        $groupLookup = MeasureTemplateSchema::matrixGroupLookup();
        $highestColumn = max(array_map(
            static fn (string $column): int => Coordinate::columnIndexFromString($column),
            array_keys($row1) ?: ['A']
        ));

        $layout = [];
        $currentGroupKey = null;
        $foundGroupKeys = [];
        $foundScalarKeys = [];

        for ($columnIndex = 1; $columnIndex <= $highestColumn; $columnIndex++) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $top = trim((string) ($row1[$column] ?? ''));
            $second = trim((string) ($row2[$column] ?? ''));

            if ($top !== '') {
                $normalized = MeasureTemplateSchema::normalizeHeader($top);
                if (isset($groupLookup[$normalized]) && $second !== '') {
                    $currentGroupKey = $groupLookup[$normalized];
                    $foundGroupKeys[$currentGroupKey] = true;
                    $layout[] = [
                        'type' => 'group',
                        'groupKey' => $currentGroupKey,
                        'column' => $column,
                        'label' => $second,
                    ];
                    continue;
                }

                if (isset($scalarLookup[$normalized]) && $second === '') {
                    $currentGroupKey = null;
                    $foundScalarKeys[$scalarLookup[$normalized]] = true;
                    $layout[] = [
                        'type' => 'scalar',
                        'key' => $scalarLookup[$normalized],
                        'column' => $column,
                    ];
                    continue;
                }

                if (isset($groupLookup[$normalized])) {
                    $currentGroupKey = $groupLookup[$normalized];
                    $foundGroupKeys[$currentGroupKey] = true;
                    if ($second !== '') {
                        $layout[] = [
                            'type' => 'group',
                            'groupKey' => $currentGroupKey,
                            'column' => $column,
                            'label' => $second,
                        ];
                    }
                    continue;
                }

                if (isset($scalarLookup[$normalized])) {
                    $currentGroupKey = null;
                    $foundScalarKeys[$scalarLookup[$normalized]] = true;
                    $layout[] = [
                        'type' => 'scalar',
                        'key' => $scalarLookup[$normalized],
                        'column' => $column,
                    ];
                    continue;
                }

                $currentGroupKey = null;
                continue;
            }

            if ($second !== '' && $currentGroupKey !== null) {
                $foundGroupKeys[$currentGroupKey] = true;
                $layout[] = [
                    'type' => 'group',
                    'groupKey' => $currentGroupKey,
                    'column' => $column,
                    'label' => $second,
                ];
            }
        }

        foreach (MeasureTemplateSchema::requiredHeaders() as $requiredKey) {
            $label = MeasureTemplateSchema::headers()[$requiredKey] ?? $requiredKey;
            if (isset(MeasureTemplateSchema::matrixGroupLabels()[$requiredKey])) {
                $groupLabel = MeasureTemplateSchema::matrixGroupLabels()[$requiredKey];
                if (!isset($foundGroupKeys[$requiredKey])) {
                    $report->addError('missing_header', sprintf('Falta la columna requerida "%s".', $groupLabel), ['header' => $groupLabel]);
                }

                continue;
            }

            if (isset($scalarLookup[MeasureTemplateSchema::normalizeHeader($label)])) {
                if (!isset($foundScalarKeys[$requiredKey])) {
                    $report->addError('missing_header', sprintf('Falta la columna requerida "%s".', $label), ['header' => $label]);
                }

                continue;
            }
        }

        return $layout;
    }

    /**
     * @param array<string, string|null> $row
     * @param array<int, array<string, mixed>> $layout
     *
     * @return array<string, mixed>
     */
    private function buildMatrixRowData(array $row, array $layout, int $rowNumber, MeasureTemplateReport $report): array
    {
        $rowData = ['row' => $rowNumber];
        $matrixSelections = [
            'impactAreas' => [],
            'departments' => [],
            'verificationSources' => [],
            'odsItems' => [],
            'tripleBalanceAxes' => [],
        ];

        foreach ($layout as $columnDescriptor) {
            $column = (string) ($columnDescriptor['column'] ?? '');
            $value = trim((string) ($row[$column] ?? ''));

            if (($columnDescriptor['type'] ?? null) === 'scalar') {
                $key = (string) ($columnDescriptor['key'] ?? '');
                $rowData[$key] = $value;
                continue;
            }

            if (($columnDescriptor['type'] ?? null) !== 'group') {
                continue;
            }

            $groupKey = (string) ($columnDescriptor['groupKey'] ?? '');
            if ($value === '') {
                continue;
            }

            if (!MeasureTemplateSchema::isSelectionMarker($value)) {
                if (($columnDescriptor['groupKey'] ?? null) === 'verification_sources' && preg_match('/^\d+$/', $value)) {
                    $label = (string) ($columnDescriptor['label'] ?? '');
                    $matrixSelections['verificationSources'][] = [
                        'priority' => (int) $value,
                        'value' => $label,
                    ];
                    continue;
                }

                $report->addError(
                    'invalid_matrix_value',
                    sprintf('Fila %d, columna %s: solo se permite "X" o prioridad numérica en la plantilla de selección múltiple.', $rowNumber, $column),
                    ['row' => $rowNumber, 'column' => $column, 'value' => $value]
                );
                continue;
            }

            $label = (string) ($columnDescriptor['label'] ?? '');
            match ($groupKey) {
                'impact_areas' => $matrixSelections['impactAreas'][] = $label,
                'departments' => $matrixSelections['departments'][] = $label,
                'verification_sources' => $matrixSelections['verificationSources'][] = [
                    'priority' => count($matrixSelections['verificationSources']) + 1,
                    'value' => $label,
                ],
                'ods_items' => $matrixSelections['odsItems'][] = $label,
                'triple_balance_axes' => $matrixSelections['tripleBalanceAxes'][] = $label,
                default => null,
            };
        }

        $rowData['protocol'] = (string) ($rowData['protocol'] ?? '');
        $rowData['projectType'] = (string) ($rowData['project_type'] ?? $rowData['projectType'] ?? '');
        $rowData['measureBlock'] = (string) ($rowData['measure_block'] ?? $rowData['measureBlock'] ?? '');
        $rowData['category'] = (string) ($rowData['category'] ?? '');
        $rowData['categoryGhg'] = (string) ($rowData['category_ghg'] ?? $rowData['categoryGhg'] ?? '');
        $rowData['name'] = (string) ($rowData['name'] ?? '');
        $rowData['nameReview'] = (string) ($rowData['name_review'] ?? $rowData['nameReview'] ?? '');
        $rowData['description'] = (string) ($rowData['description'] ?? '');
        $rowData['implementation'] = (string) ($rowData['implementation'] ?? '');
        $rowData['score'] = $this->parseScore((string) ($rowData['score'] ?? ''), $rowNumber, $report);
        $rowData['mandatory'] = (string) ($rowData['mandatory'] ?? '');
        $rowData['esg'] = (string) ($rowData['esg'] ?? '');
        $rowData['scope'] = (string) ($rowData['scope'] ?? '');
        $rowData['nameEn'] = (string) ($rowData['name_en'] ?? $rowData['nameEn'] ?? '');
        $rowData['nameReviewEn'] = (string) ($rowData['name_review_en'] ?? $rowData['nameReviewEn'] ?? '');
        $rowData['descriptionEn'] = (string) ($rowData['description_en'] ?? $rowData['descriptionEn'] ?? '');
        $rowData['implementationEn'] = (string) ($rowData['implementation_en'] ?? $rowData['implementationEn'] ?? '');
        $rowData['verificationSourcesEn'] = (string) ($rowData['verification_sources_en'] ?? $rowData['verificationSourcesEn'] ?? '');
        $rowData['departmentActionText'] = (string) ($rowData['department_action_text'] ?? $rowData['departmentActionText'] ?? '');
        $rowData['departmentActionTextEn'] = (string) ($rowData['department_action_text_en'] ?? $rowData['departmentActionTextEn'] ?? '');

        $rowData['departments'] = implode('; ', array_values($matrixSelections['departments']));
        $rowData['odsItems'] = implode('; ', array_values($matrixSelections['odsItems']));
        $rowData['impactAreas'] = implode('; ', array_values($matrixSelections['impactAreas']));
        $rowData['tripleBalanceAxes'] = implode('; ', array_values($matrixSelections['tripleBalanceAxes']));
        usort($matrixSelections['verificationSources'], static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);
        $rowData['verificationSources'] = array_values($matrixSelections['verificationSources']);

        if ($rowData['protocol'] === '') {
            $report->addError('missing_protocol', sprintf('Fila %d sin protocolo.', $rowNumber), ['row' => $rowNumber]);
        }
        if ($rowData['name'] === '') {
            $report->addError('missing_name', sprintf('Fila %d sin nombre de medida.', $rowNumber), ['row' => $rowNumber]);
        }

        $mandatory = mb_strtolower(trim($rowData['mandatory']));
        if ($mandatory !== '' && !in_array($mandatory, ['sí', 'si', 'yes', 'y', 'true', '1', 'no', 'n', 'false', '0'], true)) {
            $report->addError('invalid_mandatory', sprintf('Fila %d con valor de obligatoria inválido: "%s".', $rowNumber, $rowData['mandatory']), ['row' => $rowNumber]);
        }

        return $rowData;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cell(array $row, ?string $column): string
    {
        if ($column === null) {
            return '';
        }

        return trim((string) ($row[$column] ?? ''));
    }

    private function parseScore(string $value, int $rowNumber, MeasureTemplateReport $report): ?int
    {
        if ($value === '') {
            $report->addError('missing_score', sprintf('Fila %d sin puntuación.', $rowNumber), ['row' => $rowNumber]);
            return null;
        }

        if (!is_numeric($value)) {
            $report->addError('invalid_score', sprintf('Fila %d con puntuación no numérica: "%s".', $rowNumber, $value), ['row' => $rowNumber, 'value' => $value]);
            return null;
        }

        $score = (int) round((float) $value);
        if ($score < 1 || $score > 5) {
            $report->addError('invalid_score_range', sprintf('Fila %d con puntuación fuera de rango: "%s".', $rowNumber, $value), ['row' => $rowNumber, 'value' => $value]);
            return null;
        }

        return $score;
    }
}
