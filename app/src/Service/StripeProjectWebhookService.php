<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\ProjectRepository;
use App\Repository\ProjectSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class StripeProjectWebhookService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectSubscriptionRepository $subscriptionRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly string $webhookSecret,
    ) {
    }

    public function processCompletedCheckoutSession(array $sessionData): void
    {
        $subscription = $this->findSubscription($sessionData);
        if (!$subscription) {
            return;
        }

        $sessionId = (string) ($sessionData['id'] ?? $sessionData['session_id'] ?? '');
        $targetTier = $this->resolveTargetTier($sessionData, $subscription);
        $invoice = is_array($sessionData['invoice'] ?? null) ? $sessionData['invoice'] : [];
        $paidAmountCents = isset($sessionData['amount_total']) ? (int) $sessionData['amount_total'] : null;
        $currency = strtoupper((string) ($sessionData['currency'] ?? $subscription->getCurrency() ?? 'EUR'));

        if (
            $subscription->getStatus() === ProjectSubscription::STATUS_ACTIVE
            && $subscription->getStripeCheckoutSessionId() === $sessionId
            && $subscription->getTier() === $targetTier
        ) {
            $this->fillMissingStripeReferences($subscription, $sessionData, $invoice);
            $this->entityManager->flush();

            return;
        }

        $subscription
            ->setTier($targetTier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents($paidAmountCents)
            ->setCurrency($currency !== '' ? $currency : 'EUR')
            ->setPaymentReference($invoice['number'] ?? $invoice['id'] ?? $sessionId)
            ->setStripeCheckoutSessionId($sessionId !== '' ? $sessionId : $subscription->getStripeCheckoutSessionId())
            ->setStripePaymentIntentId($this->normalizeString($sessionData['payment_intent_id'] ?? $sessionData['payment_intent'] ?? null))
            ->setStripeInvoiceId($this->normalizeString($invoice['id'] ?? null))
            ->setStripeCustomerId($this->normalizeString($sessionData['customer_id'] ?? $sessionData['customer'] ?? null))
            ->setStripeHostedInvoiceUrl($this->normalizeString($invoice['hosted_invoice_url'] ?? null))
            ->setStripeInvoicePdfUrl($this->normalizeString($invoice['invoice_pdf'] ?? null))
            ->setLastPaymentStatus('paid')
            ->setPaidAt(new \DateTimeImmutable())
            ->setTargetTier(null);

        $this->entityManager->flush();
    }

    public function processCheckoutSessionExpired(array $sessionData): void
    {
        $this->markInterruptedPayment($sessionData, 'expired');
    }

    public function processPaymentFailed(array $sessionData): void
    {
        $this->markInterruptedPayment($sessionData, 'failed');
    }

    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }

    private function markInterruptedPayment(array $sessionData, string $status): void
    {
        $subscription = $this->findSubscription($sessionData);
        if (!$subscription) {
            return;
        }

        $sessionId = (string) ($sessionData['id'] ?? $sessionData['session_id'] ?? '');
        if ($sessionId !== '' && $subscription->getStripeCheckoutSessionId() === $sessionId && $subscription->getStatus() === ProjectSubscription::STATUS_ACTIVE) {
            return;
        }

        $subscription
            ->setStatus(ProjectSubscription::STATUS_CANCELLED)
            ->setLastPaymentStatus($status);

        if ($sessionId !== '') {
            $subscription->setStripeCheckoutSessionId($sessionId);
        }

        $this->entityManager->flush();
    }

    private function fillMissingStripeReferences(ProjectSubscription $subscription, array $sessionData, array $invoice): void
    {
        if ($subscription->getStripePaymentIntentId() === null) {
            $subscription->setStripePaymentIntentId($this->normalizeString($sessionData['payment_intent_id'] ?? $sessionData['payment_intent'] ?? null));
        }
        if ($subscription->getStripeInvoiceId() === null) {
            $subscription->setStripeInvoiceId($this->normalizeString($invoice['id'] ?? null));
        }
        if ($subscription->getStripeCustomerId() === null) {
            $subscription->setStripeCustomerId($this->normalizeString($sessionData['customer_id'] ?? $sessionData['customer'] ?? null));
        }
        if ($subscription->getStripeHostedInvoiceUrl() === null) {
            $subscription->setStripeHostedInvoiceUrl($this->normalizeString($invoice['hosted_invoice_url'] ?? null));
        }
        if ($subscription->getStripeInvoicePdfUrl() === null) {
            $subscription->setStripeInvoicePdfUrl($this->normalizeString($invoice['invoice_pdf'] ?? null));
        }
        if ($subscription->getPaymentReference() === null) {
            $subscription->setPaymentReference($invoice['number'] ?? $invoice['id'] ?? (string) ($sessionData['id'] ?? ''));
        }
        if ($subscription->getLastPaymentStatus() === null) {
            $subscription->setLastPaymentStatus('paid');
        }
        if ($subscription->getPaidAt() === null) {
            $subscription->setPaidAt(new \DateTimeImmutable());
        }
        if ($subscription->getPaidAmountCents() === null && isset($sessionData['amount_total'])) {
            $subscription->setPaidAmountCents((int) $sessionData['amount_total']);
        }
        if ($subscription->getTargetTier() !== null) {
            $subscription->setTargetTier(null);
        }
    }

    private function findSubscription(array $sessionData): ?ProjectSubscription
    {
        $sessionId = (string) ($sessionData['id'] ?? $sessionData['session_id'] ?? '');
        if ($sessionId !== '') {
            $subscription = $this->subscriptionRepository->findOneByStripeCheckoutSessionId($sessionId);
            if ($subscription) {
                return $subscription;
            }
        }

        $projectId = (int) ($sessionData['project_id'] ?? 0);
        if ($projectId <= 0) {
            return null;
        }

        $project = $this->projectRepository->find($projectId);
        if (!$project instanceof Project) {
            return null;
        }

        return $project->getSubscription();
    }

    private function resolveTargetTier(array $sessionData, ProjectSubscription $subscription): string
    {
        $targetTier = (string) ($sessionData['target_tier'] ?? $subscription->getTargetTier() ?? $subscription->getTier());
        return in_array($targetTier, [ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO], true)
            ? $targetTier
            : $subscription->getTier();
    }

    private function normalizeString(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
