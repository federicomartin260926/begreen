<?php

namespace App\Tests\Entity;

use App\Entity\Project;
use App\Entity\ProjectCommercialPayment;
use PHPUnit\Framework\TestCase;

final class ProjectCommercialPaymentTest extends TestCase
{
    public function testPaymentRecordsOneProjectUpgradeAndDoesNotChangeTier(): void
    {
        $project = new Project();
        $payment = new ProjectCommercialPayment($project, 'basic', 'standard', 'low', 24900, str_repeat('a', 64));
        self::assertSame('basic', $project->getCommercialTier());
        self::assertSame(24900, $payment->getAmountCents());
        self::assertSame(ProjectCommercialPayment::STATUS_CREATED, $payment->getStatus());
        self::assertSame('EUR', $payment->getCurrency());
        self::assertSame($project, $payment->getProject());
        self::assertSame('basic', $payment->getFromTier());
        self::assertSame('standard', $payment->getTargetTier());
    }

    public function testSessionLifecycleAndDuplicateConfirmationAreIdempotent(): void
    {
        $payment = new ProjectCommercialPayment(new Project(), 'standard', 'pro', 'high', 75000, str_repeat('b', 64));
        $payment->markCheckoutCreated('cs_test_123', 'https://example.test/checkout');
        self::assertSame(ProjectCommercialPayment::STATUS_PENDING, $payment->getStatus());
        self::assertSame('cs_test_123', $payment->getStripeCheckoutSessionId());
        $payment->markPaid('pi_123', 'in_123');
        $paidAt = $payment->getPaidAt();
        self::assertSame(ProjectCommercialPayment::STATUS_PAID, $payment->getStatus());
        $payment->markPaid('pi_ignored');
        $payment->markInterrupted(ProjectCommercialPayment::STATUS_FAILED);
        self::assertSame(ProjectCommercialPayment::STATUS_PAID, $payment->getStatus());
        self::assertSame($paidAt, $payment->getPaidAt());
        self::assertSame('pi_123', $payment->getStripePaymentIntentId());
    }

    public function testExpiredSessionCannotBeActivated(): void
    {
        $payment = new ProjectCommercialPayment(new Project(), 'basic', 'pro', 'mid', 99900, str_repeat('c', 64));
        $payment->markCheckoutCreated('cs_test_expired', 'https://example.test/checkout');
        $payment->markInterrupted(ProjectCommercialPayment::STATUS_EXPIRED);
        $this->expectException(\LogicException::class);
        $payment->markPaid();
    }

    public function testInvalidUpgradeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProjectCommercialPayment(new Project(), 'pro', 'basic', 'low', 24900, str_repeat('a', 64));
    }

    public function testNonPositiveAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProjectCommercialPayment(new Project(), 'basic', 'standard', 'low', 0, str_repeat('a', 64));
    }
}
