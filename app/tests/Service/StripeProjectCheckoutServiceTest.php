<?php

namespace App\Tests\Service;

use App\Exception\PendingStripeCheckoutException;
use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
use App\Repository\PlanRepository;
use App\Service\StripeCheckoutReconciliationResult;
use App\Service\StripeInvoiceStorageService;
use App\Service\SustainabilityPlanCompletionService;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Service\SustainabilityPlanMeasureOrderer;
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
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, projectId: 42);

        $url = $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertSame('price_standard', $client->checkout->sessions->createCalls[0]['line_items'][0]['price']);
        self::assertSame('https://example.test/backend/project/42/subscription/elaboration/success/standard?session_id={CHECKOUT_SESSION_ID}', $client->checkout->sessions->createCalls[0]['success_url']);
        self::assertSame('https://example.test/backend/project/42/subscription/elaboration/cancel/standard?session_id={CHECKOUT_SESSION_ID}', $client->checkout->sessions->createCalls[0]['cancel_url']);
        self::assertSame('always', $client->checkout->sessions->createCalls[0]['customer_creation']);
        self::assertTrue($client->checkout->sessions->createCalls[0]['invoice_creation']['enabled']);
        self::assertSame(CommercialPhase::ELABORATION->value, $client->checkout->sessions->createCalls[0]['metadata']['commercial_phase']);
        self::assertSame(CommercialPhase::ELABORATION->value, $client->checkout->sessions->createCalls[0]['payment_intent_data']['metadata']['commercial_phase']);
        self::assertSame('standard', $client->checkout->sessions->createCalls[0]['metadata']['commercial_plan_code']);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::SOURCE_MANUAL, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getSource());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
        self::assertSame($sessionId, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertSame('checkout_created', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getLastPaymentStatus());
        self::assertNull($project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getPaidAmountCents());
    }

    public function testStandardProjectCheckoutUsesUpgradePriceAndKeepsTargetTierPending(): void
    {
        $sessionId = 'cs_test_standard_pro';
        $client = $this->createFakeStripeClient($sessionId);
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD);

        $url = $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertSame('price_upgrade', $client->checkout->sessions->createCalls[0]['line_items'][0]['price']);
        self::assertSame('pro', $client->checkout->sessions->createCalls[0]['metadata']['commercial_plan_code']);
        self::assertSame('standard', $client->checkout->sessions->createCalls[0]['metadata']['current_tier']);
        self::assertSame('pro', $client->checkout->sessions->createCalls[0]['metadata']['target_tier']);
        self::assertSame('standard', $client->checkout->sessions->createCalls[0]['metadata']['upgrade_from_tier']);
        self::assertSame('standard_to_pro', $client->checkout->sessions->createCalls[0]['metadata']['upgrade_type']);
        self::assertSame('standard', $client->checkout->sessions->createCalls[0]['payment_intent_data']['metadata']['upgrade_from_tier']);
        self::assertSame('standard_to_pro', $client->checkout->sessions->createCalls[0]['payment_intent_data']['metadata']['upgrade_type']);
        self::assertSame('always', $client->checkout->sessions->createCalls[0]['customer_creation']);
        self::assertTrue($client->checkout->sessions->createCalls[0]['invoice_creation']['enabled']);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::TIER_PRO, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
        self::assertSame($sessionId, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertSame(2000, $service->resolveTargetAmountCents($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO));
    }

    public function testImplementationCheckoutUsesImplementationPriceAndDoesNotTouchElaborationSubscription(): void
    {
        $sessionId = 'cs_test_implementation_standard';
        $client = $this->createFakeStripeClient($sessionId);
        $plans = $this->makeDefaultCommercialPlans();
        $plans['implementation_standard']->setStripePriceId('price_implementation_standard');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_PRO, projectId: 43);
        $implementationSubscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);
        $project->addSubscription($implementationSubscription);

        $url = $service->startCheckout($project, CommercialPhase::IMPLEMENTATION, ProjectSubscription::TIER_STANDARD);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertSame('price_implementation_standard', $client->checkout->sessions->createCalls[0]['line_items'][0]['price']);
        self::assertSame('https://example.test/backend/project/43/subscription/implementation/success/standard?session_id={CHECKOUT_SESSION_ID}', $client->checkout->sessions->createCalls[0]['success_url']);
        self::assertSame('https://example.test/backend/project/43/subscription/implementation/cancel/standard?session_id={CHECKOUT_SESSION_ID}', $client->checkout->sessions->createCalls[0]['cancel_url']);
        self::assertSame(CommercialPhase::IMPLEMENTATION->value, $client->checkout->sessions->createCalls[0]['metadata']['commercial_phase']);
        self::assertSame('standard', $client->checkout->sessions->createCalls[0]['metadata']['commercial_plan_code']);
        self::assertSame(ProjectSubscription::TIER_PRO, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertNull($project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $implementationSubscription->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $implementationSubscription->getTargetTier());
        self::assertSame($sessionId, $implementationSubscription->getStripeCheckoutSessionId());
    }

    public function testBasicProjectCanUpgradeToStandardAndPro(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro');
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        self::assertTrue($service->canUpgrade($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD));
        self::assertTrue($service->canUpgrade($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO));
        self::assertSame(2900, $service->resolveTargetAmountCents($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD));
        self::assertSame(4900, $service->resolveTargetAmountCents($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO));

        $available = $service->getAvailableUpgradeTargets($project, CommercialPhase::ELABORATION);
        self::assertArrayHasKey(ProjectSubscription::TIER_STANDARD, $available);
        self::assertArrayHasKey(ProjectSubscription::TIER_PRO, $available);
        self::assertSame('price_standard', $available[ProjectSubscription::TIER_STANDARD]['priceId']);
        self::assertSame('price_pro', $available[ProjectSubscription::TIER_PRO]['priceId']);
    }

    public function testStandardProjectOnlyCanUpgradeToProWithDifferenceAmount(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD);

        self::assertFalse($service->canUpgrade($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD));
        self::assertTrue($service->canUpgrade($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO));
        self::assertSame(2000, $service->resolveTargetAmountCents($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO));

        $available = $service->getAvailableUpgradeTargets($project, CommercialPhase::ELABORATION);
        self::assertArrayNotHasKey(ProjectSubscription::TIER_STANDARD, $available);
        self::assertArrayHasKey(ProjectSubscription::TIER_PRO, $available);
        self::assertSame('price_upgrade', $available[ProjectSubscription::TIER_PRO]['priceId']);
        self::assertSame(2000, $available[ProjectSubscription::TIER_PRO]['amountCents']);
    }

    public function testStandardProjectDoesNotOfferProUpgradeWithoutDifferentialPriceId(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId(null);
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD);

        self::assertSame([], $service->getAvailableUpgradeTargets($project, CommercialPhase::ELABORATION));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe upgrade price id is not configured for the Standard -> Pro transition.');

        $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO);
    }

    public function testProProjectCannotUpgradeFurther(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro');
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_PRO);

        self::assertFalse($service->canUpgrade($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD));
        self::assertFalse($service->canUpgrade($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO));
        self::assertSame([], $service->getAvailableUpgradeTargets($project, CommercialPhase::ELABORATION));
    }

    public function testMissingStripePriceIdFailsForPaidUpgrade(): void
    {
        $client = $this->createFakeStripeClient();
        $plans = $this->makeDefaultCommercialPlans();
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe price id is not configured for commercial plan "standard".');

        $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD);
    }

    public function testBasicTierCannotStartCheckout(): void
    {
        $plans = $this->makeDefaultCommercialPlans();
        $service = $this->createService($this->createFakeStripeClient(), $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC);

        $this->expectException(\InvalidArgumentException::class);

        $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_BASIC);
    }

    public function testOpenPendingCheckoutIsReusedInsteadOfStartingANewOne(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_pending',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'expires_at' => time() + 3600,
            'url' => 'https://stripe.test/reused',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_pending', 42);

        $url = $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD);

        self::assertSame('https://stripe.test/reused', $url);
        self::assertCount(0, $client->checkout->sessions->createCalls);
        self::assertCount(1, $client->checkout->sessions->retrieveCalls);
    }

    public function testOpenPendingCheckoutWithExpiredTimestampIsReplacedWithNewSession(): void
    {
        $client = $this->createFakeStripeClient('cs_new_checkout');
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_pending_expired',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'expires_at' => time() - 60,
            'url' => 'https://stripe.test/expired',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_pending_expired', 42);

        $url = $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertSame('cs_new_checkout', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertCount(1, $client->checkout->sessions->retrieveCalls);
    }

    public function testCompletePendingCheckoutWithoutPaidStatusIsReplacedWithNewSession(): void
    {
        $client = $this->createFakeStripeClient('cs_complete_retry');
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_pending_complete_unpaid',
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_pending_complete_unpaid', 42);

        $url = $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_STANDARD);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertSame('cs_complete_retry', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertCount(1, $client->checkout->sessions->retrieveCalls);
    }

    public function testLatePaidSessionAfterLocalCancelStillReconcilesIdempotently(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_late_paid',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 2900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_late_paid'],
            'customer' => (object) ['id' => 'cus_late_paid'],
            'invoice' => (object) [
                'id' => 'in_late_paid',
                'number' => 'INV-2026-099',
                'hosted_invoice_url' => 'https://invoice.test/late/view',
                'invoice_pdf' => 'https://invoice.test/late/pdf',
            ],
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_late_paid', 42);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $this->assertInstanceOf(ProjectSubscription::class, $subscription);
        $this->createService($client, $plans, false)->clearPendingCheckout($subscription);
        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('upsertFromStripeCheckout')
            ->with(
                $subscription,
                self::isType('object'),
                self::isType('object')
            )
            ->willReturn(new \App\Entity\ProjectBillingDocument());

        $service = $this->createService($client, $plans, true, $invoiceStorage);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_late_paid');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_CONFIRMED, $result->status);
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $subscription->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $subscription->getTier());
        self::assertNull($subscription->getTargetTier());
    }

    public function testPendingElaborationDoesNotBlockImplementationCheckout(): void
    {
        $client = $this->createFakeStripeClient('cs_implementation_checkout');
        $plans = $this->makeDefaultCommercialPlans();
        $plans['implementation_standard']->setStripePriceId('price_implementation_standard');
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_pending_elaboration', 43);
        $implementationSubscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);
        $project->addSubscription($implementationSubscription);

        $url = $service->startCheckout($project, CommercialPhase::IMPLEMENTATION, ProjectSubscription::TIER_STANDARD);

        self::assertSame('https://stripe.test/checkout', $url);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame('cs_pending_elaboration', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $implementationSubscription->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $implementationSubscription->getTargetTier());
        self::assertSame('cs_implementation_checkout', $implementationSubscription->getStripeCheckoutSessionId());
        self::assertCount(1, $client->checkout->sessions->createCalls);
        self::assertCount(0, $client->checkout->sessions->retrieveCalls);
    }

    public function testActiveUpgradePreventsStartingASecondCheckout(): void
    {
        $client = $this->createFakeStripeClient();
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_PRO, 'cs_active_upgrade', 42);

        $this->expectException(PendingStripeCheckoutException::class);

        $service->startCheckout($project, CommercialPhase::ELABORATION, ProjectSubscription::TIER_PRO);
    }

    public function testPaidPendingCheckoutIsReconciledAndActivatesPlan(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_paid_1',
            'payment_status' => 'paid',
            'amount_total' => 2900,
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
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_paid_1', 42);
        $invoiceDocument = new ProjectBillingDocument();
        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('upsertFromStripeCheckout')
            ->with(
                $project->getSubscriptionForPhase(CommercialPhase::ELABORATION),
                self::isType('object'),
                self::isType('object')
            )
            ->willReturn($invoiceDocument);
        $invoiceStorage->expects(self::never())->method('syncInvoicePdf');
        $service = $this->createService($client, $plans, true, $invoiceStorage);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_paid_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_CONFIRMED, $result->status);
        self::assertSame(['cs_paid_1'], array_column($client->checkout->sessions->retrieveCalls, 'sessionId'));
        self::assertContains('customer', $client->checkout->sessions->retrieveCalls[0]['options']['expand']);
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertSame(2900, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getPaidAmountCents());
        self::assertSame('EUR', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getCurrency());
        self::assertSame('INV-2026-001', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getPaymentReference());
        self::assertSame('paid', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getLastPaymentStatus());
        self::assertSame('pi_paid_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripePaymentIntentId());
        self::assertSame('in_paid_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeInvoiceId());
        self::assertSame('cus_paid_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCustomerId());
        self::assertSame('https://invoice.test/view', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeHostedInvoiceUrl());
        self::assertSame('https://invoice.test/pdf', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeInvoicePdfUrl());
        self::assertSame('cs_paid_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertNull($project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
    }

    public function testPaidPendingCheckoutWithoutInvoiceStillActivatesPlan(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_paid_no_invoice',
            'payment_status' => 'paid',
            'amount_total' => 2900,
            'currency' => 'eur',
            'payment_intent' => 'pi_paid_no_invoice',
            'customer' => 'cus_paid_no_invoice',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('upsertFromStripeCheckout')
            ->with(
                self::isInstanceOf(ProjectSubscription::class),
                self::isType('object'),
                self::isNull()
            )
            ->willReturn(new ProjectBillingDocument());
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_paid_no_invoice', 42);
        $service = $this->createService($client, $plans, true, $invoiceStorage);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_paid_no_invoice');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_CONFIRMED, $result->status);
        self::assertSame(['cs_paid_no_invoice'], array_column($client->checkout->sessions->retrieveCalls, 'sessionId'));
        self::assertContains('customer', $client->checkout->sessions->retrieveCalls[0]['options']['expand']);
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame('pi_paid_no_invoice', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripePaymentIntentId());
        self::assertSame('cus_paid_no_invoice', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCustomerId());
        self::assertNull($project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeHostedInvoiceUrl());
        self::assertNull($project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeInvoicePdfUrl());
    }

    public function testUnpaidCheckoutStaysPending(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_unpaid_1',
            'payment_status' => 'unpaid',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans, true);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_unpaid_1', 42);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_unpaid_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_PENDING, $result->status);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame('unpaid', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getLastPaymentStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
    }

    public function testMetadataMismatchDoesNotConfirmPayment(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_mismatch_1',
            'payment_status' => 'paid',
            'metadata' => (object) [
                'project_id' => '999',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD, 'cs_mismatch_1', 42);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_mismatch_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_MISMATCH, $result->status);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTargetTier());
    }

    public function testReconciliationDoesNotConfirmWhenCommercialPhaseMetadataDoesNotMatchRequestedPhase(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_phase_mismatch',
            'payment_status' => 'paid',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_STANDARD,
                'commercial_plan_code' => 'standard',
            ],
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['implementation_standard']->setStripePriceId('price_implementation_standard');
        $service = $this->createService($client, $plans);
        $project = $this->createProject(ProjectSubscription::TIER_PRO, projectId: 42);
        $implementationSubscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_PENDING_PAYMENT)
            ->setSource(ProjectSubscription::SOURCE_MANUAL)
            ->setTargetTier(ProjectSubscription::TIER_STANDARD)
            ->setStripeCheckoutSessionId('cs_phase_mismatch');
        $project->addSubscription($implementationSubscription);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::IMPLEMENTATION, 'cs_phase_mismatch');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_MISMATCH, $result->status);
        self::assertSame(ProjectSubscription::STATUS_PENDING_PAYMENT, $implementationSubscription->getStatus());
        self::assertSame(ProjectSubscription::TIER_BASIC, $implementationSubscription->getTier());
        self::assertSame(ProjectSubscription::TIER_PRO, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
    }

    public function testAlreadyConfirmedCheckoutIsIdempotent(): void
    {
        $client = $this->createFakeStripeClient();
        $client->invoices->retrieveReturn = (object) [
            'id' => 'in_active_1',
            'number' => 'INV-2026-010',
            'hosted_invoice_url' => 'https://invoice.test/active/view',
            'invoice_pdf' => 'https://invoice.test/active/pdf',
        ];
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_active_1',
            'payment_status' => 'paid',
            'amount_total' => 4900,
            'currency' => 'eur',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_PRO,
                'commercial_plan_code' => 'pro',
            ],
            'payment_intent' => 'pi_active_1',
            'customer' => 'cus_active_1',
            'invoice' => 'in_active_1',
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['pro']->setStripePriceId('price_pro');
        $project = $this->createProject(ProjectSubscription::TIER_PRO, ProjectSubscription::STATUS_ACTIVE, null, 'cs_active_1', 42);
        $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->setStripePaymentIntentId(null);
        $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->setPaymentReference(null);
        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('upsertFromStripeCheckout')
            ->with(
                $project->getSubscriptionForPhase(CommercialPhase::ELABORATION),
                self::isType('object'),
                self::isType('object')
            )
            ->willReturn(new ProjectBillingDocument());
        $service = $this->createService($client, $plans, true, $invoiceStorage);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_active_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_ALREADY_CONFIRMED, $result->status);
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame('pi_active_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripePaymentIntentId());
        self::assertSame('cus_active_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCustomerId());
        self::assertSame('in_active_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeInvoiceId());
        self::assertSame('https://invoice.test/active/view', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeHostedInvoiceUrl());
        self::assertSame('https://invoice.test/active/pdf', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeInvoicePdfUrl());
        self::assertSame('cs_active_1', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeCheckoutSessionId());
        self::assertSame(['in_active_1'], $client->invoices->retrieveCalls);
    }

    public function testInvoiceObjectWithoutPdfTriggersInvoiceRefetch(): void
    {
        $client = $this->createFakeStripeClient();
        $client->invoices->retrieveReturn = (object) [
            'id' => 'in_invoice_refetch_1',
            'number' => 'INV-2026-011',
            'hosted_invoice_url' => 'https://invoice.test/refetch/view',
            'invoice_pdf' => 'https://invoice.test/refetch/pdf',
            'created' => 1717495200,
        ];
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_invoice_refetch_1',
            'payment_status' => 'paid',
            'amount_total' => 4900,
            'currency' => 'eur',
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_PRO,
                'commercial_plan_code' => 'pro',
            ],
            'payment_intent' => 'pi_invoice_refetch_1',
            'customer' => 'cus_invoice_refetch_1',
            'invoice' => 'in_invoice_refetch_1',
        ];
        $plans = $this->makeDefaultCommercialPlans();
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');
        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('upsertFromStripeCheckout')
            ->with(
                self::isInstanceOf(ProjectSubscription::class),
                self::isType('object'),
                self::isType('object')
            )
            ->willReturn(new ProjectBillingDocument());

        $project = $this->createProject(ProjectSubscription::TIER_STANDARD, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_PRO, 'cs_invoice_refetch_1', 42);
        $service = $this->createService($client, $plans, true, $invoiceStorage);

        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_invoice_refetch_1');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_CONFIRMED, $result->status);
        self::assertSame(['in_invoice_refetch_1'], $client->invoices->retrieveCalls);
        self::assertSame('https://invoice.test/refetch/pdf', $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStripeInvoicePdfUrl());
    }

    public function testPaidPendingCheckoutFlagsPlanWhenUpgradeMakesItIncomplete(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_paid_upgrade_pending',
            'payment_status' => 'paid',
            'amount_total' => 2000,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_paid_upgrade_pending'],
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_PRO,
                'commercial_plan_code' => 'pro',
            ],
        ];

        $plans = $this->makeDefaultCommercialPlans();
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');

        $basicMeasure = $this->createMeasure(101, 5);
        $pendingMeasure = $this->createMeasure(102, 3);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_PRO, 'cs_paid_upgrade_pending', 42);
        $plan = $this->createPlan($project, [
            $basicMeasure,
        ], [
            $this->createPlanMeasure(true, false, true),
        ]);

        $service = $this->createService($client, $plans, true, null, $plan, [$basicMeasure, $pendingMeasure]);
        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_paid_upgrade_pending');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_CONFIRMED, $result->status);
        self::assertTrue($result->planBecameIncompleteAfterUpgrade());
        self::assertSame('incompleto', $plan->getStatus());
    }

    public function testPaidPendingCheckoutDoesNotFlagPlanWhenUpgradeKeepsItComplete(): void
    {
        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_paid_upgrade_complete',
            'payment_status' => 'paid',
            'amount_total' => 2000,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_paid_upgrade_complete'],
            'metadata' => (object) [
                'project_id' => '42',
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_PRO,
                'commercial_plan_code' => 'pro',
            ],
        ];

        $plans = $this->makeDefaultCommercialPlans();
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');

        $basicMeasure = $this->createMeasure(201, 5);
        $pendingMeasure = $this->createMeasure(202, 3);
        $project = $this->createProject(ProjectSubscription::TIER_STANDARD, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_PRO, 'cs_paid_upgrade_complete', 42);
        $plan = $this->createPlan($project, [
            $basicMeasure,
            $pendingMeasure,
        ], [
            $this->createPlanMeasure(true, false, true),
            $this->createPlanMeasure(true, false, true),
        ]);

        $service = $this->createService($client, $plans, true, null, $plan, [$basicMeasure, $pendingMeasure]);
        $result = $service->reconcilePendingCheckout($project, CommercialPhase::ELABORATION, 'cs_paid_upgrade_complete');

        self::assertSame(StripeCheckoutReconciliationResult::STATUS_CONFIRMED, $result->status);
        self::assertFalse($result->planBecameIncompleteAfterUpgrade());
        self::assertSame('completo', $plan->getStatus());
    }

    private function createService(FakeStripeClient $stripeClient, array $plans, bool $expectFlush = false, ?StripeInvoiceStorageService $invoiceStorage = null, ?\App\Entity\Plan $plan = null, ?array $measures = null): StripeProjectCheckoutService
    {
        $gate = $this->makeProjectFeatureGate($plans);
        $planRepository = $this->createMock(CommercialPlanRepository::class);
        $projectPlanRepository = $this->createMock(PlanRepository::class);
        $projectPlanRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($plan): ?\App\Entity\Plan {
                return ($plan instanceof \App\Entity\Plan && ($criteria['project'] ?? null) === $plan->getProject()) ? $plan : null;
            }
        );
        $indexedPlans = [];
        foreach ($plans as $commercialPlan) {
            if ($commercialPlan instanceof \App\Entity\CommercialPlan) {
                $indexedPlans[$commercialPlan->getPhase()->value . ':' . strtolower($commercialPlan->getCode())] = $commercialPlan;
            }
        }
        $planRepository->method('findActiveByPhaseAndCode')->willReturnCallback(
            static function (\App\Enum\CommercialPhase $phase, string $code) use ($indexedPlans): ?\App\Entity\CommercialPlan {
                return $indexedPlans[$phase->value . ':' . strtolower(trim($code))] ?? null;
            }
        );

        $em = $this->createMock(EntityManagerInterface::class);
        if ($expectFlush) {
            $em->expects(self::once())->method('flush');
        }

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/stripe');

        if ($invoiceStorage === null) {
            $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
            $invoiceStorage->method('upsertFromStripeCheckout')->willReturn(new ProjectBillingDocument());
        }

        $completionService = new SustainabilityPlanCompletionService(
            $this->createMeasureRepositoryMock($measures ?? []),
            new PlanMeasureCatalogResolver($gate),
            new SustainabilityPlanMeasureOrderer(),
        );

        return new StripeProjectCheckoutService(
            $stripeClient,
            $gate,
            $planRepository,
            $projectPlanRepository,
            $em,
            $invoiceStorage,
            $urlGenerator,
            'https://example.test/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/success/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
            'https://example.test/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/cancel/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
            $completionService,
        );
    }

    /**
     * @param array<int, \App\Entity\Measure> $measures
     */
    private function createMeasureRepositoryMock(array $measures): MeasureRepository
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn($measures);

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        foreach (['join', 'leftJoin', 'addSelect', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);

        $repository = $this->createMock(MeasureRepository::class);
        $repository->method('createQueryBuilder')->willReturn($qb);

        return $repository;
    }

    /**
     * @param array<int, \App\Entity\PlanMeasure>|null $planMeasures
     */
    private function createPlan(\App\Entity\Project $project, array $measures = [], ?array $planMeasures = null): \App\Entity\Plan
    {
        $protocol = (new \App\Entity\Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(\App\Entity\Protocol::TYPE_RODAJE)
            ->setGroupingBy(\App\Entity\Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $plan = (new \App\Entity\Plan())
            ->setProject($project)
            ->setUser(new \App\Entity\User())
            ->setProtocol($protocol)
            ->setStatus('completo');

        foreach ($measures as $index => $measure) {
            $planMeasure = $planMeasures[$index] ?? $this->createPlanMeasure(true, false, true);
            $planMeasure->setMeasure($measure);
            $plan->addPlanMeasure($planMeasure);
        }

        return $plan;
    }

    private function createPlanMeasure(?bool $applicable, ?bool $critical, ?bool $willImplement): \App\Entity\PlanMeasure
    {
        return (new \App\Entity\PlanMeasure())
            ->setIsApplicable($applicable)
            ->setIsCritical($critical)
            ->setWillImplement($willImplement)
            ->markAsManual();
    }

    private function createMeasure(int $id, int $score): \App\Entity\Measure
    {
        $protocol = (new \App\Entity\Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(\App\Entity\Protocol::TYPE_RODAJE)
            ->setGroupingBy(\App\Entity\Protocol::GROUP_BY_CATEGORY);
        $this->setEntityId($protocol, 1);

        $measure = (new \App\Entity\Measure())
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore($score);
        $this->setEntityId($measure, $id);

        return $measure;
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
            ->setPhase(CommercialPhase::ELABORATION)
            ->setTier($tier)
            ->setStatus($status ?? ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);

        if ($targetTier !== null) {
            $subscription->setTargetTier($targetTier);
        }
        if ($sessionId !== null) {
            $subscription->setStripeCheckoutSessionId($sessionId);
        }

        $project->addSubscription($subscription);

        return $project;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }
}
