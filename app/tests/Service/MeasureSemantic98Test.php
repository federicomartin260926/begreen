<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MeasureTemplateParser;
use App\Service\MeasureTemplateSchema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class MeasureSemantic98Test extends TestCase
{
    public function testPlanningAndFollowUpAreReadFromHeaderNames(): void
    {
        $headers = MeasureTemplateSchema::headers();
        self::assertSame('Planificación', $headers['implementation']);
        self::assertSame('Planificación EN — traducción de trabajo', $headers['implementation_en']);
        self::assertSame('Implementación y seguimiento', $headers['implementation_follow_up']);
        self::assertSame('Seguimiento EN — traducción de trabajo', $headers['implementation_follow_up_en']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // Deliberately shuffle the columns: no fixed position dependency.
        $labels = [
            'A' => 'Protocolo',
            'B' => 'Medida',
            'C' => $headers['implementation_follow_up_en'],
            'D' => $headers['implementation'],
            'E' => $headers['implementation_follow_up'],
            'F' => $headers['implementation_en'],
        ];
        foreach ($labels as $col => $label) $sheet->setCellValue($col.'1', $label);
        $sheet->setCellValue('A2', 'Be Green My Film');
        $sheet->setCellValue('B2', 'Medida de prueba');
        $sheet->setCellValue('C2', 'Keep the evidence');
        $sheet->setCellValue('D2', 'Preparar recursos');
        $sheet->setCellValue('E2', 'Conservar registros');
        $sheet->setCellValue('F2', 'Prepare resources');

        $report = (new MeasureTemplateParser())->parseSheet($sheet);
        $row = $report->getRows()[0];
        self::assertSame('Preparar recursos', $row['implementation']);
        self::assertSame('Prepare resources', $row['implementationEn']);
        self::assertSame('Conservar registros', $row['implementationFollowUp']);
        self::assertSame('Keep the evidence', $row['implementationFollowUpEn']);
    }
}
