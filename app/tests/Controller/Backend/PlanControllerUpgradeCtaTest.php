<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PlanController;
use App\Entity\CommercialPlan;
use App\Entity\Plan;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
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
            $this->makePlanWithProtocol(),
            CommercialPhase::ELABORATION,
            ProjectSubscription::TIER_BASIC,
            [
                ProjectSubscription::TIER_STANDARD => ['priceId' => 'price_standard'],
                ProjectSubscription::TIER_PRO => ['priceId' => 'price_pro'],
            ],
            $this->makeCommercialPlanRepository($plans),
            $this->makeMeasureRepository()
        );

        self::assertSame('modal', $result['mode']);
        self::assertSame('Mejorar plan', $result['label']);
        self::assertCount(2, $result['options']);
        self::assertSame(ProjectSubscription::TIER_STANDARD, $result['options'][0]['targetTier']);
        self::assertSame(ProjectSubscription::TIER_PRO, $result['options'][1]['targetTier']);
        self::assertSame(100, $result['options'][0]['measureCount']);
        self::assertSame(200, $result['options'][1]['measureCount']);
    }

    public function testStandardProjectShowsSingleDirectOption(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_STANDARD);

        $plans = $this->makeDefaultCommercialPlans();
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');

        $result = $this->invokeBuildUpgradeCta(
            $controller,
            $project,
            $this->makePlanWithProtocol(),
            CommercialPhase::ELABORATION,
            ProjectSubscription::TIER_STANDARD,
            [
                ProjectSubscription::TIER_PRO => ['priceId' => 'price_upgrade', 'amountCents' => 10000],
            ],
            $this->makeCommercialPlanRepository($plans),
            $this->makeMeasureRepository()
        );

        self::assertSame('single', $result['mode']);
        self::assertCount(1, $result['options']);
        self::assertSame(ProjectSubscription::TIER_PRO, $result['options'][0]['targetTier']);
        self::assertSame('Pro', $result['options'][0]['name']);
        self::assertStringContainsString('Actualizar a Pro', $result['options'][0]['ctaLabel']);
        self::assertStringContainsString('100,00 €', $result['options'][0]['ctaLabel']);
        self::assertSame(200, $result['options'][0]['measureCount']);
    }

    public function testProProjectShowsNoUpgradeCta(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);

        $result = $this->invokeBuildUpgradeCta(
            $controller,
            $project,
            $this->makePlanWithProtocol(),
            CommercialPhase::ELABORATION,
            ProjectSubscription::TIER_PRO,
            [],
            $this->makeCommercialPlanRepository($this->makeDefaultCommercialPlans()),
            $this->makeMeasureRepository()
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
            $this->makePlanWithProtocol(),
            CommercialPhase::ELABORATION,
            ProjectSubscription::TIER_BASIC,
            [
                ProjectSubscription::TIER_STANDARD => ['priceId' => null],
                ProjectSubscription::TIER_PRO => ['priceId' => 'price_pro'],
            ],
            $this->makeCommercialPlanRepository($plans),
            $this->makeMeasureRepository()
        );

        self::assertSame('single', $result['mode']);
        self::assertCount(1, $result['options']);
        self::assertSame(ProjectSubscription::TIER_PRO, $result['options'][0]['targetTier']);
    }

    public function testImplementationUpgradeCtaDoesNotExposeCheckoutBeforeStripePhaseSupport(): void
    {
        $controller = $this->getController();
        $project = $this->makeProjectWithTiers(ProjectSubscription::TIER_PRO, ProjectSubscription::TIER_BASIC);

        $result = $this->invokeBuildUpgradeCta(
            $controller,
            $project,
            $this->makePlanWithProtocol(),
            CommercialPhase::IMPLEMENTATION,
            ProjectSubscription::TIER_BASIC,
            [],
            $this->makeCommercialPlanRepository($this->makeDefaultCommercialPlans()),
            $this->makeMeasureRepository(),
            false
        );

        self::assertSame('unavailable', $result['mode']);
        self::assertSame(CommercialPhase::IMPLEMENTATION->value, $result['phase']);
        self::assertSame([], $result['options']);
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
        $repository->method('findActiveByPhaseAndCode')->willReturnCallback(
            static function (CommercialPhase $phase, string $code) use ($plans): ?CommercialPlan {
                $key = $phase === CommercialPhase::ELABORATION
                    ? strtolower(trim($code))
                    : $phase->value . '_' . strtolower(trim($code));

                return $plans[$key] ?? null;
            }
        );

        return $repository;
    }

    private function makeMeasureRepository(): MeasureRepository
    {
        $repository = $this->createMock(MeasureRepository::class);
        $repository->method('countCatalogMeasuresForProtocol')->willReturnCallback(
            static function (Protocol $protocol, array $allowedScores): int {
                if ($protocol->getCode() !== 'be-green-my-film') {
                    return 0;
                }

                sort($allowedScores);

                if ($allowedScores === [3, 4, 5]) {
                    return 100;
                }

                if ($allowedScores === [1, 2, 3, 4, 5]) {
                    return 200;
                }

                return 50;
            }
        );

        return $repository;
    }

    private function makePlanWithProtocol(): Plan
    {
        return (new Plan())
            ->setProtocol((new Protocol())->setCode('be-green-my-film'));
    }

    private function invokeBuildUpgradeCta(
        PlanController $controller,
        \App\Entity\Project $project,
        Plan $plan,
        CommercialPhase $phase,
        string $projectTier,
        array $availableUpgradeTargets,
        CommercialPlanRepository $commercialPlanRepository,
        MeasureRepository $measureRepository,
        bool $checkoutEnabled = true
    ): array {
        $reflection = new \ReflectionMethod($controller, 'buildUpgradeCta');
        $reflection->setAccessible(true);

        /** @var array $result */
        $result = $reflection->invoke($controller, $project, $plan, $phase, $projectTier, $availableUpgradeTargets, $commercialPlanRepository, $measureRepository, $checkoutEnabled);

        return $result;
    }
}
