<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryGhg;
use App\Entity\Department;
use App\Entity\EsG;
use App\Entity\ImpactArea;
use App\Entity\MeasureBlock;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\Scope;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MeasureTemplateV23Exporter
{
    private const MAX_ROWS = 250;
    private const SIMPLE_HEADER_FILL = '4A4A4A';
    private const SIMPLE_HEADER_ALT_FILL = '5A5A5A';
    private const MATRIX_HEADER_STYLES = [
        'impact_areas' => ['group' => '1B5E20', 'option' => 'C8E6C9'],
        'departments' => ['group' => '0D47A1', 'option' => 'BBDEFB'],
        'verification_sources' => ['group' => 'B45F06', 'option' => 'F6D8AE'],
        'ods_items' => ['group' => '00695C', 'option' => 'B2DFDB'],
        'triple_balance_axes' => ['group' => '6A1B9A', 'option' => 'E1BEE7'],
    ];

    /**
     * @param array{
     *     protocols?: Protocol[],
     *     measureBlocks?: MeasureBlock[],
     *     categories?: Category[],
     *     categoryGhgs?: CategoryGhg[],
     *     departments?: Department[],
     *     ods?: Ods[],
     *     esg?: EsG[],
     *     scopes?: Scope[],
     *     impactAreas?: ImpactArea[],
     *     verificationSources?: VerificationSource[],
     *     tripleBalanceAxes?: TripleBalanceAxis[]
     * } $catalog
     */
    public function buildSpreadsheet(array $catalog = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(MeasureTemplateV23Schema::SHEET_TITLE);

        $sections = $this->buildSections($catalog);
        $this->writeHeaderRows($sheet, $sections);
        $this->applyHeaderStyles($sheet, $sections);
        $this->applyDataValidations($sheet, $sections);

        $listSheet = new Worksheet($spreadsheet, MeasureTemplateV23Schema::LISTS_SHEET);
        $spreadsheet->addSheet($listSheet);
        $this->fillListSheet($listSheet, $catalog);
        $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $sheet->freezePane('A3');
        $sheet->setSelectedCell('A3');
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param array{
     *     protocols?: Protocol[],
     *     measureBlocks?: MeasureBlock[],
     *     categories?: Category[],
     *     categoryGhgs?: CategoryGhg[],
     *     departments?: Department[],
     *     ods?: Ods[],
     *     esg?: EsG[],
     *     scopes?: Scope[],
     *     impactAreas?: ImpactArea[],
     *     verificationSources?: VerificationSource[],
     *     tripleBalanceAxes?: TripleBalanceAxis[]
     * } $catalog
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSections(array $catalog): array
    {
        $protocols = $this->sortByLabel($catalog['protocols'] ?? [], fn (Protocol $protocol): string => $this->formatProtocolValue($protocol));
        $measureBlocks = $this->sortByLabel($catalog['measureBlocks'] ?? [], fn (MeasureBlock $block): string => $this->formatMeasureBlockValue($block));
        $categories = $this->sortByLabel($catalog['categories'] ?? [], fn (Category $category): string => (string) $category->getName());
        $categoryGhgs = $this->sortByLabel($catalog['categoryGhgs'] ?? [], fn (CategoryGhg $categoryGhg): string => (string) $categoryGhg->getName());
        $esg = $this->sortByLabel($catalog['esg'] ?? [], fn (EsG $esg): string => (string) $esg->getName());
        $scopes = $this->sortByLabel($catalog['scopes'] ?? [], fn (Scope $scope): string => (string) $scope->getName());

        $impactAreas = $this->sortByLabel($catalog['impactAreas'] ?? [], fn (ImpactArea $impactArea): string => $this->formatImpactAreaValue($impactArea));
        $departments = $this->sortByLabel($catalog['departments'] ?? [], fn (Department $department): string => $this->formatDepartmentValue($department));
        $verificationSources = $this->sortByLabel($catalog['verificationSources'] ?? [], fn (VerificationSource $source): string => $this->formatVerificationSourceValue($source));
        $ods = $this->sortByNaturalLabel($catalog['ods'] ?? [], fn (Ods $odsItem): string => $this->formatOdsValue($odsItem));
        $tripleBalanceAxes = $this->sortByLabel($catalog['tripleBalanceAxes'] ?? [], fn (TripleBalanceAxis $axis): string => $this->formatTripleBalanceAxisValue($axis));

        return [
            $this->scalarSection('protocol', MeasureTemplateV23Schema::headers()['protocol'], 'A', 22, $protocols),
            $this->scalarSection('project_type', MeasureTemplateV23Schema::headers()['project_type'], 'B', 18, null, null, true),
            $this->scalarSection('measure_block', MeasureTemplateV23Schema::headers()['measure_block'], 'C', 24, $measureBlocks),
            $this->scalarSection('category', MeasureTemplateV23Schema::headers()['category'], 'D', 18, $categories),
            $this->scalarSection('category_ghg', MeasureTemplateV23Schema::headers()['category_ghg'], 'E', 24, $categoryGhgs),
            $this->scalarSection('name', MeasureTemplateV23Schema::headers()['name'], 'F', 42),
            $this->scalarSection('score', MeasureTemplateV23Schema::headers()['score'], 'G', 8, null, null, true),
            $this->scalarSection('mandatory', MeasureTemplateV23Schema::headers()['mandatory'], 'H', 12, null, null, true),
            $this->scalarSection('esg', MeasureTemplateV23Schema::headers()['esg'], 'I', 14, $esg, 'F'),
            $this->scalarSection('scope', MeasureTemplateV23Schema::headers()['scope'], 'J', 14, $scopes, 'G'),
            $this->scalarSection('name_review', MeasureTemplateV23Schema::headers()['name_review'], 'K', 24),
            $this->scalarSection('description', MeasureTemplateV23Schema::headers()['description'], 'L', 42),
            $this->scalarSection('implementation', MeasureTemplateV23Schema::headers()['implementation'], 'M', 42),
            $this->scalarSection('department_action_text', MeasureTemplateV23Schema::headers()['department_action_text'], 'N', 42),
            $this->matrixSection('impact_areas', MeasureTemplateV23Schema::matrixGroupLabels()['impact_areas'], $impactAreas, 'N', 14),
            $this->matrixSection('departments', MeasureTemplateV23Schema::matrixGroupLabels()['departments'], $departments, null, 12),
            $this->matrixSection('verification_sources', MeasureTemplateV23Schema::matrixGroupLabels()['verification_sources'], $verificationSources, null, 15),
            $this->matrixSection('ods_items', MeasureTemplateV23Schema::matrixGroupLabels()['ods_items'], $ods, null, 6),
            $this->matrixSection('triple_balance_axes', MeasureTemplateV23Schema::matrixGroupLabels()['triple_balance_axes'], $tripleBalanceAxes, null, 14),
            $this->scalarSection('name_en', MeasureTemplateV23Schema::headers()['name_en'], 'O', 28),
            $this->scalarSection('name_review_en', MeasureTemplateV23Schema::headers()['name_review_en'], 'P', 28),
            $this->scalarSection('description_en', MeasureTemplateV23Schema::headers()['description_en'], 'Q', 42),
            $this->scalarSection('implementation_en', MeasureTemplateV23Schema::headers()['implementation_en'], 'R', 42),
            $this->scalarSection('verification_sources_en', MeasureTemplateV23Schema::headers()['verification_sources_en'], 'S', 42),
            $this->scalarSection('department_action_text_en', MeasureTemplateV23Schema::headers()['department_action_text_en'], 'T', 42),
        ];
    }

    /**
     * @param array<int, object> $items
     *
     * @return array<int, object>
     */
    private function sortByLabel(array $items, callable $labelResolver): array
    {
        usort($items, static function (object $left, object $right) use ($labelResolver): int {
            return strcmp(
                mb_strtolower((string) $labelResolver($left)),
                mb_strtolower((string) $labelResolver($right))
            );
        });

        return $items;
    }

    /**
     * @param array<int, object> $items
     *
     * @return array<int, object>
     */
    private function sortByNaturalLabel(array $items, callable $labelResolver): array
    {
        usort($items, static function (object $left, object $right) use ($labelResolver): int {
            $leftLabel = trim((string) $labelResolver($left));
            $rightLabel = trim((string) $labelResolver($right));

            if (is_numeric($leftLabel) && is_numeric($rightLabel)) {
                return (int) $leftLabel <=> (int) $rightLabel;
            }

            return strcmp(mb_strtolower($leftLabel), mb_strtolower($rightLabel));
        });

        return $items;
    }

    /**
     * @param array<int, object>|null $values
     * @param array<string, mixed>|null $listConfig
     *
     * @return array<string, mixed>
     */
    private function scalarSection(
        string $key,
        string $label,
        string $templateColumn,
        int $width,
        ?array $values = null,
        ?string $listSheetColumn = null,
        bool $inlineList = false,
    ): array {
        return [
            'type' => 'scalar',
            'key' => $key,
            'label' => $label,
            'width' => $width,
            'values' => $values,
            'templateColumn' => $templateColumn,
            'listSheetColumn' => $listSheetColumn ?? $templateColumn,
            'inlineList' => $inlineList,
        ];
    }

    /**
     * @param array<int, object> $items
     *
     * @return array<string, mixed>
     */
    private function matrixSection(string $key, string $label, array $items, ?string $listColumn, int $width): array
    {
        $options = array_map(function (object $item) use ($key): string {
            return match ($key) {
                'impact_areas' => $this->formatImpactAreaValue($item),
                'departments' => $this->formatDepartmentValue($item),
                'verification_sources' => $this->formatVerificationSourceValue($item),
                'ods_items' => $this->formatOdsValue($item),
                'triple_balance_axes' => $this->formatTripleBalanceAxisValue($item),
                default => (string) ($item->__toString() ?? ''),
            };
        }, $items);

        return [
            'type' => 'matrix',
            'key' => $key,
            'label' => $label,
            'options' => $options,
            'width' => $width,
            'groupColor' => self::MATRIX_HEADER_STYLES[$key]['group'] ?? self::SIMPLE_HEADER_FILL,
            'optionColor' => self::MATRIX_HEADER_STYLES[$key]['option'] ?? self::SIMPLE_HEADER_ALT_FILL,
            'listColumn' => $listColumn,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     */
    private function writeHeaderRows(Worksheet $sheet, array $sections): void
    {
        $columnIndex = 1;

        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'scalar') {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $sheet->setCellValue(sprintf('%s1', $column), (string) $section['label']);
                $sheet->mergeCells(sprintf('%s1:%s2', $column, $column));
                $sheet->getColumnDimension($column)->setWidth((float) ($section['width'] ?? 18));
                $columnIndex++;
                continue;
            }

            if (($section['type'] ?? null) !== 'matrix') {
                continue;
            }

            $options = $section['options'] ?? [];
            if ($options === []) {
                continue;
            }

            $startColumn = $columnIndex;
            $endColumn = $columnIndex + count($options) - 1;
            $startLetter = Coordinate::stringFromColumnIndex($startColumn);
            $endLetter = Coordinate::stringFromColumnIndex($endColumn);

            $sheet->setCellValue(sprintf('%s1', $startLetter), (string) $section['label']);
            $sheet->mergeCells(sprintf('%s1:%s1', $startLetter, $endLetter));

            foreach ($options as $offset => $label) {
                $column = Coordinate::stringFromColumnIndex($startColumn + $offset);
                $sheet->setCellValue(sprintf('%s2', $column), $label);
                $sheet->getColumnDimension($column)->setWidth((float) ($section['width'] ?? 12));
            }

            $columnIndex = $endColumn + 1;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     */
    private function applyHeaderStyles(Worksheet $sheet, array $sections): void
    {
        $columnIndex = 1;
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'scalar') {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $this->styleRange($sheet, sprintf('%s1:%s2', $column, $column), self::SIMPLE_HEADER_FILL, true);
                $columnIndex++;
                continue;
            }

            if (($section['type'] ?? null) !== 'matrix') {
                continue;
            }

            $options = $section['options'] ?? [];
            if ($options === []) {
                continue;
            }

            $startColumn = $columnIndex;
            $endColumn = $columnIndex + count($options) - 1;
            $startLetter = Coordinate::stringFromColumnIndex($startColumn);
            $endLetter = Coordinate::stringFromColumnIndex($endColumn);

            $this->styleRange($sheet, sprintf('%s1:%s1', $startLetter, $endLetter), (string) ($section['groupColor'] ?? self::SIMPLE_HEADER_FILL), true);
            $this->styleRange($sheet, sprintf('%s2:%s2', $startLetter, $endLetter), (string) ($section['optionColor'] ?? self::SIMPLE_HEADER_ALT_FILL), false);

            $columnIndex = $endColumn + 1;
        }
    }

    private function styleRange(Worksheet $sheet, string $range, string $fillColor, bool $whiteText): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => $whiteText ? 'FFFFFF' : '000000'],
                'size' => 10,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $fillColor],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     */
    private function applyDataValidations(Worksheet $sheet, array $sections): void
    {
        $columnIndex = 1;

        for ($row = 3; $row <= self::MAX_ROWS; $row++) {
            $columnIndex = 1;

            foreach ($sections as $section) {
                if (($section['type'] ?? null) === 'scalar') {
                    $column = Coordinate::stringFromColumnIndex($columnIndex);
                    $this->applyScalarValidation($sheet, $column, (string) ($section['key'] ?? ''), $section, $row);
                    $columnIndex++;
                    continue;
                }

                if (($section['type'] ?? null) !== 'matrix') {
                    continue;
                }

                $options = $section['options'] ?? [];
                foreach ($options as $offset => $_label) {
                    $column = Coordinate::stringFromColumnIndex($columnIndex + $offset);
                    $sheet->getCell(sprintf('%s%d', $column, $row))
                        ->setDataValidation(
                            (new DataValidation())
                                ->setType(DataValidation::TYPE_LIST)
                                ->setFormula1('"X"')
                                ->setAllowBlank(true)
                                ->setShowDropDown(true)
                                ->setShowErrorMessage(true)
                                ->setErrorTitle('Valor inválido')
                                ->setError('Solo se permite X en esta columna.')
                        );
                }

                $columnIndex += count($options);
            }
        }
    }

    /**
     * @param array<string, mixed> $section
     */
    private function applyScalarValidation(Worksheet $sheet, string $column, string $key, array $section, int $row): void
    {
        $validation = match ($key) {
            'project_type' => '"rodaje,evento,ambos"',
            'mandatory' => '"Sí,No"',
            'score' => null,
            default => null,
        };

        if ($key === 'score') {
            $sheet->getCell(sprintf('%s%d', $column, $row))
                ->setDataValidation(
                    (new DataValidation())
                        ->setType(DataValidation::TYPE_WHOLE)
                        ->setOperator(DataValidation::OPERATOR_BETWEEN)
                        ->setFormula1('1')
                        ->setFormula2('5')
                        ->setAllowBlank(false)
                        ->setShowErrorMessage(true)
                        ->setErrorTitle('Valor inválido')
                        ->setError('La puntuación debe ser un número entre 1 y 5.')
                );

            return;
        }

        if ($validation !== null) {
            $sheet->getCell(sprintf('%s%d', $column, $row))
                ->setDataValidation(
                    (new DataValidation())
                        ->setType(DataValidation::TYPE_LIST)
                        ->setFormula1($validation)
                        ->setAllowBlank(true)
                        ->setShowDropDown(true)
                );

            return;
        }

        $listColumn = $section['listSheetColumn'] ?? null;
        $values = $section['values'] ?? null;
        if (!$listColumn || !is_array($values) || $values === []) {
            return;
        }

        $lastRow = count($values);
        $sheet->getCell(sprintf('%s%d', $column, $row))
            ->setDataValidation(
                (new DataValidation())
                    ->setType(DataValidation::TYPE_LIST)
                    ->setFormula1(sprintf("'%s'!\$%s\$1:\$%s\$%d", MeasureTemplateV23Schema::LISTS_SHEET, $listColumn, $listColumn, $lastRow))
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
            );
    }

    /**
     * @param array{
     *     protocols?: Protocol[],
     *     measureBlocks?: MeasureBlock[],
     *     categories?: Category[],
     *     categoryGhgs?: CategoryGhg[],
     *     departments?: Department[],
     *     ods?: Ods[],
     *     esg?: EsG[],
     *     scopes?: Scope[],
     *     impactAreas?: ImpactArea[],
     *     verificationSources?: VerificationSource[]
     * } $catalog
     */
    private function fillListSheet(Worksheet $sheet, array $catalog): void
    {
        $listColumns = [
            'A' => $this->formatProtocolList($catalog['protocols'] ?? []),
            'B' => $this->formatProjectTypes(),
            'C' => $this->formatMeasureBlocks($catalog['measureBlocks'] ?? []),
            'D' => $this->formatEntityList($catalog['categories'] ?? []),
            'E' => $this->formatEntityList($catalog['categoryGhgs'] ?? []),
            'F' => $this->formatEntityList($catalog['esg'] ?? []),
            'G' => $this->formatEntityList($catalog['scopes'] ?? []),
        ];

        $this->fillColumnList($sheet, $listColumns);
    }

    /**
     * @param array<string, array<int, string>> $lists
     */
    private function fillColumnList(Worksheet $sheet, array $lists): void
    {
        foreach ($lists as $column => $values) {
            foreach ($values as $index => $value) {
                $sheet->setCellValue(sprintf('%s%d', $column, $index + 1), $value);
            }
        }
    }

    /**
     * @param array<int, Protocol> $protocols
     *
     * @return array<int, string>
     */
    private function formatProtocolList(array $protocols): array
    {
        return array_map(fn (Protocol $protocol): string => $this->formatProtocolValue($protocol), $protocols);
    }

    /**
     * @param array<int, object> $entities
     *
     * @return array<int, string>
     */
    private function formatEntityList(array $entities): array
    {
        return array_map(fn (object $entity): string => $this->formatEntityValue($entity), $entities);
    }

    /**
     * @return array<int, string>
     */
    private function formatProjectTypes(): array
    {
        return [
            Protocol::TYPE_RODAJE,
            Protocol::TYPE_EVENTO,
            Protocol::TYPE_AMBOS,
        ];
    }

    /**
     * @param array<int, Department> $departments
     *
     * @return array<int, string>
     */
    private function formatDepartmentList(array $departments): array
    {
        return array_map(fn (Department $department): string => $this->formatDepartmentValue($department), $departments);
    }

    /**
     * @param array<int, MeasureBlock> $measureBlocks
     *
     * @return array<int, string>
     */
    private function formatMeasureBlocks(array $measureBlocks): array
    {
        return array_map(fn (MeasureBlock $block): string => $this->formatMeasureBlockValue($block), $measureBlocks);
    }

    private function formatProtocolValue(Protocol $protocol): string
    {
        $code = (string) ($protocol->getCode() ?? $protocol->getName() ?? '');

        return sprintf('%s - %s', $code, $protocol->getName() ?? $code);
    }

    private function formatMeasureBlockValue(MeasureBlock $block): string
    {
        $code = (string) ($block->getCode() ?? '');
        if ($code === '') {
            $protocolCode = $block->getProtocol()?->getCode() ?? 'protocol';
            $slugger = new AsciiSlugger();
            $code = sprintf('%s__%s', $protocolCode, $slugger->slug($block->getName())->lower()->toString());
        }

        return sprintf('%s - %s', $code, $block->getName());
    }

    private function formatDepartmentValue(Department $department): string
    {
        $displayName = trim((string) $department->getDisplayName());
        if ($displayName !== '') {
            return $displayName;
        }

        return (string) ($department->getCode() ?? '');
    }

    private function formatImpactAreaValue(ImpactArea $impactArea): string
    {
        return sprintf('%s - %s', $impactArea->getCode(), $impactArea->getName());
    }

    private function formatOdsValue(Ods $ods): string
    {
        if (preg_match('/(\d+)/', (string) $ods->getCode(), $matches)) {
            return $matches[1];
        }

        return (string) $ods->getName();
    }

    private function formatVerificationSourceValue(VerificationSource $source): string
    {
        return $source->getName();
    }

    private function formatTripleBalanceAxisValue(TripleBalanceAxis $axis): string
    {
        $suffix = match ($axis->getCode()) {
            'ambiental' => 'E',
            'social' => 'S',
            'economico' => 'M',
            default => strtoupper(substr($axis->getCode(), 0, 1)),
        };

        return sprintf('%s (%s)', $axis->getName(), $suffix);
    }

    private function formatEntityValue(object $entity): string
    {
        $code = method_exists($entity, 'getCode') ? (string) ($entity->getCode() ?? '') : '';
        $name = method_exists($entity, 'getName') ? (string) ($entity->getName() ?? '') : '';

        return $code !== '' ? sprintf('%s - %s', $code, $name !== '' ? $name : $code) : $name;
    }
}
