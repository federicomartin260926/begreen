<?php

namespace App\Tests\Service;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
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
                'description' => 'Descripción de prueba',
            ]],
        ]]);

        $sheet = $spreadsheet->getActiveSheet();

        self::assertSame('Group', (string) $sheet->getCell('A1')->getValue());
        self::assertSame('Measure', (string) $sheet->getCell('D1')->getValue());
        self::assertSame('Producción', (string) $sheet->getCell('A2')->getValue());
        self::assertSame('Medida A', (string) $sheet->getCell('D2')->getValue());
        self::assertSame('Descripción de prueba', (string) $sheet->getCell('K2')->getValue());
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
            default => $id,
        });

        return $translator;
    }

    private function createProject(string $tier): Project
    {
        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setTier($tier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);
        $project->setSubscription($subscription);

        return $project;
    }
}
