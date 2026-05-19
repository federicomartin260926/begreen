<?php

namespace App\Controller\Webhook;

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
                /** @var StripeCheckoutSession $session */
                $session = $this->stripeClient->checkout->sessions->retrieve(
                    (string) $event->data->object->id,
                    ['expand' => ['payment_intent', 'invoice']]
                );

                $sessionData = $this->normalizeCheckoutSession($session);
                $this->webhookService->processCompletedCheckoutSession($sessionData);
                break;

            case 'checkout.session.expired':
                /** @var StripeCheckoutSession $session */
                $session = $this->stripeClient->checkout->sessions->retrieve((string) $event->data->object->id);
                $this->webhookService->processCheckoutSessionExpired($this->normalizeCheckoutSession($session));
                break;

            case 'payment_intent.payment_failed':
                /** @var PaymentIntent $paymentIntent */
                $paymentIntent = $event->data->object;
                $this->webhookService->processPaymentFailed($this->normalizePaymentIntent($paymentIntent));
                break;

            default:
                // Eventos no relevantes para este MVP.
                break;
        }
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
            'current_tier' => $session->metadata->current_tier ?? null,
            'target_tier' => $session->metadata->target_tier ?? null,
            'user_id' => $session->metadata->user_id ?? null,
            'payment_intent_id' => is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent?->id ?? null),
            'customer_id' => is_string($session->customer) ? $session->customer : ($session->customer?->id ?? null),
            'currency' => $session->currency ?? null,
            'amount_total' => $session->amount_total ?? null,
            'payment_status' => $session->payment_status ?? null,
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
            'current_tier' => $paymentIntent->metadata->current_tier ?? null,
            'target_tier' => $paymentIntent->metadata->target_tier ?? null,
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
