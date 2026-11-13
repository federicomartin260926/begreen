<?php

namespace App\Tests\Service;

use App\Service\ProjectCommercialReturnService;
use PHPUnit\Framework\TestCase;

final class ProjectCommercialReturnServiceTest extends TestCase
{
    public function testNormalizesRetrievedPaidTestSession(): void
    {
        $data = ProjectCommercialReturnService::normalizeTrustedSession($this->session(), 'cs_test_new', 42, 7);

        self::assertSame('cs_test_new', $data['id']);
        self::assertSame('paid', $data['payment_status']);
        self::assertSame('complete', $data['status']);
        self::assertSame('project_v1', $data['commercial_scope']);
        self::assertSame('42', $data['project_id']);
        self::assertSame('7', $data['commercial_payment_id']);
        self::assertSame(24900, $data['amount_total']);
        self::assertSame('pi_test_123', $data['payment_intent_id']);
        self::assertSame(['id' => 'in_test_123'], $data['invoice']);
        self::assertSame('cus_test_123', $data['customer_id']);
    }

    public function testRejectsForgedOrCrossProjectStripeSession(): void
    {
        $base = $this->session();
        foreach ([
            ['id' => 'cs_attacker'],
            ['livemode' => true],
            ['mode' => 'subscription'],
            ['client_reference_id' => '43'],
            ['metadata' => (object) array_merge((array) $base->metadata, ['commercial_scope' => 'legacy'])],
            ['metadata' => (object) array_merge((array) $base->metadata, ['project_id' => '43'])],
            ['metadata' => (object) array_merge((array) $base->metadata, ['commercial_payment_id' => '8'])],
            ['status' => 'unrecognized'],
            ['payment_status' => 'mystery'],
            ['amount_total' => null],
        ] as $changes) {
            $session = (object) array_merge((array) $base, $changes);
            try {
                ProjectCommercialReturnService::normalizeTrustedSession($session, 'cs_test_new', 42, 7);
                self::fail('Invalid Stripe response was accepted: '.implode(',', array_keys($changes)));
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReturnsExpiredStateWithoutTreatingItAsPaid(): void
    {
        $session = $this->session();
        $session->status = 'expired';
        $session->payment_status = 'unpaid';
        $data = ProjectCommercialReturnService::normalizeTrustedSession($session, 'cs_test_new', 42, 7);

        self::assertSame('expired', $data['status']);
        self::assertSame('unpaid', $data['payment_status']);
    }

    private function session(): object
    {
        return (object) [
            'id' => 'cs_test_new',
            'livemode' => false,
            'mode' => 'payment',
            'client_reference_id' => '42',
            'status' => 'complete',
            'payment_status' => 'paid',
            'currency' => 'eur',
            'amount_total' => 24900,
            'metadata' => (object) [
                'commercial_scope' => 'project_v1',
                'commercial_payment_id' => '7',
                'project_id' => '42',
                'current_tier' => 'basic',
                'target_tier' => 'standard',
                'price_band' => 'low',
                'pricing_fingerprint' => str_repeat('a', 64),
                'quoted_amount_cents' => '24900',
            ],
            'payment_intent' => (object) ['id' => 'pi_test_123'],
            'invoice' => 'in_test_123',
            'customer' => (object) ['id' => 'cus_test_123'],
        ];
    }
}
