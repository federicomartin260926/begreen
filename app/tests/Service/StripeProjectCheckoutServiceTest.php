<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\CommercialPlanRepository;
use App\Service\StripeProjectCheckoutService;
use App\Tests\Support\CommercialPlanTestHelpers;
use App\Tests\Support\Stripe\FakeStripeClient;
use App\Tests\Support\Stripe\FakeStripeCheckoutFacade;
use App\Tests\Support\Stripe\FakeStripeCheckoutSessions;
use App\Tests\Support\Stripe\FakeStripeInvoicesFacade;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StripeProjectCheckoutServiceTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testBasicProjectCheckoutCreatesPendingSubscriptionAndUsesStandardPrice(): void
    {
        $sessionId = 'cs_test_basic_standard';
        $client = $this->createFakeStripeClient($sessionId);
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        $url = $service->startCheckout($project, ProjectSubscription::TIER_STANDARD);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertSame('price_standard', $client->checkout->sessions->createCalls[0]['line_items'][0]['price']);
        self::assertSame('https://example.test/success?session_id={CHECKOUT_SESSION_ID}', $client->checkout->sessions->createCalls[0]['success_url']);
        self::assertSame('https://example.test/cancel?session_id={CHECKOUT_SESSION_ID}', $client->checkout->sessions->createCalls[0]['cancel_url']);
        self::assertSame('standard', $client->checkout->sessions->createCalls[0]['metadata']['commercial_plan_code']);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscription()?->getStatus());
        self::assertSame(ProjectSubscription::SOURCE_STRIPE, $project->getSubscription()?->getSource());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscription()?->getTargetTier());
        self::assertSame($sessionId, $project->getSubscription()?->getStripeCheckoutSessionId());
        self::assertSame($sessionId, $project->getSubscription()?->getPaymentReference());
        self::assertSame('checkout_created', $project->getSubscription()?->getLastPaymentStatus());
        self::assertNull($project->getSubscription()?->getPaidAmountCents());
    }

    public function testStandardProjectCheckoutUsesUpgradePriceAndKeepsTargetTierPending(): void
    {
        $sessionId = 'cs_test_standard_pro';
        $client = $this->createFakeStripeClient($sessionId);
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_upgrade');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD);

        $url = $service->startCheckout($project, ProjectSubscription::TIER_PRO);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertSame('price_upgrade', $client->checkout->sessions->createCalls[0]['line_items'][0]['price']);
        self::assertSame('pro', $client->checkout->sessions->createCalls[0]['metadata']['commercial_plan_code']);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscription()?->getStatus());
        self::assertSame(ProjectSubscription::TIER_PRO, $project->getSubscription()?->getTargetTier());
        self::assertSame($sessionId, $project->getSubscription()?->getStripeCheckoutSessionId());
        self::assertSame(10000, $service->resolveTargetAmountCents($project, ProjectSubscription::TIER_PRO));
    }

    public function testBasicProjectCanUpgradeToStandardAndPro(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro');
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        self::assertTrue($service->canUpgrade($project, ProjectSubscription::TIER_STANDARD));
        self::assertTrue($service->canUpgrade($project, ProjectSubscription::TIER_PRO));
        self::assertSame(9900, $service->resolveTargetAmountCents($project, ProjectSubscription::TIER_STANDARD));
        self::assertSame(19900, $service->resolveTargetAmountCents($project, ProjectSubscription::TIER_PRO));

        $available = $service->getAvailableUpgradeTargets($project);
        self::assertArrayHasKey(ProjectSubscription::TIER_STANDARD, $available);
        self::assertArrayHasKey(ProjectSubscription::TIER_PRO, $available);
        self::assertSame('price_standard', $available[ProjectSubscription::TIER_STANDARD]['priceId']);
        self::assertSame('price_pro', $available[ProjectSubscription::TIER_PRO]['priceId']);
    }

    public function testStandardProjectOnlyCanUpgradeToProWithDifferenceAmount(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_upgrade');
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD);

        self::assertFalse($service->canUpgrade($project, ProjectSubscription::TIER_STANDARD));
        self::assertTrue($service->canUpgrade($project, ProjectSubscription::TIER_PRO));
        self::assertSame(10000, $service->resolveTargetAmountCents($project, ProjectSubscription::TIER_PRO));

        $available = $service->getAvailableUpgradeTargets($project);
        self::assertArrayNotHasKey(ProjectSubscription::TIER_STANDARD, $available);
        self::assertArrayHasKey(ProjectSubscription::TIER_PRO, $available);
        self::assertSame('price_upgrade', $available[ProjectSubscription::TIER_PRO]['priceId']);
    }

    public function testProProjectCannotUpgradeFurther(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro');
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_PRO);

        self::assertFalse($service->canUpgrade($project, ProjectSubscription::TIER_STANDARD));
        self::assertFalse($service->canUpgrade($project, ProjectSubscription::TIER_PRO));
        self::assertSame([], $service->getAvailableUpgradeTargets($project));
    }

    public function testMissingStripePriceIdFailsForPaidUpgrade(): void
    {
        $client = $this->createFakeStripeClient();
        $plans = $this->makeDefaultCommercialPlans();
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe price id is not configured for commercial plan "standard".');

        $service->startCheckout($project, ProjectSubscription::TIER_STANDARD);
    }

    public function testBasicTierCannotStartCheckout(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        $this->expectException(\InvalidArgumentException::class);

        $service->startCheckout($project, ProjectSubscription::TIER_BASIC);
    }

    private function createService(FakeStripeClient $stripeClient, array $plans, bool $expectFlush = false): StripeProjectCheckoutService
    {
        $gate = $this->makeProjectFeatureGate($plans);
        $planRepository = $this->createMock(CommercialPlanRepository::class);
        $indexedPlans = [];
        foreach ($plans as $plan) {
            if ($plan instanceof \App\Entity\CommercialPlan) {
                $indexedPlans[strtolower($plan->getCode())] = $plan;
            }
        }
        $planRepository->method('findActiveByCode')->willReturnCallback(
            static function (string $code) use ($indexedPlans): ?\App\Entity\CommercialPlan {
                return $indexedPlans[strtolower(trim($code))] ?? null;
            }
        );

        $em = $this->createMock(EntityManagerInterface::class);
        if ($expectFlush) {
            $em->expects(self::once())->method('flush');
            $em->expects(self::never())->method('persist');
        }

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/stripe');

        return new StripeProjectCheckoutService(
            $stripeClient,
            $gate,
            $planRepository,
            $em,
            $urlGenerator,
            'https://example.test/success?session_id={CHECKOUT_SESSION_ID}',
            'https://example.test/cancel?session_id={CHECKOUT_SESSION_ID}',
        );
    }

    private function createFakeStripeClient(string $sessionId = 'cs_test_default'): FakeStripeClient
    {
        $sessions = new FakeStripeCheckoutSessions();
        $sessions->createReturn = (object) [
            'id' => $sessionId,
            'url' => 'https://stripe.test/checkout',
        ];

        $checkout = new FakeStripeCheckoutFacade($sessions);
        $invoices = new FakeStripeInvoicesFacade();

        return new FakeStripeClient($checkout, $invoices);
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
