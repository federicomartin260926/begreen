<?php

namespace App\Controller\Webhook;

use App\Service\ProjectCommercialWebhookService;
use App\Service\StripeProjectWebhookService;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly StripeProjectWebhookService $webhookService,
        private readonly ProjectCommercialWebhookService $projectWebhookService,
    ) {
    }

    #[Route('/webhooks/stripe', name: 'webhook_stripe', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = (string) $request->headers->get('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $signature, $this->webhookService->getWebhookSecret());
        } catch (\Throwable) {
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->handleEvent($event);
        } catch (\Throwable) {
            return new Response('Webhook processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('OK', Response::HTTP_OK);
    }

    private function handleEvent(Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
                $eventType = (string) $event->type;
                $needsPaymentDetails = in_array($eventType, [
                    'checkout.session.completed',
                    'checkout.session.async_payment_succeeded',
                ], true);

                /** @var StripeCheckoutSession $session */
                $session = $this->stripeClient->checkout->sessions->retrieve(
                    (string) $event->data->object->id,
                    $needsPaymentDetails ? ['expand' => ['payment_intent', 'invoice']] : []
                );
                $this->handleCheckoutSessionEvent($eventType, $this->normalizeCheckoutSession($session));
                break;

            case 'payment_intent.payment_failed':
                /** @var PaymentIntent $paymentIntent */
                $paymentIntent = $event->data->object;
                $paymentData = $this->normalizePaymentIntent($paymentIntent);
                // For project_v1 wait for checkout.session.async_payment_failed, which
                // carries the verified Checkout Session ID and project payment ID.
                if (($paymentData['commercial_scope'] ?? null) === 'project_v1') {
                    break;
                }
                if (($paymentData['commercial_scope'] ?? null) !== null) {
                    throw new \DomainException('Unrecognized Stripe commercial payment scope.');
                }
                if ($this->hasLegacyCommercialPhase($paymentData)) {
                    $this->webhookService->processPaymentFailed($paymentData);
                }
                break;

            default:
                // Other events do not change project commercial rights.
                break;
        }
    }

    /** @param array<string, mixed> $sessionData */
    private function handleCheckoutSessionEvent(string $eventType, array $sessionData): void
    {
        $scope = $sessionData['commercial_scope'] ?? null;
        if ($scope === 'project_v1') {
            switch ($eventType) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $this->projectWebhookService->processCompletedCheckoutSession($sessionData);
                    break;
                case 'checkout.session.expired':
                    $this->projectWebhookService->processCheckoutSessionExpired($sessionData);
                    break;
                case 'checkout.session.async_payment_failed':
                    $this->projectWebhookService->processAsyncPaymentFailed($sessionData);
                    break;
            }

            return;
        }

        // Never send an unknown new payment scope through the legacy
        // phase-based implementation (even when phase metadata is present).
        if ($scope !== null) {
            throw new \DomainException('Unrecognized Stripe commercial checkout scope.');
        }
        if (!$this->hasLegacyCommercialPhase($sessionData)) {
            return; // Unrelated Stripe event.
        }

        switch ($eventType) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                // Even historical phase checkouts must never activate on an
                // unpaid checkout.session.completed event.
                if (($sessionData['payment_status'] ?? null) === 'paid') {
                    $this->webhookService->processCompletedCheckoutSession($sessionData);
                }
                break;
            case 'checkout.session.expired':
                $this->webhookService->processCheckoutSessionExpired($sessionData);
                break;
            case 'checkout.session.async_payment_failed':
                if (($sessionData['payment_status'] ?? null) !== 'paid') {
                    $this->webhookService->processPaymentFailed($sessionData);
                }
                break;
        }
    }

    /** @param array<string, mixed> $data */
    private function hasLegacyCommercialPhase(array $data): bool
    {
        return in_array($data['commercial_phase'] ?? null, ['elaboration', 'implementation'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCheckoutSession(StripeCheckoutSession $session): array
    {
        $invoice = null;
        if ($session->invoice instanceof Invoice) {
            $invoice = $this->normalizeInvoice($session->invoice);
        } elseif (is_string($session->invoice) && $session->invoice !== '') {
            try {
                $retrievedInvoice = $this->stripeClient->invoices->retrieve($session->invoice);
                if ($retrievedInvoice instanceof Invoice) {
                    $invoice = $this->normalizeInvoice($retrievedInvoice);
                }
            } catch (\Throwable) {
                $invoice = null;
            }
        }

        return array_filter([
            'id' => $session->id,
            'project_id' => $session->metadata->project_id ?? null,
            'commercial_scope' => $session->metadata->commercial_scope ?? null,
            'commercial_payment_id' => $session->metadata->commercial_payment_id ?? null,
            'price_band' => $session->metadata->price_band ?? null,
            'quoted_amount_cents' => $session->metadata->quoted_amount_cents ?? null,
            'pricing_fingerprint' => $session->metadata->pricing_fingerprint ?? null,
            'commercial_phase' => $session->metadata->commercial_phase ?? null,
            'current_tier' => $session->metadata->current_tier ?? null,
            'target_tier' => $session->metadata->target_tier ?? null,
            'commercial_plan_code' => $session->metadata->commercial_plan_code ?? null,
            'upgrade_from_tier' => $session->metadata->upgrade_from_tier ?? null,
            'upgrade_type' => $session->metadata->upgrade_type ?? null,
            'user_id' => $session->metadata->user_id ?? null,
            'payment_intent_id' => is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent?->id ?? null),
            'customer_id' => is_string($session->customer) ? $session->customer : ($session->customer?->id ?? null),
            'currency' => $session->currency ?? null,
            'amount_total' => $session->amount_total ?? null,
            'payment_status' => $session->payment_status ?? null,
            'status' => $session->status ?? null,
            'invoice' => $invoice,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePaymentIntent(PaymentIntent $paymentIntent): array
    {
        return array_filter([
            'id' => $paymentIntent->id,
            'project_id' => $paymentIntent->metadata->project_id ?? null,
            'commercial_scope' => $paymentIntent->metadata->commercial_scope ?? null,
            'commercial_phase' => $paymentIntent->metadata->commercial_phase ?? null,
            'current_tier' => $paymentIntent->metadata->current_tier ?? null,
            'target_tier' => $paymentIntent->metadata->target_tier ?? null,
            'commercial_plan_code' => $paymentIntent->metadata->commercial_plan_code ?? null,
            'upgrade_from_tier' => $paymentIntent->metadata->upgrade_from_tier ?? null,
            'upgrade_type' => $paymentIntent->metadata->upgrade_type ?? null,
            'user_id' => $paymentIntent->metadata->user_id ?? null,
            'currency' => $paymentIntent->currency ?? null,
            'amount_total' => $paymentIntent->amount_received ?? null,
            'payment_status' => $paymentIntent->status ?? null,
            'payment_intent_id' => $paymentIntent->id,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeInvoice(Invoice $invoice): array
    {
        return array_filter([
            'id' => $invoice->id,
            'number' => $invoice->number ?? null,
            'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null,
            'invoice_pdf' => $invoice->invoice_pdf ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
