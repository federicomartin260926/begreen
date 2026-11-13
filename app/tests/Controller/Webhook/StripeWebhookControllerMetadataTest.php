<?php

namespace App\Tests\Controller\Webhook;

use App\Controller\Webhook\StripeWebhookController;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;

final class StripeWebhookControllerMetadataTest extends TestCase
{
    public function testProjectCheckoutSessionMetadataSurvivesNormalization(): void
    {
        $fingerprint = str_repeat('a', 64);
        $session = Session::constructFrom([
            'id' => 'cs_test_project_80',
            'metadata' => [
                'commercial_scope' => 'project_v1',
                'commercial_payment_id' => '42',
                'project_id' => '123',
                'current_tier' => 'basic',
                'target_tier' => 'standard',
                'price_band' => 'mid',
                'quoted_amount_cents' => '49900',
                'pricing_fingerprint' => $fingerprint,
            ],
            'currency' => 'eur',
            'amount_total' => 49900,
            'payment_status' => 'paid',
            'status' => 'complete',
            'payment_intent' => 'pi_test_80',
            'customer' => 'cus_test_80',
            'invoice' => null,
        ]);

        $normalized = $this->normalize('normalizeCheckoutSession', $session);
        self::assertSame('project_v1', $normalized['commercial_scope']);
        self::assertSame('42', $normalized['commercial_payment_id']);
        self::assertSame('123', $normalized['project_id']);
        self::assertSame('basic', $normalized['current_tier']);
        self::assertSame('standard', $normalized['target_tier']);
        self::assertSame('mid', $normalized['price_band']);
        self::assertSame('49900', $normalized['quoted_amount_cents']);
        self::assertSame($fingerprint, $normalized['pricing_fingerprint']);
        self::assertSame('complete', $normalized['status']);
        self::assertSame(49900, $normalized['amount_total']);
    }

    public function testHistoricalPhaseCheckoutKeepsItsPhaseMetadata(): void
    {
        $session = Session::constructFrom([
            'id' => 'cs_test_legacy_80',
            'metadata' => [
                'project_id' => '321',
                'commercial_phase' => 'elaboration',
                'target_tier' => 'pro',
            ],
            'payment_status' => 'paid',
            'payment_intent' => null,
            'customer' => null,
            'invoice' => null,
        ]);
        $normalized = $this->normalize('normalizeCheckoutSession', $session);
        self::assertArrayNotHasKey('commercial_scope', $normalized);
        self::assertSame('elaboration', $normalized['commercial_phase']);
        self::assertSame('pro', $normalized['target_tier']);
    }

    public function testProjectPaymentIntentKeepsScopeToAvoidLegacyFailureHandling(): void
    {
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_test_project_80',
            'metadata' => [
                'commercial_scope' => 'project_v1',
                'project_id' => '123',
            ],
            'status' => 'requires_payment_method',
        ]);
        $normalized = $this->normalize('normalizePaymentIntent', $paymentIntent);
        self::assertSame('project_v1', $normalized['commercial_scope']);
        self::assertSame('123', $normalized['project_id']);
    }

    public function testUnknownCommercialScopeCannotReachLegacyCheckout(): void
    {
        $controller = (new \ReflectionClass(StripeWebhookController::class))->newInstanceWithoutConstructor();
        $this->expectException(\DomainException::class);
        (new \ReflectionMethod(StripeWebhookController::class, 'handleCheckoutSessionEvent'))->invoke(
            $controller,
            'checkout.session.completed',
            [
                'commercial_scope' => 'unexpected',
                'commercial_phase' => 'elaboration',
                'payment_status' => 'paid',
            ]
        );
    }

    private function normalize(string $method, Session|PaymentIntent $object): array
    {
        $controller = (new \ReflectionClass(StripeWebhookController::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod(StripeWebhookController::class, $method))->invoke($controller, $object);
    }
}
