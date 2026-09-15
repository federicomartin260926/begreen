<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\SustainabilityPlanExportController;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Service\ProjectFeatureGate;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanExportControllerTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testReviewExportsUseImplementationPhase(): void
    {
        $controller = $this->makeControllerWithFeatureGate(
            $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())
        );
        $project = $this->makeProjectWithTiers(ProjectSubscription::TIER_PRO, ProjectSubscription::TIER_BASIC);

        self::assertFalse($this->invokeExportAllowed($controller, $project, 'category', 'pdf'));
        self::assertFalse($this->invokeExportAllowed($controller, $project, 'category', 'excel'));

        $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->setTier(ProjectSubscription::TIER_PRO);

        self::assertTrue($this->invokeExportAllowed($controller, $project, 'category', 'pdf'));
        self::assertTrue($this->invokeExportAllowed($controller, $project, 'category', 'excel'));
    }

    public function testImplementationDepartmentPdfAcceptsTheCommercialDepartmentFeature(): void
    {
        $implementationPlan = $this->makeCommercialPlan('basic', [
            'phase' => CommercialPhase::IMPLEMENTATION,
            'features' => array_replace(
                $this->defaultImplementationCommercialPlanDefinition('basic')['features'],
                [
                    'sustainability_plan.department_pdf' => true,
                    'sustainability_plan.export.department_pdf' => false,
                    'sustainability_plan.export.department' => false,
                ]
            ),
        ]);
        $controller = $this->makeControllerWithFeatureGate(
            $this->makeProjectFeatureGate([
                $this->makeCommercialPlan('basic'),
                $implementationPlan,
            ])
        );
        $project = $this->makeProjectWithTiers(
            ProjectSubscription::TIER_PRO,
            ProjectSubscription::TIER_BASIC
        );

        self::assertTrue($this->invokeExportAllowed($controller, $project, 'department', 'pdf'));
        self::assertFalse($this->invokeExportAllowed($controller, $project, 'department', 'excel'));
    }

    public function testClosureExportsUseElaborationPhaseAndExpectedMatrix(): void
    {
        $controller = $this->makeControllerWithFeatureGate(
            $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())
        );

        $basic = $this->makeProjectWithTiers(ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_PRO);
        self::assertFalse($this->invokeClosureExportAllowed($controller, $basic, 'department', 'pdf'));
        self::assertFalse($this->invokeClosureExportAllowed($controller, $basic, 'ods', 'pdf'));
        self::assertFalse($this->invokeClosureExportAllowed($controller, $basic, 'department', 'excel'));

        $standard = $this->makeProjectWithTiers(ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO);
        self::assertTrue($this->invokeClosureExportAllowed($controller, $standard, 'department', 'pdf'));
        self::assertFalse($this->invokeClosureExportAllowed($controller, $standard, 'triple_balance', 'pdf'));
        self::assertFalse($this->invokeClosureExportAllowed($controller, $standard, 'department', 'excel'));

        $pro = $this->makeProjectWithTiers(ProjectSubscription::TIER_PRO, ProjectSubscription::TIER_BASIC);
        foreach (['department', 'triple_balance', 'ods', 'impact_area'] as $grouping) {
            self::assertTrue($this->invokeClosureExportAllowed($controller, $pro, $grouping, 'pdf'));
            self::assertTrue($this->invokeClosureExportAllowed($controller, $pro, $grouping, 'excel'));
        }

        self::assertFalse($this->invokeClosureExportAllowed($controller, $pro, 'category', 'pdf'));
        self::assertFalse($this->invokeClosureExportAllowed($controller, $pro, 'category', 'excel'));
    }

    public function testDepartmentAndOdsSummariesKeepAllGroupsWithoutOther(): void
    {
        $controller = $this->makeControllerWithFeatureGate(
            $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())
        );
        $groups = [];

        for ($index = 1; $index <= 7; $index++) {
            $groups[] = [
                'label' => 'Grupo ' . $index,
                'rows' => [[
                    'measureId' => $index,
                    'applicable' => true,
                    'selected' => true,
                    'critical' => false,
                ]],
            ];
        }

        foreach (['department', 'ods'] as $grouping) {
            $summary = $this->invokeGroupedSummary($controller, $groups, $grouping);

            self::assertCount(7, $summary);
            self::assertNotContains('Otros', array_column($summary, 'name'));
        }

        $categorySummary = $this->invokeGroupedSummary($controller, $groups, 'category');
        self::assertCount(6, $categorySummary);
        self::assertContains('Otros', array_column($categorySummary, 'name'));
    }

    public function testGroupedPdfUsesTheCompletePlanForMetricsAndOnlySelectedRowsForDetail(): void
    {
        $controller = $this->makeControllerWithFeatureGate(
            $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())
        );
        $groups = [
            ['label' => 'Seleccionadas', 'rows' => [[
                'measureId' => 1,
                'displayName' => 'Seleccionada',
                'score' => 5,
                'applicable' => true,
                'selected' => true,
                'critical' => false,
            ]]],
            ['label' => 'Descartadas', 'rows' => [[
                'measureId' => 2,
                'displayName' => 'Descartada',
                'score' => 4,
                'applicable' => true,
                'selected' => false,
                'critical' => false,
            ]]],
            ['label' => 'No aplicables', 'rows' => [[
                'measureId' => 3,
                'displayName' => 'No aplicable',
                'score' => 3,
                'applicable' => false,
                'selected' => false,
                'critical' => false,
            ]]],
            ['label' => 'Personalizadas', 'rows' => [[
                'measureId' => null,
                'displayName' => 'Medida personalizada',
                'observations' => '',
                'selected' => null,
            ]]],
        ];

        $metricsMethod = new \ReflectionMethod($controller, 'buildGroupedVisualMetrics');
        $metricsMethod->setAccessible(true);
        $metrics = $metricsMethod->invoke($controller, $groups);

        self::assertSame(3, $metrics['coverIndicators']['total']);
        self::assertSame(2, $metrics['coverIndicators']['applicable']);
        self::assertSame(1, $metrics['coverIndicators']['toImplement']);

        $summary = $this->invokeGroupedSummary($controller, $groups, 'department');
        self::assertCount(3, $summary);
        self::assertEqualsCanonicalizing(
            ['Seleccionadas', 'Descartadas', 'No aplicables'],
            array_column($summary, 'name')
        );

        $detailGroupsMethod = new \ReflectionMethod($controller, 'buildGroupedDetailGroups');
        $detailGroupsMethod->setAccessible(true);
        $detailGroups = $detailGroupsMethod->invoke($controller, $groups);

        $detailPagesMethod = new \ReflectionMethod($controller, 'buildGroupedDetailPages');
        $detailPagesMethod->setAccessible(true);
        $detailPages = $detailPagesMethod->invoke($controller, $detailGroups);

        self::assertSame(
            ['Seleccionadas', 'Personalizadas'],
            array_column($detailPages, 'groupLabel')
        );
        self::assertSame(
            ['Seleccionada', 'Medida personalizada'],
            array_map(
                static fn (array $page): string => $page['rows'][0]['displayName'],
                $detailPages
            )
        );
    }

    private function makeControllerWithFeatureGate(ProjectFeatureGate $featureGate): SustainabilityPlanExportController
    {
        $reflection = new \ReflectionClass(SustainabilityPlanExportController::class);
        /** @var SustainabilityPlanExportController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $property = new \ReflectionProperty($controller, 'featureGate');
        $property->setAccessible(true);
        $property->setValue($controller, $featureGate);

        return $controller;
    }

    private function invokeExportAllowed(
        SustainabilityPlanExportController $controller,
        Project $project,
        string $grouping,
        string $format
    ): bool {
        $reflection = new \ReflectionMethod($controller, 'isExportAllowed');
        $reflection->setAccessible(true);

        return (bool) $reflection->invoke($controller, $project, $grouping, $format);
    }

    private function invokeClosureExportAllowed(
        SustainabilityPlanExportController $controller,
        Project $project,
        string $grouping,
        string $format
    ): bool {
        $reflection = new \ReflectionMethod($controller, 'isExportAllowed');
        $reflection->setAccessible(true);

        return (bool) $reflection->invoke(
            $controller,
            $project,
            $grouping,
            $format,
            CommercialPhase::ELABORATION,
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function invokeGroupedSummary(
        SustainabilityPlanExportController $controller,
        array $groups,
        string $grouping
    ): array {
        $reflection = new \ReflectionMethod($controller, 'buildGroupedSummary');
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, $groups, 'Otros', $grouping);
    }
}
