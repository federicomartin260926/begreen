<?php

namespace App\Service\Animation;

use App\Service\MeasureTemplateParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class AnimationCatalogSourceReader
{
    public const int EXPECTED_ACTIVE_COUNT = 239;
    public const string EDITORIAL_SHEET = 'Plantilla estándar de medidas';

    public function __construct(
        private readonly MeasureTemplateParser $editorialParser,
        private readonly AnimationMeasureDatasetReader $filterReader,
    ) {
    }

    /** @return array<string, AnimationCatalogEntry> keyed by catalogId, ordered by ORDEN_VISUAL */
    public function read(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName(self::EDITORIAL_SHEET);
        if (null === $sheet) {
            throw new RuntimeException(sprintf('No existe la hoja editorial "%s".', self::EDITORIAL_SHEET));
        }

        $headers = [];
        foreach ($sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1', null, true, true, true)[1] ?? [] as $column => $value) {
            $label = trim((string) $value);
            if ('' !== $label) {
                $headers[$label] = $column;
            }
        }
        foreach (['ID interno', 'Impacto esperado', 'Esfuerzo / coste', 'Complejidad'] as $requiredHeader) {
            if (!isset($headers[$requiredHeader])) {
                throw new RuntimeException(sprintf('Falta la columna editorial requerida "%s".', $requiredHeader));
            }
        }

        $report = $this->editorialParser->parseSpreadsheet($spreadsheet);
        $editorialById = [];
        $rowHasCatalogId = [];
        foreach ($report->getRows() as $editorial) {
            $rowNumber = (int) ($editorial['row'] ?? 0);
            $catalogId = trim((string) $sheet->getCell($headers['ID interno'].$rowNumber)->getValue());
            if ('' === $catalogId) {
                continue;
            }
            $rowHasCatalogId[$rowNumber] = true;
            if (isset($editorialById[$catalogId])) {
                throw new RuntimeException(sprintf('ID editorial duplicado "%s".', $catalogId));
            }

            $this->validateEditorial($editorial, $catalogId, $rowNumber);
            foreach (['Impacto esperado', 'Esfuerzo / coste', 'Complejidad'] as $metadataHeader) {
                if ('' === trim((string) $sheet->getCell($headers[$metadataHeader].$rowNumber)->getValue())) {
                    throw new RuntimeException(sprintf('Fila editorial %d (%s) sin %s.', $rowNumber, $catalogId, $metadataHeader));
                }
            }
            $editorialById[$catalogId] = [
                'row' => $rowNumber,
                'editorial' => $editorial,
                'expectedImpact' => trim((string) $sheet->getCell($headers['Impacto esperado'].$rowNumber)->getValue()),
                'effortCost' => trim((string) $sheet->getCell($headers['Esfuerzo / coste'].$rowNumber)->getValue()),
                'complexity' => trim((string) $sheet->getCell($headers['Complejidad'].$rowNumber)->getValue()),
            ];
        }

        foreach ($report->getErrors() as $error) {
            $rowNumber = (int) ($error['context']['row'] ?? 0);
            if (isset($rowHasCatalogId[$rowNumber])) {
                throw new RuntimeException(sprintf(
                    'Fila editorial %d inválida: %s',
                    $rowNumber,
                    (string) ($error['message'] ?? 'error desconocido'),
                ));
            }
        }

        $filtersById = [];
        foreach ($this->filterReader->read($path) as $filter) {
            if ($filter->active) {
                $filtersById[$filter->id] = $filter;
            }
        }

        $missingEditorial = array_values(array_diff(array_keys($filtersById), array_keys($editorialById)));
        $missingFilter = array_values(array_diff(array_keys($editorialById), array_keys($filtersById)));
        if ([] !== $missingEditorial || [] !== $missingFilter) {
            throw new RuntimeException(sprintf(
                'Join editorial/DEV incompleto. Sin editorial: [%s]. Sin DEV activo: [%s].',
                implode(', ', $missingEditorial),
                implode(', ', $missingFilter),
            ));
        }
        if (self::EXPECTED_ACTIVE_COUNT !== count($filtersById)) {
            throw new RuntimeException(sprintf('Se esperaban %d medidas activas y se obtuvieron %d.', self::EXPECTED_ACTIVE_COUNT, count($filtersById)));
        }

        uasort($filtersById, static fn (AnimationMeasure $left, AnimationMeasure $right): int => $left->visualOrder <=> $right->visualOrder);
        $entries = [];
        foreach ($filtersById as $catalogId => $filter) {
            $source = $editorialById[$catalogId];
            $editorial = $source['editorial'];
            $categoryCode = trim((string) ($editorial['category'] ?? ''));
            $blockName = trim((string) ($editorial['measureBlock'] ?? ''));
            $entries[$catalogId] = new AnimationCatalogEntry(
                catalogId: $catalogId,
                sourceRow: $source['row'],
                categoryCode: $categoryCode,
                blockCode: $this->slug(sprintf('animation-%s-%s', $categoryCode, $blockName)),
                blockName: $blockName,
                editorial: $editorial,
                expectedImpact: $source['expectedImpact'],
                effortCost: $source['effortCost'],
                complexity: $source['complexity'],
                filter: $filter,
            );
        }

        return $entries;
    }

    /** @param array<string, mixed> $editorial */
    private function validateEditorial(array $editorial, string $catalogId, int $rowNumber): void
    {
        $required = [
            'protocol', 'projectType', 'measureBlock', 'category', 'name', 'nameReview', 'questionText',
            'gamificationMessage', 'description', 'implementation', 'departmentActionText', 'nameEn',
            'nameReviewEn', 'questionTextEn', 'gamificationMessageEn', 'descriptionEn', 'implementationEn',
            'verificationSourcesEn', 'departmentActionTextEn',
        ];
        foreach ($required as $field) {
            if ('' === trim((string) ($editorial[$field] ?? ''))) {
                throw new RuntimeException(sprintf('Fila editorial %d (%s) sin %s.', $rowNumber, $catalogId, $field));
            }
        }
        if ('Be Green My Animation' !== trim((string) $editorial['protocol']) || 'animación' !== trim((string) $editorial['projectType'])) {
            throw new RuntimeException(sprintf('Fila editorial %d (%s) no pertenece al catálogo Animation.', $rowNumber, $catalogId));
        }
    }

    private function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = false === $ascii ? $value : $ascii;

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }
}
