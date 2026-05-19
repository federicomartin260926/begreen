<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\ProjectSubscriptionRepository;
use App\Service\ProjectFeatureGate;
use App\Service\StripeProjectCheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StripeProjectCheckoutServiceTest extends TestCase
{
    public function testBasicProjectCanUpgradeToStandardAndPro(): void
    {
        $service = $this->createService(ProjectSubscription::TIER_BASIC);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        self::assertTrue($service->canUpgrade($project, ProjectSubscription::TIER_STANDARD));
        self::assertTrue($service->canUpgrade($project, ProjectSubscription::TIER_PRO));
        self::assertSame(9900, $service->resolveTargetAmountCents($project, ProjectSubscription::TIER_STANDARD));
        self::assertSame(19900, $service->resolveTargetAmountCents($project, ProjectSubscription::TIER_PRO));

        $available = $service->getAvailableUpgradeTargets($project);
        self::assertArrayHasKey(ProjectSubscription::TIER_STANDARD, $available);
        self::assertArrayHasKey(ProjectSubscription::TIER_PRO, $available);
    }

    public function testStandardProjectOnlyCanUpgradeToProWithDifferenceAmount(): void
    {
        $service = $this->createService(ProjectSubscription::TIER_STANDARD);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD);

        self::assertFalse($service->canUpgrade($project, ProjectSubscription::TIER_STANDARD));
        self::assertTrue($service->canUpgrade($project, ProjectSubscription::TIER_PRO));
        self::assertSame(10000, $service->resolveTargetAmountCents($project, ProjectSubscription::TIER_PRO));

        $available = $service->getAvailableUpgradeTargets($project);
        self::assertArrayNotHasKey(ProjectSubscription::TIER_STANDARD, $available);
        self::assertArrayHasKey(ProjectSubscription::TIER_PRO, $available);
    }

    public function testProProjectCannotUpgradeFurther(): void
    {
        $service = $this->createService(ProjectSubscription::TIER_PRO);
        $project = $this->createProject(ProjectSubscription::TIER_PRO);

        self::assertFalse($service->canUpgrade($project, ProjectSubscription::TIER_STANDARD));
        self::assertFalse($service->canUpgrade($project, ProjectSubscription::TIER_PRO));
        self::assertSame([], $service->getAvailableUpgradeTargets($project));
    }

    private function createService(string $tier): StripeProjectCheckoutService
    {
        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProject')->willReturn(null);

        $gate = new ProjectFeatureGate($subscriptionRepository);

        $stripeClient = $this->createMock(StripeClient::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/stripe');

        return new StripeProjectCheckoutService(
            $stripeClient,
            $gate,
            $em,
            $urlGenerator,
            'price_standard',
            'price_pro',
            'price_upgrade',
            'https://example.test/success?session_id={CHECKOUT_SESSION_ID}',
            'https://example.test/cancel?session_id={CHECKOUT_SESSION_ID}',
        );
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
