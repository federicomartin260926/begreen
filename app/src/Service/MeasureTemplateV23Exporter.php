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
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MeasureTemplateV23Exporter
{
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
     *     tripleBalanceAxes?: TripleBalanceAxis[],
     *     verificationSources?: VerificationSource[]
     * } $catalog
     */
    public function buildSpreadsheet(array $catalog = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(MeasureTemplateV23Schema::SHEET_TITLE);

        $headers = array_values(MeasureTemplateV23Schema::headers());
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:W1')->getFont()->setBold(true);

        $protocols = $catalog['protocols'] ?? [];
        $measureBlocks = $catalog['measureBlocks'] ?? [];
        $categories = $catalog['categories'] ?? [];
        $categoryGhgs = $catalog['categoryGhgs'] ?? [];
        $departments = $catalog['departments'] ?? [];
        $ods = $catalog['ods'] ?? [];
        $esg = $catalog['esg'] ?? [];
        $scopes = $catalog['scopes'] ?? [];
        $impactAreas = $catalog['impactAreas'] ?? [];
        $tripleBalanceAxes = $catalog['tripleBalanceAxes'] ?? [];
        $verificationSources = $catalog['verificationSources'] ?? [];

        $listSheet = new Worksheet($spreadsheet, MeasureTemplateV23Schema::LISTS_SHEET);
        $spreadsheet->addSheet($listSheet);

        $this->fillListSheet($listSheet, [
            'A' => $this->formatProtocolList($protocols),
            'B' => $this->formatProjectTypes(),
            'C' => $this->formatMeasureBlocks($measureBlocks),
            'D' => $this->formatEntityList($categories),
            'E' => $this->formatEntityList($categoryGhgs),
            'F' => $this->formatDepartmentList($departments),
            'G' => $this->formatEntityList($ods),
            'H' => $this->formatEntityList($esg),
            'I' => $this->formatEntityList($scopes),
            'J' => $this->formatEntityList($impactAreas),
            'K' => $this->formatEntityList($tripleBalanceAxes),
            'L' => $this->formatEntityList($verificationSources),
        ]);
        $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $example = $this->buildExampleRow($protocols, $categoryGhgs, $categories, $departments, $ods, $esg, $scopes, $impactAreas, $tripleBalanceAxes, $verificationSources, $measureBlocks);
        $sheet->fromArray($example, null, 'A2');

        $this->applyValidationList($sheet, 'A', 'A', count($protocols));
        $this->applyValidationList($sheet, 'B', 'B', 3);
        $this->applyValidationList($sheet, 'C', 'C', count($measureBlocks));
        $this->applyValidationList($sheet, 'D', 'D', count($categories));
        $this->applyValidationList($sheet, 'E', 'E', count($categoryGhgs));
        $this->applyValidationList($sheet, 'N', 'H', count($esg));
        $this->applyValidationList($sheet, 'O', 'I', count($scopes));

        for ($row = 2; $row <= 1000; $row++) {
            $dvMandatory = (new DataValidation())
                ->setType(DataValidation::TYPE_LIST)
                ->setFormula1('"Sí,No"')
                ->setAllowBlank(true)
                ->setShowDropDown(true);
            $sheet->getCell("K{$row}")->setDataValidation($dvMandatory);

            $dvScore = (new DataValidation())
                ->setType(DataValidation::TYPE_WHOLE)
                ->setOperator(DataValidation::OPERATOR_BETWEEN)
                ->setFormula1('1')
                ->setFormula2('5')
                ->setAllowBlank(false)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Valor inválido')
                ->setError('La puntuación debe ser un número entre 1 y 5.');
            $sheet->getCell("J{$row}")->setDataValidation($dvScore);
        }

        $sheet->setSelectedCell('A1');
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param array<int, Protocol> $protocols
     * @param array<int, CategoryGhg> $categoryGhgs
     * @param array<int, Category> $categories
     * @param array<int, Department> $departments
     * @param array<int, Ods> $ods
     * @param array<int, EsG> $esg
     * @param array<int, Scope> $scopes
     * @param array<int, ImpactArea> $impactAreas
     * @param array<int, TripleBalanceAxis> $tripleBalanceAxes
     * @param array<int, VerificationSource> $verificationSources
     * @param array<int, MeasureBlock> $measureBlocks
     *
     * @return array<int, mixed>
     */
    private function buildExampleRow(
        array $protocols,
        array $categoryGhgs,
        array $categories,
        array $departments,
        array $ods,
        array $esg,
        array $scopes,
        array $impactAreas,
        array $tripleBalanceAxes,
        array $verificationSources,
        array $measureBlocks,
    ): array {
        $protocol = $protocols[0] ?? null;
        $categoryGhg = $categoryGhgs[0] ?? null;
        $category = $categories[0] ?? null;
        $department = $departments[0] ?? null;
        $odsItem = $ods[0] ?? null;
        $esgItem = $esg[0] ?? null;
        $scope = $scopes[0] ?? null;
        $impactArea = $impactAreas[0] ?? null;
        $axis = $tripleBalanceAxes[0] ?? null;
        $source1 = $verificationSources[0] ?? null;
        $source2 = $verificationSources[1] ?? null;
        $source3 = $verificationSources[2] ?? null;
        $block = $measureBlocks[0] ?? null;

        return [
            $protocol ? $this->formatProtocolValue($protocol) : '',
            $protocol?->getType() ?? '',
            $block ? $this->formatMeasureBlockValue($block) : '',
            $category ? $this->formatEntityValue($category) : '',
            $categoryGhg ? $this->formatEntityValue($categoryGhg) : '',
            'Medida ejemplo',
            'Nombre revisión ejemplo',
            'Descripción de la medida',
            'Cómo se implementará',
            5,
            'No',
            $department ? $this->formatDepartmentValue($department) : '',
            $odsItem ? $this->formatEntityValue($odsItem) : '',
            $esgItem ? $this->formatEntityValue($esgItem) : '',
            $scope ? $this->formatEntityValue($scope) : '',
            $impactArea ? $this->formatEntityValue($impactArea) : '',
            $axis ? $this->formatEntityValue($axis) : '',
            $this->formatVerificationSourcesValue($source1, $source2, $source3),
            '',
            '',
            '',
            '',
            '',
        ];
    }

    /**
     * @param array<string, array<int, string>> $lists
     */
    private function fillListSheet(Worksheet $sheet, array $lists): void
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
        $code = (string) ($department->getCode() ?? $department->getDisplayName());

        return sprintf('%s - %s', $code, $department->getDisplayName());
    }

    private function formatEntityValue(object $entity): string
    {
        $code = method_exists($entity, 'getCode') ? (string) ($entity->getCode() ?? '') : '';
        $name = method_exists($entity, 'getName') ? (string) ($entity->getName() ?? '') : '';

        return $code !== '' ? sprintf('%s - %s', $code, $name !== '' ? $name : $code) : $name;
    }

    private function formatVerificationSourcesValue(?VerificationSource $source1 = null, ?VerificationSource $source2 = null, ?VerificationSource $source3 = null): string
    {
        $parts = [];
        foreach ([$source1, $source2, $source3] as $index => $source) {
            if (!$source instanceof VerificationSource) {
                continue;
            }

            $parts[] = sprintf('%d. %s', $index + 1, $source->getName());
        }

        return implode(' | ', $parts);
    }

    private function applyValidationList(Worksheet $sheet, string $column, string $listColumn, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        for ($row = 2; $row <= 1000; $row++) {
            $sheet->getCell(sprintf('%s%d', $column, $row))
                ->setDataValidation(
                    (new DataValidation())
                        ->setType(DataValidation::TYPE_LIST)
                        ->setFormula1(sprintf("'%s'!\$%s\$1:\$%s\$%d", MeasureTemplateV23Schema::LISTS_SHEET, $listColumn, $listColumn, $count))
                        ->setAllowBlank(true)
                        ->setShowDropDown(true)
                );
        }
    }
}
