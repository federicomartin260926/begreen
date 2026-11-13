<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectCommercialPayment;
use App\Service\ProjectCommercialPricing;
use App\Service\ProjectCommercialWebhookService;
use PHPUnit\Framework\TestCase;

final class ProjectCommercialWebhookPolicyTest extends TestCase
{
    private function createEventProject(): array
    {
        $project = (new Project())->setType('evento')->setEventModality('hibrido')->setEventAttendeesCount(101);
        $pricing = new ProjectCommercialPricing();
        $payment = new ProjectCommercialPayment($project, 'basic', 'pro', 'mid', 99900, $pricing->getPricingFingerprint($project));

        return [$project, $payment, $pricing];
    }

    public function testExactPaidQuoteCanActivate(): void
    {
        [$project, $payment, $pricing] = $this->createEventProject();
        self::assertNull(ProjectCommercialWebhookService::activationBlockReason(
            $payment, $project, ['currency' => 'eur', 'amount_total' => 99900], $pricing
        ));
    }

    public function testDifferentStripeAmountRequiresReview(): void
    {
        [$project, $payment, $pricing] = $this->createEventProject();
        self::assertSame('amount_mismatch', ProjectCommercialWebhookService::activationBlockReason(
            $payment, $project, ['currency' => 'eur', 'amount_total' => 24900], $pricing
        ));
    }

    public function testIncorrectCurrencyRequiresReview(): void
    {
        [$project, $payment, $pricing] = $this->createEventProject();
        self::assertSame('currency_mismatch', ProjectCommercialWebhookService::activationBlockReason(
            $payment, $project, ['currency' => 'usd', 'amount_total' => 99900], $pricing
        ));
    }

    public function testPricingChangeRequiresReview(): void
    {
        [$project, $payment, $pricing] = $this->createEventProject();
        $project->setEventAttendeesCount(1001);
        self::assertSame('project_pricing_changed', ProjectCommercialWebhookService::activationBlockReason(
            $payment, $project, ['currency' => 'eur', 'amount_total' => 99900], $pricing
        ));
    }

    public function testProjectTierChangeRequiresReview(): void
    {
        [$project, $payment, $pricing] = $this->createEventProject();
        $project->setCommercialTier('standard');
        self::assertSame('project_tier_changed', ProjectCommercialWebhookService::activationBlockReason(
            $payment, $project, ['currency' => 'eur', 'amount_total' => 99900], $pricing
        ));
    }

    public function testEarlyVerifiedWebhookBindsSessionBeforeCheckoutResponse(): void
    {
        [$project, $payment] = $this->createEventProject();
        $payment->bindSessionFromVerifiedWebhook('cs_test_early');
        self::assertSame(ProjectCommercialPayment::STATUS_PENDING, $payment->getStatus());
        self::assertSame('cs_test_early', $payment->getStripeCheckoutSessionId());
        $this->expectException(\LogicException::class);
        $payment->bindSessionFromVerifiedWebhook('cs_test_other');
    }

    public function testPaidUnderReviewDoesNotEnableTierOrAllowDelayedFailure(): void
    {
        [$project, $payment] = $this->createEventProject();
        $payment->bindSessionFromVerifiedWebhook('cs_test_review');
        $payment->markPaidForReview('project_pricing_changed', 'pi_review');
        $payment->markInterrupted(ProjectCommercialPayment::STATUS_FAILED);
        self::assertSame(ProjectCommercialPayment::STATUS_PAID_REVIEW, $payment->getStatus());
        self::assertSame('project_pricing_changed', $payment->getReviewReason());
        self::assertSame('basic', $project->getCommercialTier());
    }
}
