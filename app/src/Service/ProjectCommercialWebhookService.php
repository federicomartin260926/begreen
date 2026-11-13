<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectCommercialPayment;
use App\Repository\ProjectCommercialPaymentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handle only Stripe Checkout Sessions with signed, retrieved project_v1 metadata.
 * Deliberately separate from the phase-scoped legacy subscription webhook.
 * This service does not verify signatures; the existing webhook controller does.
 */
final class ProjectCommercialWebhookService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectCommercialPaymentRepository $paymentRepository,
        private readonly ProjectCommercialPricing $pricing,
    ) {
    }

    /** @param array<string, mixed> $sessionData */
    public function processCompletedCheckoutSession(array $sessionData): void
    {
        // Checkout completion does not imply paid for delayed payment methods.
        if (($sessionData['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $this->entityManager->getConnection()->transactional(function () use ($sessionData): void {
            $payment = $this->findAndLockPayment($sessionData);
            if (in_array($payment->getStatus(), [ProjectCommercialPayment::STATUS_PAID, ProjectCommercialPayment::STATUS_PAID_REVIEW], true)) {
                return;
            }

            $sessionId = (string) $sessionData['id'];
            if ($payment->getStatus() === ProjectCommercialPayment::STATUS_CREATED) {
                $payment->bindSessionFromVerifiedWebhook($sessionId);
            }

            $project = $payment->getProject();
            $reason = self::activationBlockReason($payment, $project, $sessionData, $this->pricing);
            if ($reason === null && $payment->getStatus() !== ProjectCommercialPayment::STATUS_PENDING) {
                $reason = 'payment_arrived_after_interruption';
            }

            $invoice = is_array($sessionData['invoice'] ?? null) ? $sessionData['invoice'] : [];
            $paymentIntent = $this->optionalString($sessionData['payment_intent_id'] ?? null);
            $invoiceId = $this->optionalString($invoice['id'] ?? null);
            $customerId = $this->optionalString($sessionData['customer_id'] ?? null);

            if ($reason !== null) {
                // Money was received, but activation would be unsafe. Require manual review;
                // the repository includes this state in the in-progress query.
                $payment->markPaidForReview($reason, $paymentIntent, $invoiceId, $customerId);
            } else {
                $payment->markPaid($paymentIntent, $invoiceId, $customerId);
                $project->setCommercialTier($payment->getTargetTier());
            }
            $this->entityManager->flush();
        });
    }

    /** @param array<string, mixed> $sessionData */
    public function processCheckoutSessionExpired(array $sessionData): void
    {
        if (($sessionData['status'] ?? null) !== 'expired') {
            throw new \DomainException('Cannot expire a checkout session that is not expired in Stripe.');
        }
        $this->markInterrupted($sessionData, ProjectCommercialPayment::STATUS_EXPIRED);
    }

    /** Only for Stripe's checkout.session.async_payment_failed event. @param array<string, mixed> $sessionData */
    public function processAsyncPaymentFailed(array $sessionData): void
    {
        if (($sessionData['payment_status'] ?? null) === 'paid') {
            throw new \DomainException('A paid Stripe session must not be marked failed.');
        }
        $this->markInterrupted($sessionData, ProjectCommercialPayment::STATUS_FAILED);
    }

    /**
     * Pure activation guard. All source values must originate from a verified Stripe
     * Checkout Session, never an HTTP parameter or a browser success redirect.
     *
     * @param array<string, mixed> $sessionData
     */
    public static function activationBlockReason(
        ProjectCommercialPayment $payment,
        Project $project,
        array $sessionData,
        ProjectCommercialPricing $pricing,
    ): ?string {
        if (($sessionData['currency'] ?? null) !== 'eur') {
            return 'currency_mismatch';
        }
        if (!is_int($sessionData['amount_total'] ?? null) || $sessionData['amount_total'] !== $payment->getAmountCents()) {
            return 'amount_mismatch';
        }
        if ($project->getCommercialTier() !== $payment->getFromTier()) {
            return 'project_tier_changed';
        }
        try {
            $fingerprint = $pricing->getPricingFingerprint($project);
            $band = $pricing->getPriceBand($project);
            $expected = $payment->getFromTier() === Project::COMMERCIAL_TIER_STANDARD
                ? $pricing->getUpgradeAmountCents($project, $payment->getFromTier(), $payment->getTargetTier())
                : $pricing->getAmountCents($project, $payment->getTargetTier());
            if ($fingerprint !== $payment->getPricingFingerprint() || $band !== $payment->getPriceBand() || $expected !== $payment->getAmountCents()) {
                return 'project_pricing_changed';
            }
        } catch (\DomainException | \InvalidArgumentException) {
            return 'project_pricing_changed';
        }

        return null;
    }

    /** @param array<string, mixed> $sessionData */
    private function markInterrupted(array $sessionData, string $targetStatus): void
    {
        $this->entityManager->getConnection()->transactional(function () use ($sessionData, $targetStatus): void {
            $payment = $this->findAndLockPayment($sessionData);
            if (in_array($payment->getStatus(), [ProjectCommercialPayment::STATUS_PAID, ProjectCommercialPayment::STATUS_PAID_REVIEW], true)) {
                return;
            }
            if ($payment->getStatus() === ProjectCommercialPayment::STATUS_CREATED) {
                $payment->bindSessionFromVerifiedWebhook((string) $sessionData['id']);
            }
            $payment->markInterrupted($targetStatus);
            $this->entityManager->flush();
        });
    }

    /** @param array<string, mixed> $data */
    private function findAndLockPayment(array $data): ProjectCommercialPayment
    {
        $scope = $data['commercial_scope'] ?? null;
        $idRaw = $data['commercial_payment_id'] ?? null;
        $projectIdRaw = $data['project_id'] ?? null;
        $sessionId = $data['id'] ?? null;
        if (
            $scope !== 'project_v1'
            || (!is_string($idRaw) && !is_int($idRaw)) || !ctype_digit((string) $idRaw) || (int) $idRaw < 1
            || (!is_string($projectIdRaw) && !is_int($projectIdRaw)) || !ctype_digit((string) $projectIdRaw) || (int) $projectIdRaw < 1
            || !is_string($sessionId) || trim($sessionId) === ''
        ) {
            throw new \DomainException('Invalid Stripe project checkout identity.');
        }
        $payment = $this->paymentRepository->find((int) $idRaw);
        if (!$payment instanceof ProjectCommercialPayment) {
            throw new \DomainException('Unknown Stripe project payment.');
        }
        $project = $payment->getProject();
        $this->entityManager->lock($project, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($project);
        $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($payment);

        if (
            $project->getId() !== (int) $projectIdRaw
            || ($payment->getStripeCheckoutSessionId() !== null && $payment->getStripeCheckoutSessionId() !== $sessionId)
            || ($data['current_tier'] ?? null) !== $payment->getFromTier()
            || ($data['target_tier'] ?? null) !== $payment->getTargetTier()
            || ($data['price_band'] ?? null) !== $payment->getPriceBand()
            || ($data['pricing_fingerprint'] ?? null) !== $payment->getPricingFingerprint()
            || (string) ($data['quoted_amount_cents'] ?? '') !== (string) $payment->getAmountCents()
        ) {
            throw new \DomainException('Stripe checkout metadata does not match the project payment reservation.');
        }
        return $payment;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
