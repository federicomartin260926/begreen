<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectCommercialPayment;
use App\Repository\ProjectCommercialPaymentRepository;
use Stripe\StripeClient;

/**
 * Reconcile a project Checkout using a session freshly retrieved from Stripe.
 * The browser's session ID is only a lookup hint; it never authorizes activation.
 * This service does not depend on webhooks or on a phase-scoped subscription.
 */
final class ProjectCommercialReturnService
{
    public const RESULT_PAID = 'paid';
    public const RESULT_PAID_REVIEW = 'paid_review';
    public const RESULT_PENDING = 'pending';
    public const RESULT_EXPIRED = 'expired';
    public const RESULT_FAILED = 'failed';
    public const RESULT_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly ProjectCommercialPaymentRepository $paymentRepository,
        private readonly ProjectCommercialWebhookService $paymentService,
    ) {
    }

    /**
     * @param string|null $returnedSessionId A value supplied by Stripe on browser return,
     *                                       or null for the explicit "Verify payment" action.
     * @throws \DomainException if a returned session is unknown or belongs to another project
     * @throws \Throwable if Stripe is unavailable; the caller must not report success
     */
    public function reconcile(Project $project, ?string $returnedSessionId = null): string
    {
        $projectId = $project->getId();
        if ($projectId === null || $projectId <= 0) {
            throw new \DomainException('The project must be persisted before payment verification.');
        }

        $returnedSessionId = trim($returnedSessionId ?? '');
        $payment = $returnedSessionId !== ''
            ? $this->paymentRepository->findOneBySessionId($returnedSessionId)
            : $this->paymentRepository->findInProgressForProject($project);

        if (!$payment instanceof ProjectCommercialPayment || $payment->getProject()->getId() !== $projectId) {
            throw new \DomainException('The Stripe session is not associated with this project.');
        }

        if ($payment->getStatus() === ProjectCommercialPayment::STATUS_PAID) {
            return self::RESULT_PAID;
        }
        if ($payment->getStatus() === ProjectCommercialPayment::STATUS_PAID_REVIEW) {
            return self::RESULT_PAID_REVIEW;
        }
        if ($payment->getStatus() === ProjectCommercialPayment::STATUS_EXPIRED) {
            return self::RESULT_EXPIRED;
        }
        if ($payment->getStatus() === ProjectCommercialPayment::STATUS_FAILED) {
            return self::RESULT_FAILED;
        }
        if ($payment->getStatus() !== ProjectCommercialPayment::STATUS_PENDING) {
            // A CREATED reservation has no confirmed Stripe session to inspect.
            return self::RESULT_UNAVAILABLE;
        }

        $storedSessionId = $payment->getStripeCheckoutSessionId();
        $paymentId = $payment->getId();
        if ($storedSessionId === null || $storedSessionId === '' || $paymentId === null) {
            throw new \DomainException('The pending checkout has no persisted Stripe identity.');
        }
        if ($returnedSessionId !== '' && !hash_equals($storedSessionId, $returnedSessionId)) {
            throw new \DomainException('The returned Stripe session does not match the pending checkout.');
        }

        // Always consult Stripe server-to-server. Never trust browser parameters
        // or claim success solely because the user arrived at a success route.
        $session = $this->stripeClient->checkout->sessions->retrieve($storedSessionId, [
            'expand' => ['payment_intent', 'invoice'],
        ]);
        $data = self::normalizeTrustedSession($session, $storedSessionId, $projectId, $paymentId);

        if ($data['status'] === 'expired') {
            $this->paymentService->processCheckoutSessionExpired($data);
            return self::RESULT_EXPIRED;
        }
        if ($data['status'] !== 'complete' || $data['payment_status'] !== 'paid') {
            return self::RESULT_PENDING;
        }

        // Reuse the existing transactional activation guard: it checks amount,
        // currency, tiers, price band and quote fingerprint under DB locks.
        $this->paymentService->processCompletedCheckoutSession($data);

        return match ($payment->getStatus()) {
            ProjectCommercialPayment::STATUS_PAID => self::RESULT_PAID,
            ProjectCommercialPayment::STATUS_PAID_REVIEW => self::RESULT_PAID_REVIEW,
            default => self::RESULT_PENDING,
        };
    }

    /**
     * Pure Stripe response validation and normalization, independently testable.
     * This is NOT a browser payload; callers must pass a response retrieved by the
     * server-side Stripe SDK with an authenticated TEST key.
     *
     * @return array<string, mixed>
     */
    public static function normalizeTrustedSession(
        object $session,
        string $expectedSessionId,
        int $expectedProjectId,
        int $expectedPaymentId,
    ): array {
        $read = static function (mixed $source, string $field): mixed {
            if (is_array($source)) {
                return $source[$field] ?? null;
            }
            return is_object($source) ? ($source->{$field} ?? null) : null;
        };
        $metadata = $read($session, 'metadata');
        $sessionId = $read($session, 'id');
        $projectId = $read($metadata, 'project_id');
        $paymentId = $read($metadata, 'commercial_payment_id');

        if (
            !is_string($sessionId) || !hash_equals($expectedSessionId, $sessionId)
            || $read($session, 'livemode') !== false
            || $read($session, 'mode') !== 'payment'
            || $read($session, 'client_reference_id') !== (string) $expectedProjectId
            || $read($metadata, 'commercial_scope') !== 'project_v1'
            || !is_string($projectId) || $projectId !== (string) $expectedProjectId
            || !is_string($paymentId) || $paymentId !== (string) $expectedPaymentId
        ) {
            throw new \DomainException('Stripe session identity or TEST environment does not match the checkout.');
        }

        $status = $read($session, 'status');
        $paymentStatus = $read($session, 'payment_status');
        if (!in_array($status, ['open', 'complete', 'expired'], true)
            || !in_array($paymentStatus, ['paid', 'unpaid', 'no_payment_required'], true)) {
            throw new \DomainException('Unexpected Stripe checkout status.');
        }
        $currency = $read($session, 'currency');
        $amount = $read($session, 'amount_total');
        if (!is_string($currency) || !is_int($amount)) {
            throw new \DomainException('Stripe checkout has no valid amount or currency.');
        }

        $paymentIntent = $read($session, 'payment_intent');
        $invoice = $read($session, 'invoice');
        $customer = $read($session, 'customer');
        $idOf = static fn (mixed $value): ?string => is_string($value)
            ? $value
            : (is_object($value) && is_string($value->id ?? null) ? $value->id : null);
        $invoiceId = $idOf($invoice);

        return [
            'id' => $sessionId,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'currency' => $currency,
            'amount_total' => $amount,
            'commercial_scope' => 'project_v1',
            'commercial_payment_id' => $paymentId,
            'project_id' => $projectId,
            'current_tier' => $read($metadata, 'current_tier'),
            'target_tier' => $read($metadata, 'target_tier'),
            'price_band' => $read($metadata, 'price_band'),
            'pricing_fingerprint' => $read($metadata, 'pricing_fingerprint'),
            'quoted_amount_cents' => $read($metadata, 'quoted_amount_cents'),
            'payment_intent_id' => $idOf($paymentIntent),
            'customer_id' => $idOf($customer),
            'invoice' => $invoiceId !== null ? ['id' => $invoiceId] : [],
        ];
    }
}
