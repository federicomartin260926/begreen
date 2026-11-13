<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectCommercialPayment;
use App\Exception\PendingStripeCheckoutException;
use App\Repository\ProjectCommercialPaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;

/**
 * Project-scoped checkout. Intentionally not attached to any HTTP route until
 * the new webhook and reconciliation flow have passed their own tests.
 */
final class ProjectCommercialCheckoutService
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly StripeProjectCheckoutService $quoteService,
        private readonly ProjectCommercialPaymentRepository $paymentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function startCheckout(Project $project, string $targetTier, string $successUrl, string $cancelUrl): string
    {
        // A fresh server-side quote; neither amounts nor tiers come from an HTTP form.
        $payload = $this->quoteService->buildProjectCheckoutSessionPayload(
            $project, $targetTier, $successUrl, $cancelUrl
        );
        $meta = $payload['metadata'];

        // Reserve one payment under the project's DB lock, then release the
        // transaction BEFORE contacting Stripe (avoid holding locks over HTTP).
        $payment = $this->entityManager->getConnection()->transactional(function () use ($project, $targetTier, $meta): ProjectCommercialPayment {
            $this->entityManager->lock($project, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($project);

            $pricing = new ProjectCommercialPricing();
            if (
                $project->getCommercialTier() !== $meta['current_tier']
                || $pricing->getPricingFingerprint($project) !== $meta['pricing_fingerprint']
            ) {
                throw new \DomainException('Project pricing changed while preparing checkout. Request a new quote.');
            }

            $existing = $this->paymentRepository->findInProgressForProject($project);
            if ($existing !== null) {
                if (
                    $existing->getFromTier() !== $meta['current_tier']
                    || $existing->getTargetTier() !== $targetTier
                    || $existing->getPriceBand() !== $meta['price_band']
                    || $existing->getAmountCents() !== (int) $meta['quoted_amount_cents']
                    || $existing->getPricingFingerprint() !== $meta['pricing_fingerprint']
                ) {
                    throw new PendingStripeCheckoutException('Another project payment must be reconciled before starting a new checkout.');
                }

                return $existing;
            }

            $payment = new ProjectCommercialPayment(
                $project,
                $meta['current_tier'],
                $targetTier,
                $meta['price_band'],
                (int) $meta['quoted_amount_cents'],
                $meta['pricing_fingerprint'],
            );
            $this->entityManager->persist($payment);
            $this->entityManager->flush();

            return $payment;
        });

        if ($payment->getStatus() === ProjectCommercialPayment::STATUS_PENDING) {
            $checkoutUrl = $payment->getStripeCheckoutUrl();
            $expiresAt = $payment->getStripeCheckoutExpiresAt();
            if (
                $checkoutUrl === null
                || ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable())
            ) {
                // Never create a second charge on an unverified session.
                throw new PendingStripeCheckoutException('The existing session needs Stripe verification before retrying.');
            }

            return $checkoutUrl;
        }

        if ($payment->getStatus() !== ProjectCommercialPayment::STATUS_CREATED) {
            throw new PendingStripeCheckoutException('The existing project payment must be reconciled.');
        }

        // Stripe's idempotency keys expire; after this window do not risk
        // creating a second session for a previously ambiguous API response.
        if ($payment->getCreatedAt() < new \DateTimeImmutable('-20 hours')) {
            throw new PendingStripeCheckoutException('This unfinished checkout needs manual reconciliation before retrying.');
        }

        $paymentId = $payment->getId();
        if ($paymentId === null) {
            throw new \LogicException('Commercial payment reservation was not persisted.');
        }

        $payload['metadata']['commercial_payment_id'] = (string) $paymentId;
        $payload['payment_intent_data']['metadata'] = $payload['metadata'];
        $session = $this->stripeClient->checkout->sessions->create($payload, [
            'idempotency_key' => 'begreen-project-checkout-v1-' . $paymentId,
        ]);

        $sessionId = is_string($session->id ?? null) ? trim($session->id) : '';
        $checkoutUrl = is_string($session->url ?? null) ? trim($session->url) : '';
        if ($sessionId === '' || $checkoutUrl === '') {
            // Keep the reservation in CREATED for an idempotent Stripe retry.
            throw new \RuntimeException('Stripe returned a checkout without a valid session ID and URL.');
        }

        $expiresAt = is_int($session->expires_at ?? null)
            ? (new \DateTimeImmutable())->setTimestamp($session->expires_at)
            : null;

        return $this->entityManager->getConnection()->transactional(function () use ($payment, $project, $sessionId, $checkoutUrl, $expiresAt): string {
            $this->entityManager->lock($project, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($payment, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($payment);

            if ($payment->getStatus() === ProjectCommercialPayment::STATUS_CREATED) {
                $payment->markCheckoutCreated($sessionId, $checkoutUrl, $expiresAt);
                $this->entityManager->flush();

                return $checkoutUrl;
            }

            if (
                $payment->getStatus() === ProjectCommercialPayment::STATUS_PENDING
                && $payment->getStripeCheckoutSessionId() === $sessionId
            ) {
                return $payment->getStripeCheckoutUrl() ?? $checkoutUrl;
            }

            // For example, a webhook could have settled this reservation first.
            throw new PendingStripeCheckoutException('Payment state changed during checkout; verify the project before retrying.');
        });
    }
}
