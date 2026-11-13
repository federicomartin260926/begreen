<?php

namespace App\Service;

use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectCommercialPayment;
use App\Repository\ProjectBillingDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only Stripe reconciliation of an already paid project Checkout, followed
 * by idempotent invoice/document persistence. Never creates a charge or a plan.
 */
final class ProjectCommercialInvoiceSyncService
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly ProjectBillingDocumentRepository $documentRepository,
        private readonly StripeInvoiceStorageService $invoiceStorage,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private readonly string $stripeSecretKey,
    ) {
    }

    /**
     * @return array{invoiceFound:bool,pdfAvailable:bool}
     * @throws \DomainException for mismatched Stripe identity, nonpaid data or forbidden state
     * @throws \Throwable for Stripe/network/storage/DB failures (caller must never claim success)
     */
    public function sync(ProjectCommercialPayment $payment): array
    {
        if (!str_starts_with($this->stripeSecretKey, 'sk_test_')) {
            throw new \DomainException('Invoice synchronization requires Stripe TEST.');
        }
        if ($payment->getStatus() !== ProjectCommercialPayment::STATUS_PAID) {
            throw new \DomainException('Only confirmed paid purchases can synchronize invoices.');
        }
        $project = $payment->getProject();
        $projectId = $project->getId();
        $paymentId = $payment->getId();
        $sessionId = $payment->getStripeCheckoutSessionId();
        if ($projectId === null || $paymentId === null || $sessionId === null || !str_starts_with($sessionId, 'cs_test_')) {
            throw new \DomainException('Missing persisted TEST checkout identity.');
        }

        $session = $this->stripeClient->checkout->sessions->retrieve($sessionId, [
            'expand' => ['invoice', 'payment_intent'],
        ]);
        $trusted = ProjectCommercialReturnService::normalizeTrustedSession($session, $sessionId, $projectId, $paymentId);
        self::assertMatchesPaidPayment($payment, $trusted);
        $invoiceId = $trusted['invoice']['id'] ?? null;
        if (!is_string($invoiceId) || !str_starts_with($invoiceId, 'in_')) {
            return ['invoiceFound' => false, 'pdfAvailable' => false];
        }
        $invoice = $this->stripeClient->invoices->retrieve($invoiceId);
        self::assertMatchesPaidInvoice($payment, $invoice, $invoiceId);
        $customerId = $trusted['customer_id'];
        if ($customerId !== null && $payment->getStripeCustomerId() !== null && $customerId !== $payment->getStripeCustomerId()) {
            throw new \DomainException('Stripe Checkout customer does not match the original payment.');
        }
        $invoiceCustomer = self::objectId($invoice->customer ?? null);
        if ($customerId !== null && $invoiceCustomer !== null && $invoiceCustomer !== $customerId) {
            throw new \DomainException('Stripe invoice belongs to a different customer.');
        }

        $document = $this->documentRepository->findOneBy([
            'project' => $project,
            'stripeCheckoutSessionId' => $sessionId,
            'subscription' => null,
        ]);
        if (!$document instanceof ProjectBillingDocument) {
            // An existing invoice ID must never be attached to another project.
            $sameInvoice = $this->documentRepository->findOneBy(['stripeInvoiceId' => $invoiceId]);
            if ($sameInvoice !== null) {
                throw new \DomainException('The Stripe invoice is already associated with another billing document.');
            }
            $document = new ProjectBillingDocument();
            $document->setProject($project);
            $project->addBillingDocument($document);
            $this->entityManager->persist($document);
        }
        if ($document->getStripeInvoiceId() !== null && $document->getStripeInvoiceId() !== $invoiceId) {
            throw new \DomainException('The project document already belongs to another invoice.');
        }

        $payment->attachVerifiedInvoice($invoiceId, $customerId);
        $invoiceNumber = is_string($invoice->number ?? null) ? $invoice->number : $invoiceId;
        $pdfUrl = is_string($invoice->invoice_pdf ?? null) ? $invoice->invoice_pdf : null;
        $hostedUrl = is_string($invoice->hosted_invoice_url ?? null) ? $invoice->hosted_invoice_url : null;
        $created = $invoice->created ?? null;
        $document
            ->setSubscription(null)
            ->setProvider(ProjectBillingDocument::PROVIDER_STRIPE)
            ->setType(ProjectBillingDocument::TYPE_INVOICE)
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setStripeCheckoutSessionId($sessionId)
            ->setStripePaymentIntentId($payment->getStripePaymentIntentId())
            ->setStripeInvoiceId($invoiceId)
            ->setStripeCustomerId($customerId ?? $invoiceCustomer)
            ->setPaymentReference($invoiceNumber)
            ->setPurchaseLabel(sprintf('BEGREEN %s (%s → %s)', ucfirst($payment->getTargetTier()), ucfirst($payment->getFromTier()), ucfirst($payment->getTargetTier())))
            ->setAmountCents($payment->getAmountCents())
            ->setCurrency($payment->getCurrency())
            ->setHostedInvoiceUrl($hostedUrl)
            ->setInvoicePdfUrl($pdfUrl)
            ->setIssuedAt(is_int($created) ? (new \DateTimeImmutable())->setTimestamp($created) : $document->getIssuedAt())
            ->setPaidAt($payment->getPaidAt());

        // A failed/late PDF download does not roll back a valid invoice reference.
        // It can be retried with the same button without another Stripe Checkout.
        // Refresh the PDF after Stripe confirms this invoice is paid. The storage
        // service validates the download and atomically replaces the existing file.
        $pdfAvailable = $this->invoiceStorage->syncInvoicePdf($document, true);
        $this->entityManager->flush();

        return ['invoiceFound' => true, 'pdfAvailable' => $pdfAvailable];
    }

    /** @param array<string,mixed> $trusted */
    public static function assertMatchesPaidPayment(ProjectCommercialPayment $payment, array $trusted): void
    {
        if (($trusted['status'] ?? null) !== 'complete'
            || ($trusted['payment_status'] ?? null) !== 'paid'
            || ($trusted['currency'] ?? null) !== strtolower($payment->getCurrency())
            || ($trusted['amount_total'] ?? null) !== $payment->getAmountCents()
            || ($trusted['current_tier'] ?? null) !== $payment->getFromTier()
            || ($trusted['target_tier'] ?? null) !== $payment->getTargetTier()
            || ($trusted['price_band'] ?? null) !== $payment->getPriceBand()
            || ($trusted['pricing_fingerprint'] ?? null) !== $payment->getPricingFingerprint()
            || ($trusted['quoted_amount_cents'] ?? null) !== (string) $payment->getAmountCents()
            || ($payment->getStripePaymentIntentId() !== null && ($trusted['payment_intent_id'] ?? null) !== $payment->getStripePaymentIntentId())
        ) {
            throw new \DomainException('Stripe paid checkout no longer matches the recorded project purchase.');
        }
    }

    public static function assertMatchesPaidInvoice(ProjectCommercialPayment $payment, object $invoice, string $expectedId): void
    {
        if (($invoice->id ?? null) !== $expectedId
            || ($invoice->livemode ?? null) !== false
            || ($invoice->status ?? null) !== 'paid'
            || ($invoice->currency ?? null) !== strtolower($payment->getCurrency())
            || ($invoice->amount_paid ?? null) !== $payment->getAmountCents()
        ) {
            throw new \DomainException('Stripe invoice identity or paid amount does not match this purchase.');
        }
        if ($payment->getStripeInvoiceId() !== null && $payment->getStripeInvoiceId() !== $expectedId) {
            throw new \DomainException('A different invoice is already attached to this purchase.');
        }
    }

    private static function objectId(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_object($value) && is_string($value->id ?? null) ? $value->id : null);
    }
}
