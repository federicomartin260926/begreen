<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class MeasureTemplateV23Parser
{
    public function parseFile(string $path): MeasureTemplateV23Report
    {
        return $this->parseSpreadsheet(IOFactory::load($path));
    }

    public function parseSpreadsheet(Spreadsheet $spreadsheet): MeasureTemplateV23Report
    {
        $sheet = $spreadsheet->getSheetByName(MeasureTemplateV23Schema::SHEET_TITLE) ?? $spreadsheet->getActiveSheet();

        return $this->parseSheet($sheet);
    }

    public function parseSheet(Worksheet $sheet): MeasureTemplateV23Report
    {
        $report = new MeasureTemplateV23Report();
        $report->setSheetName($sheet->getTitle());
        $report->setDimension(sprintf('A1:%s%d', $sheet->getHighestColumn(), $sheet->getHighestRow()));

        $rows = $sheet->toArray(null, true, true, true);
        $headerRow = $rows[1] ?? [];
        $report->setHeaders($headerRow);

        $columnMap = $this->buildColumnMap($headerRow, $report);
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
                $verificationSources = MeasureTemplateV23Schema::splitVerificationSourcesCell($rowData['verificationSources']);
            } catch (\InvalidArgumentException $e) {
                $report->addError('invalid_verification_sources', sprintf('Fila %d: %s', $rowNumber, $e->getMessage()), ['row' => $rowNumber]);
            }

            $rowData['verificationSources'] = $verificationSources;

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
    private function buildColumnMap(array $headerRow, MeasureTemplateV23Report $report): array
    {
        $normalized = [];
        foreach ($headerRow as $column => $header) {
            $normalized[MeasureTemplateV23Schema::normalizeHeader((string) $header)] = $column;
        }

        $columnMap = [];
        foreach (MeasureTemplateV23Schema::headers() as $key => $label) {
            $normalizedKey = MeasureTemplateV23Schema::normalizeHeader($label);
            if (!isset($normalized[$normalizedKey])) {
                if (!MeasureTemplateV23Schema::isOptionalHeader($key)) {
                    $report->addError('missing_header', sprintf('Falta la columna requerida "%s".', $label), ['header' => $label]);
                }
                continue;
            }

            $columnMap[$key] = $normalized[$normalizedKey];
        }

        return $columnMap;
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

    private function parseScore(string $value, int $rowNumber, MeasureTemplateV23Report $report): ?int
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
