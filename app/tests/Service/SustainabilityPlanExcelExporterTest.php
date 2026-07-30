<?php

namespace App\Tests\Service;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Service\SustainabilityPlanExcelExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SustainabilityPlanExcelExporterTest extends TestCase
{
    public function testBuildSpreadsheetCreatesExpectedHeadersAndRows(): void
    {
        $exporter = new SustainabilityPlanExcelExporter($this->createTranslator());
        $plan = (new Plan())->setProject($this->createProject(ProjectSubscription::TIER_PRO));
        $project = $plan->getProject();

        $spreadsheet = $exporter->buildSpreadsheet($plan, $project, 'department', [[
            'label' => 'Producción',
            'rows' => [[
                'category' => 'Movilidad',
                'block' => 'Bloque 1',
                'displayName' => 'Medida A',
                'score' => 5,
                'departments' => 'Producción, Postproducción',
                'ods' => 'ODS 12',
                'impactAreas' => 'Cambio Climático',
                'tripleBalanceAxes' => 'Ambiental',
                'verificationSources' => '1. Foto',
                'implemented' => true,
                'verified' => false,
                'responsibles' => 'Ana García',
                'executionIncident' => 'Incidencia visible',
                'description' => 'Descripción de prueba',
                'statusLabel' => 'Implementada',
            ]],
        ]]);

        $sheet = $spreadsheet->getActiveSheet();

        self::assertSame('Group', (string) $sheet->getCell('A1')->getValue());
        self::assertSame('Measure', (string) $sheet->getCell('D1')->getValue());
        self::assertSame('Implemented', (string) $sheet->getCell('K1')->getValue());
        self::assertSame('Execution incident', (string) $sheet->getCell('N1')->getValue());
        self::assertSame('Producción', (string) $sheet->getCell('A2')->getValue());
        self::assertSame('Medida A', (string) $sheet->getCell('D2')->getValue());
        self::assertSame('Yes', (string) $sheet->getCell('K2')->getValue());
        self::assertSame('Ana García', (string) $sheet->getCell('M2')->getValue());
        self::assertSame('Incidencia visible', (string) $sheet->getCell('N2')->getValue());
        self::assertSame('Descripción de prueba', (string) $sheet->getCell('O2')->getValue());
        self::assertSame('Implementada', (string) $sheet->getCell('P2')->getValue());
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => match ($id) {
            'backend.plan.exports.excel.group' => 'Group',
            'backend.plan.exports.excel.category' => 'Category',
            'backend.plan.exports.excel.block' => 'Block',
            'backend.plan.exports.excel.measure' => 'Measure',
            'backend.plan.exports.excel.score' => 'Score',
            'backend.plan.exports.excel.departments' => 'Departments',
            'backend.plan.exports.excel.ods' => 'ODS',
            'backend.plan.exports.excel.impact_areas' => 'Impact areas',
            'backend.plan.exports.excel.triple_balance' => 'Triple balance',
            'backend.plan.exports.excel.verification_sources' => 'Sources',
            'backend.plan.exports.excel.description' => 'Description',
            'backend.plan.exports.excel.implemented' => 'Implemented',
            'backend.plan.exports.excel.verified' => 'Verified',
            'backend.plan.exports.excel.responsibles' => 'Responsibles',
            'backend.plan.exports.excel.execution_incident' => 'Execution incident',
            'backend.plan.exports.excel.status' => 'Status',
            'backend.common.yes' => 'Yes',
            'backend.common.no' => 'No',
            default => $id,
        });

        return $translator;
    }

    private function createProject(string $tier): Project
    {
        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setTier($tier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);
        $project->addSubscription($subscription);

        return $project;
    }
}
