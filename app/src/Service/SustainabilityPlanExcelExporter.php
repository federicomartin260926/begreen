<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\Project;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SustainabilityPlanExcelExporter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<int, array{label:string, rows:array<int, array<string, mixed>>}> $groups
     */
    public function buildSpreadsheet(Plan $plan, Project $project, string $grouping, array $groups): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plan');

        $sheet->setCellValue('A1', $this->translator->trans('backend.plan.exports.excel.group'));
        $sheet->setCellValue('B1', $this->translator->trans('backend.plan.exports.excel.category'));
        $sheet->setCellValue('C1', $this->translator->trans('backend.plan.exports.excel.block'));
        $sheet->setCellValue('D1', $this->translator->trans('backend.plan.exports.excel.measure'));
        $sheet->setCellValue('E1', $this->translator->trans('backend.plan.exports.excel.score'));
        $sheet->setCellValue('F1', $this->translator->trans('backend.plan.exports.excel.departments'));
        $sheet->setCellValue('G1', $this->translator->trans('backend.plan.exports.excel.ods'));
        $sheet->setCellValue('H1', $this->translator->trans('backend.plan.exports.excel.impact_areas'));
        $sheet->setCellValue('I1', $this->translator->trans('backend.plan.exports.excel.triple_balance'));
        $sheet->setCellValue('J1', $this->translator->trans('backend.plan.exports.excel.verification_sources'));
        $sheet->setCellValue('K1', $this->translator->trans('backend.plan.exports.excel.implemented'));
        $sheet->setCellValue('L1', $this->translator->trans('backend.plan.exports.excel.verified'));
        $sheet->setCellValue('M1', $this->translator->trans('backend.plan.exports.excel.responsibles'));
        $sheet->setCellValue('N1', $this->translator->trans('backend.plan.exports.excel.execution_incident'));
        $sheet->setCellValue('O1', $this->translator->trans('backend.plan.exports.excel.description'));
        $sheet->setCellValue('P1', $this->translator->trans('backend.plan.exports.excel.status'));

        $rowIndex = 2;
        foreach ($this->mergeRowsAcrossGroups($groups) as $item) {
            $label = $item['label'];
            $row = $item['row'];
            $sheet->fromArray([
                $label,
                $row['category'] ?? '',
                $row['block'] ?? '',
                $row['displayName'] ?? '',
                $row['score'] ?? '',
                $row['departments'] ?? '',
                $row['ods'] ?? '',
                $row['impactAreas'] ?? '',
                $row['tripleBalanceAxes'] ?? '',
                $row['verificationSources'] ?? '',
                match ($row['implemented'] ?? null) {
                    true => $this->translator->trans('backend.plan.review.execution_decision.executed'),
                    false => $this->translator->trans('backend.plan.review.execution_decision.not_executable'),
                    default => $this->translator->trans('backend.plan.review.execution_decision.undecided'),
                },
                !empty($row['verified']) ? $this->translator->trans('backend.common.yes') : $this->translator->trans('backend.common.no'),
                $row['responsibles'] ?? '',
                $row['executionIncident'] ?? '',
                $row['description'] ?? '',
                $row['statusLabel'] ?? '',
            ], null, 'A' . $rowIndex);
            $rowIndex++;
        }

        if ($rowIndex > 2) {
            $sheet->setAutoFilter('A1:P' . ($rowIndex - 1));
        }

        // F6: anchuras acotadas para evitar hojas de cientos de columnas visuales.
        // Se mantienen las 16 columnas originales y una fila por medida seleccionada.
        $columnWidths = [
            'A' => 32, 'B' => 22, 'C' => 22, 'D' => 52,
            'E' => 10, 'F' => 36, 'G' => 20, 'H' => 26,
            'I' => 22, 'J' => 38, 'K' => 23, 'L' => 14,
            'M' => 24, 'N' => 38, 'O' => 60, 'P' => 24,
        ];
        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setAutoSize(false);
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A2:P' . max(2, $rowIndex - 1))
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);
        // Alto limitado: los textos largos permanecen íntegros en la celda
        // y pueden consultarse en la barra de fórmulas sin ensanchar la hoja.
        for ($r = 2; $r < $rowIndex; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(72);
        }
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A2:A' . max(2, $rowIndex - 1))
            ->getFont()->setBold(true);

        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EAF4EA'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    /**
     * @param array<int, array{label:string, rows:array<int, array<string, mixed>>}> $groups
     * @return list<array{label:string, row:array<string, mixed>}>
     */
    private function mergeRowsAcrossGroups(array $groups): array
    {
        $merged = [];

        foreach ($groups as $groupIndex => $group) {
            $label = (string) ($group['label'] ?? '');

            foreach ($group['rows'] ?? [] as $rowIndex => $row) {
                $measureId = $row['measureId'] ?? null;
                if ($measureId !== null && ($row['selected'] ?? null) !== true) {
                    continue;
                }

                $key = $measureId !== null
                    ? 'measure_' . $measureId
                    : sprintf('row_%d_%d', $groupIndex, $rowIndex);

                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'labels' => [],
                        'row' => $row,
                    ];
                }

                if ($label !== '' && !in_array($label, $merged[$key]['labels'], true)) {
                    $merged[$key]['labels'][] = $label;
                }
            }
        }

        return array_values(array_map(static fn (array $item): array => [
            'label' => implode(', ', $item['labels']),
            'row' => $item['row'],
        ], $merged));
    }
}
