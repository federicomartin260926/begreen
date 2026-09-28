<?php

namespace App\Service\Animation;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

final class AnimationMeasureDatasetReader
{
    private const string SHEET_NAME = 'MEDIDAS_FILTROS_DEV';

    /** @return list<AnimationMeasure> */
    public function read(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet instanceof Worksheet) {
            throw new RuntimeException(sprintf('No existe la hoja requerida "%s".', self::SHEET_NAME));
        }

        $rows = $sheet->toArray(null, true, true, true);
        [$headerRow, $columns] = $this->locateHeader($rows);
        $measures = [];
        $ids = [];
        $orders = [];
        $dataStarted = false;

        for ($rowNumber = $headerRow + 1, $last = $sheet->getHighestDataRow(); $rowNumber <= $last; ++$rowNumber) {
            $row = $rows[$rowNumber] ?? [];
            if ($this->isEmptyRow($row)) {
                if ($dataStarted) {
                    break;
                }
                continue;
            }

            $dataStarted = true;
            $measure = $this->parseMeasure($row, $columns, $rowNumber);
            if (isset($ids[$measure->id])) {
                throw new RuntimeException(sprintf('ID duplicado "%s" en la fila %d.', $measure->id, $rowNumber));
            }
            if (isset($orders[$measure->visualOrder])) {
                throw new RuntimeException(sprintf('ORDEN_VISUAL duplicado "%d" en la fila %d.', $measure->visualOrder, $rowNumber));
            }

            $ids[$measure->id] = true;
            $orders[$measure->visualOrder] = true;
            $measures[] = $measure;
        }

        if ([] === $measures) {
            throw new RuntimeException('La tabla de medidas no contiene filas válidas.');
        }

        $actualOrders = array_keys($orders);
        sort($actualOrders, SORT_NUMERIC);
        if ($actualOrders !== range(1, count($measures))) {
            throw new RuntimeException('ORDEN_VISUAL debe ser único y consecutivo desde 1.');
        }

        usort($measures, static fn (AnimationMeasure $a, AnimationMeasure $b): int => $a->visualOrder <=> $b->visualOrder);

        return $measures;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{int, array<string, string>}
     */
    private function locateHeader(array $rows): array
    {
        foreach ($rows as $rowNumber => $row) {
            $columns = [];
            foreach ($row as $column => $value) {
                $header = trim((string) $value);
                if ('' !== $header) {
                    $columns[$header] = $column;
                }
            }

            if (isset($columns['ID'])) {
                $missing = array_values(array_diff($this->requiredColumns(), array_keys($columns)));
                if ([] !== $missing) {
                    throw new RuntimeException(sprintf('Faltan columnas requeridas: %s.', implode(', ', $missing)));
                }

                return [(int) $rowNumber, $columns];
            }
        }

        throw new RuntimeException('No se ha encontrado la cabecera real de MEDIDAS_FILTROS_DEV.');
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $columns
     */
    private function parseMeasure(array $row, array $columns, int $rowNumber): AnimationMeasure
    {
        $id = trim((string) ($row[$columns['ID']] ?? ''));
        if ('' === $id) {
            throw new RuntimeException(sprintf('Fila de medida %d inválida: falta ID.', $rowNumber));
        }

        return new AnimationMeasure(
            id: $id,
            active: $this->binary($row, $columns, 'ACTIVA', $rowNumber),
            visualOrder: $this->positiveInteger($row, $columns, 'ORDEN_VISUAL', $rowNumber),
            planCompatibility: $this->binaryMap($row, $columns, AnimationConfigurationCatalog::PLANS, $rowNumber),
            forceIfTechniqueMatches: $this->binary($row, $columns, 'FORZAR_SI_TECNICA_COINCIDE', $rowNumber),
            forceIfShooting: $this->binary($row, $columns, 'FORZAR_SI_RODAJE_SI', $rowNumber),
            notApplicableAllowed: $this->binary($row, $columns, 'NO_APLICA_PERMITIDO', $rowNumber),
            operationalCondition: $this->nullableText($row[$columns['Condicion_operativa']] ?? null),
            techniqueCompatibility: $this->binaryMap($row, $columns, AnimationConfigurationCatalog::TECHNIQUES, $rowNumber),
            structureCompatibility: $this->binaryMap($row, $columns, AnimationConfigurationCatalog::STRUCTURES, $rowNumber),
            processingLevelCompatibility: $this->binaryMap($row, $columns, AnimationConfigurationCatalog::PROCESSING_LEVELS, $rowNumber),
            processingInfrastructureCompatibility: $this->binaryMap($row, $columns, AnimationConfigurationCatalog::PROCESSING_INFRASTRUCTURES, $rowNumber),
            shootingCompatibility: $this->binaryMap($row, $columns, AnimationConfigurationCatalog::SHOOTING, $rowNumber),
            aiCompatibility: $this->binaryMap($row, $columns, AnimationConfigurationCatalog::AI, $rowNumber),
            interactiveCompatibility: [
                'INTERACTIVO_SI' => $this->binary($row, $columns, 'INTERACTIVO_SI', $rowNumber),
                'INTERACTIVO_NO' => $this->binary($row, $columns, 'INTERACTIVO_NO', $rowNumber),
            ],
        );
    }

    /** @return list<string> */
    private function requiredColumns(): array
    {
        return array_values(array_unique(array_merge(
            ['ID', 'ACTIVA', 'ORDEN_VISUAL', 'FORZAR_SI_TECNICA_COINCIDE', 'FORZAR_SI_RODAJE_SI', 'NO_APLICA_PERMITIDO', 'Condicion_operativa'],
            array_values(AnimationConfigurationCatalog::PLANS),
            array_values(AnimationConfigurationCatalog::TECHNIQUES),
            array_values(AnimationConfigurationCatalog::STRUCTURES),
            array_values(AnimationConfigurationCatalog::PROCESSING_LEVELS),
            array_values(AnimationConfigurationCatalog::PROCESSING_INFRASTRUCTURES),
            array_values(AnimationConfigurationCatalog::SHOOTING),
            array_values(AnimationConfigurationCatalog::AI),
            array_values(AnimationConfigurationCatalog::INTERACTIVE),
        )));
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $columns
     * @param array<string, string> $mapping
     *
     * @return array<string, bool>
     */
    private function binaryMap(array $row, array $columns, array $mapping, int $rowNumber): array
    {
        $result = [];
        foreach ($mapping as $column) {
            $result[$column] = $this->binary($row, $columns, $column, $rowNumber);
        }

        return $result;
    }

    /** @param array<string, mixed> $row @param array<string, string> $columns */
    private function binary(array $row, array $columns, string $column, int $rowNumber): bool
    {
        $value = trim((string) ($row[$columns[$column]] ?? ''));
        if (!in_array($value, ['0', '1'], true)) {
            throw new RuntimeException(sprintf('Valor binario inválido en %s, fila %d.', $column, $rowNumber));
        }

        return '1' === $value;
    }

    /** @param array<string, mixed> $row @param array<string, string> $columns */
    private function positiveInteger(array $row, array $columns, string $column, int $rowNumber): int
    {
        $value = trim((string) ($row[$columns[$column]] ?? ''));
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException(sprintf('Orden inválido en %s, fila %d.', $column, $rowNumber));
        }

        return (int) $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    /** @param array<string, mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ('' !== trim((string) $value)) {
                return false;
            }
        }

        return true;
    }
}
