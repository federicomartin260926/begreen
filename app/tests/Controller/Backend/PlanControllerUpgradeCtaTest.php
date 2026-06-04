<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PlanController;
use App\Entity\CommercialPlan;
use App\Entity\ProjectSubscription;
use App\Repository\CommercialPlanRepository;
use App\Tests\Support\CommercialPlanTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanControllerUpgradeCtaTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testBasicProjectShowsModalWithStandardAndProOptions(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);

        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro');

        $result = $this->invokeBuildUpgradeCta(
            $controller,
            $project,
            ProjectSubscription::TIER_BASIC,
            [
                ProjectSubscription::TIER_STANDARD => ['priceId' => 'price_standard'],
                ProjectSubscription::TIER_PRO => ['priceId' => 'price_pro'],
            ],
            $this->makeCommercialPlanRepository($plans)
        );

        self::assertSame('modal', $result['mode']);
        self::assertSame('Mejorar plan', $result['label']);
        self::assertCount(2, $result['options']);
        self::assertSame(ProjectSubscription::TIER_STANDARD, $result['options'][0]['targetTier']);
        self::assertSame(ProjectSubscription::TIER_PRO, $result['options'][1]['targetTier']);
    }

    public function testStandardProjectShowsSingleDirectOption(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);

        $plans = $this->makeDefaultCommercialPlans();
        $plans['pro']->setStripePriceId('price_pro');

        $result = $this->invokeBuildUpgradeCta(
            $controller,
            $project,
            ProjectSubscription::TIER_STANDARD,
            [
                ProjectSubscription::TIER_PRO => ['priceId' => 'price_pro'],
            ],
            $this->makeCommercialPlanRepository($plans)
        );

        self::assertSame('single', $result['mode']);
        self::assertCount(1, $result['options']);
        self::assertSame(ProjectSubscription::TIER_PRO, $result['options'][0]['targetTier']);
        self::assertSame('Pro', $result['options'][0]['name']);
        self::assertStringContainsString('Actualizar a Pro', $result['options'][0]['ctaLabel']);
    }

    public function testProProjectShowsNoUpgradeCta(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);

        $result = $this->invokeBuildUpgradeCta(
            $controller,
            $project,
            ProjectSubscription::TIER_PRO,
            [],
            $this->makeCommercialPlanRepository($this->makeDefaultCommercialPlans())
        );

        self::assertSame('none', $result['mode']);
        self::assertSame('Plan máximo', $result['label']);
        self::assertSame([], $result['options']);
    }

    public function testMissingStripePriceIdIsNotExposedAsPayableOption(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_BASIC);

        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId(null);
        $plans['pro']->setStripePriceId('price_pro');

        $result = $this->invokeBuildUpgradeCta(
            $controller,
            $project,
            ProjectSubscription::TIER_BASIC,
            [
                ProjectSubscription::TIER_STANDARD => ['priceId' => null],
                ProjectSubscription::TIER_PRO => ['priceId' => 'price_pro'],
            ],
            $this->makeCommercialPlanRepository($plans)
        );

        self::assertSame('single', $result['mode']);
        self::assertCount(1, $result['options']);
        self::assertSame(ProjectSubscription::TIER_PRO, $result['options'][0]['targetTier']);
    }

    private function getController(): PlanController
    {
        self::bootKernel();

        /** @var PlanController $controller */
        $controller = self::getContainer()->get(PlanController::class);

        return $controller;
    }

    /**
     * @param array<string, CommercialPlan> $plans
     */
    private function makeCommercialPlanRepository(array $plans): CommercialPlanRepository
    {
        $repository = $this->createMock(CommercialPlanRepository::class);
        $repository->method('findActiveByCode')->willReturnCallback(
            static function (string $code) use ($plans): ?CommercialPlan {
                return $plans[strtolower(trim($code))] ?? null;
            }
        );

        return $repository;
    }

    private function invokeBuildUpgradeCta(
        PlanController $controller,
        \App\Entity\Project $project,
        string $projectTier,
        array $availableUpgradeTargets,
        CommercialPlanRepository $commercialPlanRepository
    ): array {
        $reflection = new \ReflectionMethod($controller, 'buildUpgradeCta');
        $reflection->setAccessible(true);

        /** @var array $result */
        $result = $reflection->invoke($controller, $project, $projectTier, $availableUpgradeTargets, $commercialPlanRepository);

        return $result;
    }
}
