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
}
