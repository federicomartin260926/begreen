<?php

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BeGreenMyFilmV23Parser
{
    private const SHEET_NAME = 'Plan de Sostenibilidad';
    private const EXPECTED_DIMENSION = 'A1:BM255';

    private const ODS_NAMES = [
        '1' => 'Fin de la pobreza',
        '2' => 'Hambre cero',
        '3' => 'Salud y bienestar',
        '4' => 'Educación de calidad',
        '5' => 'Igualdad de género',
        '6' => 'Agua limpia y saneamiento',
        '7' => 'Energía asequible y no contaminante',
        '8' => 'Trabajo decente y crecimiento económico',
        '9' => 'Industria, innovación e infraestructura',
        '10' => 'Reducción de las desigualdades',
        '11' => 'Ciudades y comunidades sostenibles',
        '12' => 'Producción y consumo responsables',
        '13' => 'Acción por el clima',
        '14' => 'Vida submarina',
        '15' => 'Vida de ecosistemas terrestres',
        '16' => 'Paz, justicia e instituciones sólidas',
        '17' => 'Alianzas para lograr los objetivos',
    ];

    public function parseFile(string $path): BeGreenMyFilmV23Report
    {
        return $this->parseSpreadsheet(IOFactory::load($path));
    }

    public function parseSpreadsheet(Spreadsheet $spreadsheet): BeGreenMyFilmV23Report
    {
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME) ?? $spreadsheet->getActiveSheet();
        return $this->parseSheet($sheet);
    }

    public function parseSheet(Worksheet $sheet): BeGreenMyFilmV23Report
    {
        $report = new BeGreenMyFilmV23Report();
        $report->setSheetName($sheet->getTitle());
        $report->setDimension(sprintf('A1:%s%d', $sheet->getHighestColumn(), $sheet->getHighestRow()));

        if ($sheet->getTitle() !== self::SHEET_NAME) {
            $report->addError(
                'sheet_name',
                sprintf('Hoja inesperada: "%s" en lugar de "%s".', $sheet->getTitle(), self::SHEET_NAME)
            );
        }

        if (strtoupper($report->jsonSerialize()['dimension']) !== self::EXPECTED_DIMENSION) {
            $report->addWarning(
                'dimension',
                sprintf(
                    'Dimensión detectada %s; se esperaba %s.',
                    $report->jsonSerialize()['dimension'],
                    self::EXPECTED_DIMENSION
                )
            );
        }

        $grid = $sheet->rangeToArray('A1:BM255', null, true, true, true);
        $report->setHeaders([
            'row1' => $grid[1] ?? [],
            'row2' => $grid[2] ?? [],
        ]);

        $currentTopBlock = null;
        $currentTopBlockCode = null;
        $currentSectionCode = null;
        $sortOrder = 0;

        for ($row = 3; $row <= 255; $row++) {
            $cells = $grid[$row] ?? [];
            $measureText = $this->cell($cells, 'H');
            $scoreText = trim((string) $this->cell($cells, 'I'));

            if ($row === 255 && $measureText === 'TOTAL PUNTOS') {
                continue;
            }

            if ($scoreText !== '' && is_numeric($scoreText) && $row <= 254) {
                $measure = $this->parseMeasureRow($row, $cells, $report);
                $measure['sortOrder'] = ++$sortOrder;
                $report->registerMeasure($measure, $currentSectionCode);
                continue;
            }

            if ($measureText !== '') {
                $sortOrder++;
                $isTopLevel = $this->isTopLevelBlock($measureText);
                $code = $this->codeFromLabel($measureText, 'block-' . $row);
                $parentCode = $isTopLevel ? null : $currentTopBlockCode;
                $parentName = $isTopLevel ? null : $currentTopBlock;

                $report->registerSection($code, $measureText, $row, $isTopLevel ? 0 : 1, $parentCode, $parentName);
                $currentSectionCode = $code;

                if ($isTopLevel) {
                    $currentTopBlock = $measureText;
                    $currentTopBlockCode = $code;
                }
                continue;
            }
        }

        $this->validateMeasureRows($report);
        $this->validateWarningsFromWorkbook($report);
        $report->finalize();

        return $report;
    }

    private function parseMeasureRow(int $row, array $cells, BeGreenMyFilmV23Report $report): array
    {
        $measureText = $this->cell($cells, 'H');
        $score = (int) $this->cell($cells, 'I');

        $impactAreas = $this->collectSelected($cells, ['A', 'B', 'C', 'D', 'E', 'F']);
        $departments = $this->collectSelected($cells, ['J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE']);
        $verificationSources = $this->collectVerificationSources($cells, $report, $row);
        $ods = $this->collectOds($cells);
        $tripleBalanceAxes = $this->collectSelected($cells, ['BJ', 'BK', 'BL']);

        if ($departments === []) {
            $report->addWarning('missing_department', sprintf('Fila %d sin departamento marcado.', $row), ['row' => $row]);
        }

        if ($row === 184) {
            $report->addWarning(
                'row_184_department',
                'La fila 184 no marca departamento en la plantilla v23; se mantiene como warning funcional.',
                ['row' => $row]
            );
        }

        if ($impactAreas === []) {
            $report->addError('missing_impact_area', sprintf('Fila %d sin área de impacto.', $row), ['row' => $row]);
        }

        if ($ods === []) {
            $report->addError('missing_ods', sprintf('Fila %d sin ODS.', $row), ['row' => $row]);
        }

        if ($tripleBalanceAxes === []) {
            $report->addError('missing_triple_balance', sprintf('Fila %d sin triple balance.', $row), ['row' => $row]);
        }

        if (count($verificationSources) !== 3) {
            $report->addError(
                'verification_source_count',
                sprintf('Fila %d con %d fuentes de verificación en lugar de 3.', $row, count($verificationSources)),
                ['row' => $row, 'count' => count($verificationSources)]
            );
        }

        $measure = [
            'row' => $row,
            'name' => $measureText,
            'category' => $this->cell($cells, 'G'),
            'score' => $score,
            'description' => $this->cell($cells, 'BM'),
            'impactAreas' => $impactAreas,
            'departments' => $departments,
            'verificationSources' => $verificationSources,
            'ods' => $ods,
            'tripleBalanceAxes' => $tripleBalanceAxes,
        ];

        if ($measure['category'] !== '') {
            $report->registerCategory(
                $this->codeFromLabel($measure['category'], 'category-' . $row),
                $measure['category'],
                $row
            );
        }

        foreach ($impactAreas as $item) {
            $report->registerImpactArea($item['code'], $item['name'], $row);
        }
        foreach ($departments as $item) {
            $report->registerDepartment($item['code'], $item['name'], $row);
        }
        foreach ($verificationSources as $item) {
            $report->registerVerificationSource($item['code'], $item['name'], $row, $item['priority']);
        }
        foreach ($ods as $item) {
            $report->registerOds($item['code'], $item['name'], $row);
        }
        foreach ($tripleBalanceAxes as $item) {
            $report->registerTripleBalanceAxis($item['code'], $item['name'], $row);
        }

        return $measure;
    }

    private function validateMeasureRows(BeGreenMyFilmV23Report $report): void
    {
        foreach ($report->jsonSerialize()['measureRows'] as $measure) {
            if ((int) $measure['score'] < 1 || (int) $measure['score'] > 5) {
                $report->addError('score_range', sprintf('Fila %d con puntuación fuera de rango.', $measure['row']), ['row' => $measure['row']]);
            }
        }
    }

    private function validateWarningsFromWorkbook(BeGreenMyFilmV23Report $report): void
    {
        $ods12 = false;
        $heRows = [];
        foreach ($report->jsonSerialize()['measureRows'] as $measure) {
            foreach ($measure['ods'] as $ods) {
                if ($ods['code'] === '12') {
                    $ods12 = true;
                }
            }
            foreach ($measure['departments'] as $department) {
                if ($department['name'] === 'HE') {
                    $heRows[] = $measure['row'];
                    break;
                }
            }
        }

        if (!$ods12) {
            $report->addWarning('ods12_unused', 'ODS 12 no detectado en la plantilla.');
        }
        if ($heRows !== []) {
            $report->addWarning(
                'department_he_present',
                sprintf('Se detecta el departamento HE en las filas: %s. Queda pendiente de confirmación funcional.', implode(', ', $heRows)),
                ['rows' => $heRows]
            );
        }
    }

    private function collectSelected(array $cells, array $columns): array
    {
        $items = [];
        foreach ($columns as $column) {
            $value = trim((string) ($cells[$column] ?? ''));
            if ($value === '' || $value === '0') {
                continue;
            }

            $items[] = [
                'code' => $this->codeFromLabel($column, $column),
                'name' => $this->headerName($column),
            ];
        }

        return $items;
    }

    private function collectVerificationSources(array $cells, BeGreenMyFilmV23Report $report, int $row): array
    {
        $items = [];
        $columns = ['AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR'];

        foreach ($columns as $column) {
            $value = trim((string) ($cells[$column] ?? ''));
            if ($value === '') {
                continue;
            }

            if (!in_array($value, ['1', '2', '3'], true)) {
                $report->addError(
                    'verification_source_priority',
                    sprintf('Fila %d, columna %s: prioridad inválida "%s".', $row, $column, $value),
                    ['row' => $row, 'column' => $column, 'value' => $value]
                );
                continue;
            }

            $items[] = [
                'code' => $this->codeFromLabel($column, $column),
                'name' => $this->headerName($column),
                'priority' => (int) $value,
            ];
        }

        return $items;
    }

    private function collectOds(array $cells): array
    {
        $items = [];
        $columns = ['AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ', 'BA', 'BB', 'BC', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI'];

        foreach ($columns as $index => $column) {
            $value = trim((string) ($cells[$column] ?? ''));
            if ($value === '' || $value === '0') {
                continue;
            }

            $code = (string) ($index + 1);
            $items[] = [
                'code' => $code,
                'name' => self::ODS_NAMES[$code] ?? $code,
            ];
        }

        return $items;
    }

    private function headerName(string $column): string
    {
        return match ($column) {
            'A' => 'Cambio Climático',
            'B' => 'Agotamiento Recursos Nat.',
            'C' => 'Biodiversidad',
            'D' => 'Contaminación',
            'E' => 'Cambio Uso Suelo',
            'F' => 'Comunicación y Sensib.',
            'J' => 'Prod',
            'K' => 'Dir',
            'L' => 'Foto/Cám',
            'M' => 'Eléc',
            'N' => 'Maq/Grip',
            'O' => 'Son',
            'P' => 'Arte',
            'Q' => 'Const',
            'R' => 'Vest',
            'S' => 'Maq/Pel',
            'T' => 'SFX',
            'U' => 'Loca',
            'V' => 'Trans',
            'W' => 'Atz',
            'X' => 'Cast',
            'Y' => 'Cate',
            'Z' => 'HE',
            'AA' => 'Post',
            'AB' => 'Cont',
            'AC' => 'Sost',
            'AD' => 'Vet/Anim',
            'AE' => 'Guion',
            'AF' => 'Factura / Albarán',
            'AG' => 'Foto',
            'AH' => 'Captura / Email',
            'AI' => 'Declaración Resp.',
            'AJ' => 'Informe Técnico',
            'AK' => 'Certif. / Licencia',
            'AL' => 'Listado / Invent.',
            'AM' => 'Ficha Técnica',
            'AN' => 'Contrato / Acuerdo',
            'AO' => 'Doc. Producción',
            'AP' => 'Plan / Protocolo',
            'AQ' => 'Acta / Registro',
            'AR' => 'Permiso Admin.',
            'BJ' => 'Ambiental',
            'BK' => 'Social',
            'BL' => 'Económico',
            default => $column,
        };
    }

    private function cell(array $cells, string $column): string
    {
        return trim((string) ($cells[$column] ?? ''));
    }

    private function isTopLevelBlock(string $value): bool
    {
        return str_starts_with($value, 'MÓDULO:') || $value === mb_strtoupper($value);
    }

    private function codeFromLabel(string $label, string $fallback): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
        $ascii = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii) ?: $fallback);
        $ascii = trim($ascii, '-');
        return $ascii !== '' ? $ascii : $fallback;
    }
}
