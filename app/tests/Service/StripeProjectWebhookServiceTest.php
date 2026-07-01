<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
use App\Repository\PlanRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectSubscriptionRepository;
use App\Service\CommercialPlanResolver;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Service\SustainabilityPlanCompletionService;
use App\Service\SustainabilityPlanMeasureOrderer;
use App\Service\StripeProjectWebhookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class StripeProjectWebhookServiceTest extends TestCase
{
    public function testCompletedCheckoutActivatesTargetTierAndStoresStripeReferences(): void
    {
        $subscription = $this->createSubscription(ProjectSubscription::TIER_STANDARD, ProjectSubscription::STATUS_PENDING_PAYMENT);
        $subscription->setStripeCheckoutSessionId('cs_test_123');
        $subscription->setTargetTier(ProjectSubscription::TIER_PRO);

        $service = $this->createService($subscription);

        $service->processCompletedCheckoutSession([
            'id' => 'cs_test_123',
            'project_id' => 42,
            'target_tier' => ProjectSubscription::TIER_PRO,
            'current_tier' => ProjectSubscription::TIER_STANDARD,
            'payment_intent_id' => 'pi_test_123',
            'customer_id' => 'cus_test_123',
            'currency' => 'eur',
            'amount_total' => 10000,
            'payment_status' => 'paid',
            'invoice' => [
                'id' => 'in_test_123',
                'number' => 'INV-2026-001',
                'hosted_invoice_url' => 'https://stripe.test/invoice',
                'invoice_pdf' => 'https://stripe.test/invoice.pdf',
            ],
        ]);

        self::assertSame(ProjectSubscription::TIER_PRO, $subscription->getTier());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $subscription->getStatus());
        self::assertSame(ProjectSubscription::SOURCE_STRIPE, $subscription->getSource());
        self::assertSame(10000, $subscription->getPaidAmountCents());
        self::assertSame('EUR', $subscription->getCurrency());
        self::assertSame('INV-2026-001', $subscription->getPaymentReference());
        self::assertSame('cs_test_123', $subscription->getStripeCheckoutSessionId());
        self::assertSame('pi_test_123', $subscription->getStripePaymentIntentId());
        self::assertSame('in_test_123', $subscription->getStripeInvoiceId());
        self::assertSame('cus_test_123', $subscription->getStripeCustomerId());
        self::assertSame('https://stripe.test/invoice', $subscription->getStripeHostedInvoiceUrl());
        self::assertSame('https://stripe.test/invoice.pdf', $subscription->getStripeInvoicePdfUrl());
        self::assertSame('paid', $subscription->getLastPaymentStatus());
        self::assertNull($subscription->getTargetTier());
        self::assertInstanceOf(\DateTimeImmutable::class, $subscription->getPaidAt());
    }

    public function testCompletedCheckoutWithoutInvoiceStillActivatesTargetTierAndUsesFallbackReference(): void
    {
        $subscription = $this->createSubscription(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT);
        $subscription->setStripeCheckoutSessionId('cs_test_fallback');
        $subscription->setTargetTier(ProjectSubscription::TIER_STANDARD);

        $service = $this->createService($subscription);

        $service->processCompletedCheckoutSession([
            'id' => 'cs_test_fallback',
            'project_id' => 42,
            'target_tier' => ProjectSubscription::TIER_STANDARD,
            'payment_intent_id' => 'pi_test_fallback',
            'currency' => 'eur',
            'amount_total' => 9900,
            'payment_status' => 'paid',
        ]);

        self::assertSame(ProjectSubscription::TIER_STANDARD, $subscription->getTier());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $subscription->getStatus());
        self::assertSame('cs_test_fallback', $subscription->getPaymentReference());
        self::assertSame('pi_test_fallback', $subscription->getStripePaymentIntentId());
        self::assertNull($subscription->getStripeInvoiceId());
        self::assertNull($subscription->getStripeCustomerId());
        self::assertNull($subscription->getStripeHostedInvoiceUrl());
        self::assertNull($subscription->getStripeInvoicePdfUrl());
    }

    public function testCompletedCheckoutIsIdempotent(): void
    {
        $subscription = $this->createSubscription(ProjectSubscription::TIER_STANDARD, ProjectSubscription::STATUS_PENDING_PAYMENT);
        $subscription->setStripeCheckoutSessionId('cs_test_123');
        $subscription->setTargetTier(ProjectSubscription::TIER_PRO);

        $service = $this->createService($subscription);
        $payload = [
            'id' => 'cs_test_123',
            'project_id' => 42,
            'target_tier' => ProjectSubscription::TIER_PRO,
            'payment_intent_id' => 'pi_test_123',
            'customer_id' => 'cus_test_123',
            'currency' => 'eur',
            'amount_total' => 10000,
            'payment_status' => 'paid',
            'invoice' => [
                'id' => 'in_test_123',
                'number' => 'INV-2026-001',
            ],
        ];

        $service->processCompletedCheckoutSession($payload);
        $service->processCompletedCheckoutSession($payload);

        self::assertSame(ProjectSubscription::TIER_PRO, $subscription->getTier());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $subscription->getStatus());
        self::assertSame('INV-2026-001', $subscription->getPaymentReference());
    }

    public function testCompletedCheckoutFillsMissingStripeReferencesWhenAlreadyActive(): void
    {
        $subscription = $this->createSubscription(ProjectSubscription::TIER_STANDARD, ProjectSubscription::STATUS_ACTIVE);
        $subscription->setStripeCheckoutSessionId('cs_test_123');
        $subscription->setTargetTier(ProjectSubscription::TIER_PRO);

        $service = $this->createService($subscription);
        $service->processCompletedCheckoutSession([
            'id' => 'cs_test_123',
            'project_id' => 42,
            'target_tier' => ProjectSubscription::TIER_PRO,
            'payment_intent_id' => 'pi_test_123',
            'customer_id' => 'cus_test_123',
            'currency' => 'eur',
            'amount_total' => 10000,
            'payment_status' => 'paid',
            'invoice' => [
                'id' => 'in_test_123',
                'number' => 'INV-2026-001',
                'hosted_invoice_url' => 'https://stripe.test/invoice',
                'invoice_pdf' => 'https://stripe.test/invoice.pdf',
            ],
        ]);

        self::assertSame(ProjectSubscription::TIER_PRO, $subscription->getTier());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $subscription->getStatus());
        self::assertSame('INV-2026-001', $subscription->getPaymentReference());
        self::assertSame('pi_test_123', $subscription->getStripePaymentIntentId());
        self::assertSame('in_test_123', $subscription->getStripeInvoiceId());
        self::assertSame('cus_test_123', $subscription->getStripeCustomerId());
        self::assertSame('https://stripe.test/invoice', $subscription->getStripeHostedInvoiceUrl());
        self::assertSame('https://stripe.test/invoice.pdf', $subscription->getStripeInvoicePdfUrl());
        self::assertSame('paid', $subscription->getLastPaymentStatus());
        self::assertNull($subscription->getTargetTier());
    }

    public function testExpiredCheckoutMarksCancellation(): void
    {
        $subscription = $this->createSubscription(ProjectSubscription::TIER_BASIC, ProjectSubscription::STATUS_PENDING_PAYMENT);
        $subscription->setStripeCheckoutSessionId('cs_test_expired');
        $subscription->setTargetTier(ProjectSubscription::TIER_STANDARD);

        $service = $this->createService($subscription);
        $service->processCheckoutSessionExpired([
            'id' => 'cs_test_expired',
            'project_id' => 42,
            'target_tier' => ProjectSubscription::TIER_STANDARD,
        ]);

        self::assertSame(ProjectSubscription::STATUS_CANCELLED, $subscription->getStatus());
        self::assertSame('expired', $subscription->getLastPaymentStatus());
        self::assertSame('cs_test_expired', $subscription->getStripeCheckoutSessionId());
    }

    public function testCompletedCheckoutWithoutMatchingSubscriptionIsIgnored(): void
    {
        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByStripeCheckoutSessionId')->willReturn(null);

        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->method('find')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new StripeProjectWebhookService(
            $entityManager,
            $subscriptionRepository,
            $projectRepository,
            $this->createMock(PlanRepository::class),
            new SustainabilityPlanCompletionService(
                $this->createMock(MeasureRepository::class),
                new PlanMeasureCatalogResolver($this->createFeatureGate()),
                new SustainabilityPlanMeasureOrderer(),
            ),
            'whsec_test',
        );

        $service->processCompletedCheckoutSession([
            'id' => 'cs_unknown',
            'project_id' => 9999,
            'target_tier' => ProjectSubscription::TIER_PRO,
            'payment_intent_id' => 'pi_unknown',
        ]);

        self::assertTrue(true);
    }

    private function createService(ProjectSubscription $subscription): StripeProjectWebhookService
    {
        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByStripeCheckoutSessionId')->willReturn($subscription);

        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->method('find')->willReturn((new Project())->setSubscription($subscription));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('flush');

        return new StripeProjectWebhookService(
            $entityManager,
            $subscriptionRepository,
            $projectRepository,
            $this->createMock(PlanRepository::class),
            new SustainabilityPlanCompletionService(
                $this->createMock(MeasureRepository::class),
                new PlanMeasureCatalogResolver($this->createFeatureGate()),
                new SustainabilityPlanMeasureOrderer(),
            ),
            'whsec_test',
        );
    }

    private function createFeatureGate(): ProjectFeatureGate
    {
        $commercialPlanRepository = $this->createMock(CommercialPlanRepository::class);
        $commercialPlanRepository->method('findActiveByCode')->willReturnCallback(static fn (string $code) => null);

        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProject')->willReturn(null);

        return new ProjectFeatureGate(new CommercialPlanResolver($commercialPlanRepository, $subscriptionRepository));
    }

    private function createSubscription(string $tier, string $status): ProjectSubscription
    {
        return (new ProjectSubscription())
            ->setTier($tier)
            ->setStatus($status)
            ->setSource(ProjectSubscription::SOURCE_MANUAL)
            ->setCurrency('EUR');
    }
}
