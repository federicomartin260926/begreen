<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Department;
use App\Entity\ImpactArea;
use App\Entity\MeasureBlock;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MeasureTemplateV31FixtureBuilder
{
    private const PROTOCOL_CODE = 'be-green-my-film';
    private const PROTOCOL_NAME = 'Be Green My Film';

    /**
     * Este listado se mantiene como catálogo estándar de verificación del proyecto.
     *
     * @var string[]
     */
    private const VERIFICATION_SOURCES = [
        'Acta / Registro',
        'Captura / Email',
        'Certif. / Licencia',
        'Contrato / Acuerdo',
        'Declaración Resp.',
        'Doc. Producción',
        'Factura / Albarán',
        'Ficha Técnica',
        'Foto',
        'Informe Técnico',
        'Listado / Invent.',
        'Permiso Admin.',
        'Plan / Protocolo',
    ];

    /**
     * @param array{
     *     summary: array<string, mixed>,
     *     measures: array<int, array<string, mixed>>,
     *     warnings?: array<int, array<string, mixed>>
     * } $extraction
     */
    public function build(array $extraction): Spreadsheet
    {
        $catalog = $this->buildCatalog($extraction['summary'] ?? []);
        $spreadsheet = $this->buildTemplate($catalog);

        $sheet = $spreadsheet->getSheetByName(MeasureTemplateSchema::SHEET_TITLE) ?? $spreadsheet->getActiveSheet();
        $layout = $this->buildLayout($sheet);

        $this->fillRows($sheet, $layout, $extraction['measures'] ?? []);

        return $spreadsheet;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array{
     *     protocols: Protocol[],
     *     measureBlocks: MeasureBlock[],
     *     categories: Category[],
     *     categoryGhgs: array<int, mixed>,
     *     departments: Department[],
     *     ods: Ods[],
     *     esg: array<int, mixed>,
     *     scopes: array<int, mixed>,
     *     impactAreas: ImpactArea[],
     *     verificationSources: VerificationSource[],
     *     tripleBalanceAxes: TripleBalanceAxis[]
     * }
     */
    private function buildCatalog(array $summary): array
    {
        $protocol = (new Protocol())
            ->setCode(self::PROTOCOL_CODE)
            ->setName(self::PROTOCOL_NAME)
            ->setType(Protocol::TYPE_RODAJE);

        return [
            'protocols' => [$protocol],
            'measureBlocks' => $this->buildMeasureBlocks($protocol, $summary['blocks'] ?? []),
            'categories' => $this->buildCategories($summary['categories'] ?? []),
            'categoryGhgs' => [],
            'departments' => $this->buildDepartments($summary['departments'] ?? []),
            'ods' => $this->buildOdsItems($summary['ods'] ?? []),
            'esg' => [],
            'scopes' => [],
            'impactAreas' => $this->buildImpactAreas($summary['environmental_impacts'] ?? []),
            'verificationSources' => $this->buildVerificationSources(),
            'tripleBalanceAxes' => $this->buildTripleBalanceAxes($summary['triple_balance'] ?? []),
        ];
    }

    /**
     * @param array{
     *     protocols: Protocol[],
     *     measureBlocks: MeasureBlock[],
     *     categories: Category[],
     *     categoryGhgs: array<int, mixed>,
     *     departments: Department[],
     *     ods: Ods[],
     *     esg: array<int, mixed>,
     *     scopes: array<int, mixed>,
     *     impactAreas: ImpactArea[],
     *     verificationSources: VerificationSource[],
     *     tripleBalanceAxes: TripleBalanceAxis[]
     * } $catalog
     */
    private function buildTemplate(array $catalog): Spreadsheet
    {
        $exporter = new MeasureTemplateExporter();

        return $exporter->buildSpreadsheet($catalog);
    }

    /**
     * @param array<int, string> $blocks
     *
     * @return MeasureBlock[]
     */
    private function buildMeasureBlocks(Protocol $protocol, array $blocks): array
    {
        $slugger = new AsciiSlugger();
        $items = [];

        foreach (array_values(array_unique(array_map('trim', $blocks))) as $index => $name) {
            if ($name === '') {
                continue;
            }

            $code = sprintf(
                '%s__%s',
                (string) $protocol->getCode(),
                $slugger->slug($name)->lower()->toString()
            );

            $items[] = (new MeasureBlock())
                ->setProtocol($protocol)
                ->setCode($code)
                ->setName($name)
                ->setSortOrder($index + 1);
        }

        return $items;
    }

    /**
     * @param array<int, string> $categories
     *
     * @return Category[]
     */
    private function buildCategories(array $categories): array
    {
        $items = [];
        foreach (array_values(array_unique(array_map('trim', $categories))) as $name) {
            if ($name === '') {
                continue;
            }

            $items[] = (new Category())->setName($name);
        }

        return $items;
    }

    /**
     * @param array<int, string> $departments
     *
     * @return Department[]
     */
    private function buildDepartments(array $departments): array
    {
        $items = [];
        foreach (array_values(array_unique(array_map('trim', $departments))) as $name) {
            if ($name === '') {
                continue;
            }

            $items[] = (new Department())
                ->setName($name)
                ->setProjectType(Protocol::TYPE_RODAJE);
        }

        return $items;
    }

    /**
     * @param array<int, string|int> $ods
     *
     * @return Ods[]
     */
    private function buildOdsItems(array $ods): array
    {
        $items = [];
        foreach (array_values(array_unique(array_map('strval', $ods))) as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $items[] = (new Ods())
                ->setCode($value)
                ->setName($value);
        }

        return $items;
    }

    /**
     * @param array<int, string> $impacts
     *
     * @return ImpactArea[]
     */
    private function buildImpactAreas(array $impacts): array
    {
        $codes = ['a', 'b', 'c', 'd', 'e', 'f'];
        $items = [];

        foreach (array_values(array_unique(array_map('trim', $impacts))) as $index => $name) {
            if ($name === '') {
                continue;
            }

            $items[] = (new ImpactArea())
                ->setCode($codes[$index] ?? chr(ord('a') + $index))
                ->setName($name);
        }

        return $items;
    }

    /**
     * @return VerificationSource[]
     */
    private function buildVerificationSources(): array
    {
        $items = [];
        foreach (self::VERIFICATION_SOURCES as $index => $name) {
            $items[] = (new VerificationSource())
                ->setCode(sprintf('vs-%02d', $index + 1))
                ->setName($name);
        }

        return $items;
    }

    /**
     * @param array<int, string> $axes
     *
     * @return TripleBalanceAxis[]
     */
    private function buildTripleBalanceAxes(array $axes): array
    {
        $items = [];

        foreach (array_values(array_unique(array_map('trim', $axes))) as $name) {
            if ($name === '') {
                continue;
            }

            $normalized = MeasureTemplateSchema::normalizeHeader($name);
            $code = match (true) {
                str_contains($normalized, 'ambiental') => 'ambiental',
                str_contains($normalized, 'econom') => 'economico',
                str_contains($normalized, 'social') => 'social',
                default => $normalized,
            };

            $items[] = (new TripleBalanceAxis())
                ->setCode($code)
                ->setName($this->normalizeTripleBalanceName($name));
        }

        return $items;
    }

    /**
     * @return array{
     *     scalar: array<string, string>,
     *     matrix: array<string, array<string, string>>
     * }
     */
    private function buildLayout(Worksheet $sheet): array
    {
        $rows = $sheet->toArray(null, true, true, true);
        $row1 = $rows[1] ?? [];
        $row2 = $rows[2] ?? [];
        $scalarLookup = MeasureTemplateSchema::scalarHeaderLookup();
        $groupLookup = MeasureTemplateSchema::matrixGroupLookup();

        $layout = [
            'scalar' => [],
            'matrix' => [],
        ];

        $currentGroupKey = null;
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $top = trim((string) ($row1[$column] ?? ''));
            $second = trim((string) ($row2[$column] ?? ''));

            if ($top !== '') {
                $normalized = MeasureTemplateSchema::normalizeHeader($top);
                if (isset($scalarLookup[$normalized]) && $second === '') {
                    $layout['scalar'][$scalarLookup[$normalized]] = $column;
                    $currentGroupKey = null;
                    continue;
                }

                if (isset($groupLookup[$normalized]) && $second !== '') {
                    $currentGroupKey = $groupLookup[$normalized];
                    $layout['matrix'][$currentGroupKey] ??= [];
                    $this->registerMatrixColumn($layout['matrix'][$currentGroupKey], $second, $column);
                    continue;
                }
            }

            if ($currentGroupKey !== null && $second !== '') {
                $this->registerMatrixColumn($layout['matrix'][$currentGroupKey], $second, $column);
            }
        }

        return $layout;
    }

    /**
     * @param array<string, mixed> $measure
     */
    private function fillRows(Worksheet $sheet, array $layout, array $measures): void
    {
        foreach ($measures as $index => $measure) {
            $rowNumber = 3 + $index;
            $this->fillScalarRows($sheet, $layout['scalar'], $measure, $rowNumber);
            $this->fillMatrixRows($sheet, $layout['matrix'], $measure, $rowNumber);
        }
    }

    /**
     * @param array<string, string> $scalarLayout
     * @param array<string, mixed> $measure
     */
    private function fillScalarRows(Worksheet $sheet, array $scalarLayout, array $measure, int $rowNumber): void
    {
        $values = [
            'protocol' => self::PROTOCOL_NAME,
            'project_type' => 'rodaje',
            'measure_block' => (string) ($measure['block'] ?? ''),
            'category' => (string) ($measure['category'] ?? ''),
            'category_ghg' => '',
            'name' => (string) ($measure['measure'] ?? ''),
            'name_review' => '',
            'description' => (string) ($measure['description'] ?? ''),
            'implementation' => '',
            'department_action_text' => (string) ($measure['department_action_text'] ?? ''),
            'score' => (int) ($measure['points'] ?? 0),
            'mandatory' => '',
            'esg' => '',
            'scope' => '',
            'name_en' => '',
            'name_review_en' => '',
            'description_en' => '',
            'implementation_en' => '',
            'verification_sources_en' => '',
            'department_action_text_en' => '',
        ];

        foreach ($values as $key => $value) {
            if (!isset($scalarLayout[$key])) {
                continue;
            }

            $sheet->setCellValue(sprintf('%s%d', $scalarLayout[$key], $rowNumber), $value);
        }
    }

    /**
     * @param array<string, array<string, string>> $matrixLayout
     * @param array<string, mixed> $measure
     */
    private function fillMatrixRows(Worksheet $sheet, array $matrixLayout, array $measure, int $rowNumber): void
    {
        $groups = [
            'impact_areas' => $measure['environmental_impacts'] ?? [],
            'departments' => $measure['departments'] ?? [],
            'verification_sources' => $measure['verification_sources'] ?? [],
            'ods_items' => $measure['ods'] ?? [],
            'triple_balance_axes' => $measure['triple_balance'] ?? [],
        ];

        foreach ($groups as $groupKey => $selectedValues) {
            if (!isset($matrixLayout[$groupKey])) {
                continue;
            }

            if ($groupKey === 'verification_sources') {
                foreach ((array) $selectedValues as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $priority = (int) ($item['priority'] ?? 0);
                    $value = trim((string) ($item['value'] ?? ''));
                    $column = $this->resolveMatrixColumn($matrixLayout[$groupKey], $value);
                    if ($column === null || $priority < 1 || $priority > 3) {
                        continue;
                    }

                    $sheet->setCellValue(sprintf('%s%d', $column, $rowNumber), $priority);
                }

                continue;
            }

            foreach ((array) $selectedValues as $value) {
                $column = $this->resolveMatrixColumn($matrixLayout[$groupKey], (string) $value);
                if ($column === null) {
                    continue;
                }

                $sheet->setCellValue(sprintf('%s%d', $column, $rowNumber), MeasureTemplateSchema::MATRIX_SELECTION_MARKER);
            }
        }
    }

    /**
     * @param array<string, string> $lookup
     */
    private function resolveMatrixColumn(array $lookup, string $value): ?string
    {
        $candidates = [
            MeasureTemplateSchema::normalizeHeader($value),
        ];

        foreach (MeasureTemplateSchema::lookupCandidates($value) as $candidate) {
            $candidates[] = MeasureTemplateSchema::normalizeHeader($candidate);
        }

        if (preg_match('/^\s*([^-(]+?)\s*-\s*(.+)\s*$/u', $value, $matches)) {
            $candidates[] = MeasureTemplateSchema::normalizeHeader($matches[2]);
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($candidate !== '' && isset($lookup[$candidate])) {
                return $lookup[$candidate];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $lookup
     */
    private function registerMatrixColumn(array &$lookup, string $label, string $column): void
    {
        $keys = [MeasureTemplateSchema::normalizeHeader($label)];

        if (preg_match('/^\s*(.+?)\s*-\s*(.+)\s*$/u', $label, $matches)) {
            $keys[] = MeasureTemplateSchema::normalizeHeader($matches[2]);
        }

        if (preg_match('/^\s*(.+?)\s*\((.+)\)\s*$/u', $label, $matches)) {
            $keys[] = MeasureTemplateSchema::normalizeHeader($matches[1]);
            $keys[] = MeasureTemplateSchema::normalizeHeader($matches[2]);
        }

        foreach (array_values(array_unique($keys)) as $key) {
            if ($key === '') {
                continue;
            }

            $lookup[$key] = $column;
        }
    }

    private function normalizeTripleBalanceName(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return $label;
        }

        if (preg_match('/^(.+?)\s*\([A-Z]\)$/u', $label, $matches)) {
            return trim($matches[1]);
        }

        return $label;
    }
}
