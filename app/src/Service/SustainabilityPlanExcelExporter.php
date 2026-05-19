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
        $sheet->setCellValue('K1', $this->translator->trans('backend.plan.exports.excel.description'));

        $rowIndex = 2;
        foreach ($groups as $group) {
            $label = (string) ($group['label'] ?? '');
            foreach ($group['rows'] ?? [] as $row) {
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
                    $row['description'] ?? '',
                ], null, 'A' . $rowIndex);
                $rowIndex++;
            }
        }

        if ($rowIndex > 2) {
            $sheet->setAutoFilter('A1:K' . ($rowIndex - 1));
        }

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getStyle('A1:K1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EAF4EA'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
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
}
