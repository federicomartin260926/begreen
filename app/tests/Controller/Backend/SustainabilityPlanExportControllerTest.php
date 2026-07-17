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
}
