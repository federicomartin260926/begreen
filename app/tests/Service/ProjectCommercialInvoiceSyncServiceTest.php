<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectCommercialPayment;
use App\Service\ProjectCommercialInvoiceSyncService;
use PHPUnit\Framework\TestCase;

final class ProjectCommercialInvoiceSyncServiceTest extends TestCase
{
    public function testPaidCheckoutCanAttachLateInvoiceWithoutChangingPlanOrPaymentState(): void
    {
        $project = new Project();
        $payment = $this->paidPayment($project);
        $paidAt = $payment->getPaidAt();
        $payment->attachVerifiedInvoice('in_test_123', 'cus_test_123');
        $payment->attachVerifiedInvoice('in_test_123', 'cus_test_123');

        self::assertSame('in_test_123', $payment->getStripeInvoiceId());
        self::assertSame('cus_test_123', $payment->getStripeCustomerId());
        self::assertSame($paidAt, $payment->getPaidAt());
        self::assertSame(ProjectCommercialPayment::STATUS_PAID, $payment->getStatus());
        self::assertSame(Project::COMMERCIAL_TIER_BASIC, $project->getCommercialTier());
    }

    public function testRejectsAttachingInvoiceBeforePaymentIsConfirmed(): void
    {
        $payment = new ProjectCommercialPayment(new Project(), 'basic', 'standard', 'low', 24900, str_repeat('a', 64));
        $payment->markCheckoutCreated('cs_test_a', 'https://checkout.stripe.com/test');
        $this->expectException(\LogicException::class);
        $payment->attachVerifiedInvoice('in_test_123');
    }

    public function testRejectsReplacingAConfirmedInvoice(): void
    {
        $payment = $this->paidPayment(new Project());
        $payment->attachVerifiedInvoice('in_first');
        $this->expectException(\DomainException::class);
        $payment->attachVerifiedInvoice('in_other');
    }

    public function testRejectsMismatchBetweenTrustedCheckoutAndStoredQuote(): void
    {
        $payment = $this->paidPayment(new Project());
        $trusted = $this->validCheckoutData();
        ProjectCommercialInvoiceSyncService::assertMatchesPaidPayment($payment, $trusted);
        foreach (['status', 'payment_status', 'currency', 'amount_total', 'current_tier', 'target_tier', 'price_band', 'pricing_fingerprint', 'quoted_amount_cents', 'payment_intent_id'] as $field) {
            $bad = $trusted;
            $bad[$field] = 'other';
            try {
                ProjectCommercialInvoiceSyncService::assertMatchesPaidPayment($payment, $bad);
                self::fail('Mismatched Stripe field accepted: '.$field);
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRequiresPaidTestInvoiceWithExactAmountAndCurrency(): void
    {
        $payment = $this->paidPayment(new Project());
        $invoice = (object) ['id' => 'in_test_123', 'livemode' => false, 'status' => 'paid', 'currency' => 'eur', 'amount_paid' => 24900];
        ProjectCommercialInvoiceSyncService::assertMatchesPaidInvoice($payment, $invoice, 'in_test_123');
        foreach (['id', 'livemode', 'status', 'currency', 'amount_paid'] as $field) {
            $bad = clone $invoice;
            $bad->{$field} = 'incorrect';
            try {
                ProjectCommercialInvoiceSyncService::assertMatchesPaidInvoice($payment, $bad, 'in_test_123');
                self::fail('Mismatched invoice field accepted: '.$field);
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    private function paidPayment(Project $project): ProjectCommercialPayment
    {
        $payment = new ProjectCommercialPayment($project, 'basic', 'standard', 'low', 24900, str_repeat('a', 64));
        $payment->markCheckoutCreated('cs_test_a', 'https://checkout.stripe.com/test');
        $payment->markPaid('pi_test_a');

        return $payment;
    }

    /** @return array<string,mixed> */
    private function validCheckoutData(): array
    {
        return [
            'status' => 'complete',
            'payment_status' => 'paid',
            'currency' => 'eur',
            'amount_total' => 24900,
            'current_tier' => 'basic',
            'target_tier' => 'standard',
            'price_band' => 'low',
            'pricing_fingerprint' => str_repeat('a', 64),
            'quoted_amount_cents' => '24900',
            'payment_intent_id' => 'pi_test_a',
        ];
    }
}
