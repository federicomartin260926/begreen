<?php

namespace App\Tests\Service;

use App\Exception\PendingStripeCheckoutException;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\CommercialPlanRepository;
use App\Service\StripeCheckoutReconciliationResult;
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

    public function testPendingPaymentPreventsStartingASecondCheckout(): void
    {
        $client = $this->createFakeStripeClient();
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_pending', 42);

        try {
            $service->startCheckout($project, ProjectSubscription::TIER_STANDARD);
            self::fail('Expected PendingStripeCheckoutException.');
        } catch (PendingStripeCheckoutException) {
            self::assertCount(0, $client->checkout->sessions->createCalls);
        }
    }

    public function testPaidPendingCheckoutIsReconciledAndActivatesPlan(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_paid_1',
            'payment_status' => 'paid',
            'amount_total' => 9900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_paid_1'],
            'customer' => (object) ['id' => 'cus_paid_1'],
            'invoice' => (object) [
                'id' => 'in_paid_1',
                'number' => 'INV-2026-001',
                'hosted_invoice_url' => 'https://invoice.test/view',
                'invoice_pdf' => 'https://invoice.test/pdf',
            ],
            'metadata' => (object) [
                'project_id' => '42',
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_paid_1', 42);

        $result = $service->reconcilePendingCheckout($project, 'cs_paid_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_CONFIRMED, $result->status);
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscription()?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscription()?->getTier());
        self::assertSame(9900, $project->getSubscription()?->getPaidAmountCents());
        self::assertSame('EUR', $project->getSubscription()?->getCurrency());
        self::assertSame('INV-2026-001', $project->getSubscription()?->getPaymentReference());
        self::assertSame('paid', $project->getSubscription()?->getLastPaymentStatus());
        self::assertSame('pi_paid_1', $project->getSubscription()?->getStripePaymentIntentId());
        self::assertSame('in_paid_1', $project->getSubscription()?->getStripeInvoiceId());
        self::assertSame('cus_paid_1', $project->getSubscription()?->getStripeCustomerId());
        self::assertSame('https://invoice.test/view', $project->getSubscription()?->getStripeHostedInvoiceUrl());
        self::assertSame('https://invoice.test/pdf', $project->getSubscription()?->getStripeInvoicePdfUrl());
        self::assertSame('cs_paid_1', $project->getSubscription()?->getStripeCheckoutSessionId());
        self::assertNull($project->getSubscription()?->getTargetTier());
    }

    public function testUnpaidCheckoutStaysPending(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_unpaid_1',
            'payment_status' => 'unpaid',
            'metadata' => (object) [
                'project_id' => '42',
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_unpaid_1', 42);

        $result = $service->reconcilePendingCheckout($project, 'cs_unpaid_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_PENDING, $result->status);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscription()?->getStatus());
        self::assertSame('unpaid', $project->getSubscription()?->getLastPaymentStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscription()?->getTargetTier());
    }

    public function testMetadataMismatchDoesNotConfirmPayment(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_mismatch_1',
            'payment_status' => 'paid',
            'metadata' => (object) [
                'project_id' => '999',
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_mismatch_1', 42);

        $result = $service->reconcilePendingCheckout($project, 'cs_mismatch_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_MISMATCH, $result->status);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscription()?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscription()?->getTargetTier());
    }

    public function testAlreadyConfirmedCheckoutIsIdempotent(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_active_1',
            'payment_status' => 'paid',
            'amount_total' => 19900,
            'currency' => 'eur',
            'metadata' => (object) [
                'project_id' => '42',
                'target_tier' => ProjectSubscription::TIER_PRO,
                'commercial_plan_code' => 'pro',
            ],
            'payment_intent' => (object) ['id' => 'pi_active_1'],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['pro']->setStripePriceId('price_pro');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_PRO, ProjectSubscription::STATUS_ACTIVE, null, 'cs_active_1', 42);
        $project->getSubscription()?->setStripePaymentIntentId(null);
        $project->getSubscription()?->setPaymentReference(null);

        $result = $service->reconcilePendingCheckout($project, 'cs_active_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_ALREADY_CONFIRMED, $result->status);
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscription()?->getStatus());
        self::assertSame('pi_active_1', $project->getSubscription()?->getStripePaymentIntentId());
        self::assertSame('cs_active_1', $project->getSubscription()?->getStripeCheckoutSessionId());
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

    private function createProject(
        string $tier,
        ?string $status = null,
        ?string $targetTier = null,
        ?string $sessionId = null,
        ?int $projectId = null
    ): Project
    {
        $project = new Project();
        if ($projectId !== null) {
            $this->setEntityId($project, $projectId);
        }

        $subscription = (new ProjectSubscription())
            ->setTier($tier)
            ->setStatus($status ?? ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);

        if ($targetTier !== null) {
            $subscription->setTargetTier($targetTier);
        }
        if ($sessionId !== null) {
            $subscription->setStripeCheckoutSessionId($sessionId);
        }

        $project->setSubscription($subscription);

        return $project;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }
}
