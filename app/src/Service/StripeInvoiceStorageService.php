<?php

namespace App\Service;

use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Repository\ProjectBillingDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Stripe\StripeClient;
use Throwable;

class StripeInvoiceStorageService
{
    private const MAX_BYTES = 10_485_760;
    private const STORAGE_ROOT = 'stripe-invoices';

    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly HttpClientInterface $httpClient,
        private readonly ProjectBillingDocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function hasLocalCopy(ProjectBillingDocument $document): bool
    {
        return $this->getLocalInvoiceAbsolutePath($document) !== null;
    }

    public function getLocalInvoiceAbsolutePath(ProjectBillingDocument $document): ?string
    {
        $absolutePath = $this->resolveStoredAbsolutePath($document);
        if ($absolutePath !== null) {
            return $absolutePath;
        }

        $computedPath = $this->buildAbsolutePath($this->buildRelativePath($document));
        if (is_file($computedPath)) {
            $document->setLocalPath($this->buildRelativePath($document));
            if ($document->getDownloadedAt() === null) {
                $document->setDownloadedAt(new \DateTimeImmutable());
            }

            return $computedPath;
        }

        return null;
    }

    public function syncInvoicePdf(ProjectBillingDocument $document, bool $forceRefresh = false): bool
    {
        $relativePath = $this->normalizeStoredPath($document->getLocalPath()) ?? $this->buildRelativePath($document);
        $absolutePath = $this->buildAbsolutePath($relativePath);

        if (!$forceRefresh) {
            $existingPath = $this->resolveStoredAbsolutePath($document) ?? (is_file($absolutePath) ? $absolutePath : null);
            if ($existingPath !== null) {
                $document->setLocalPath($this->relativePathFromAbsolute($existingPath));
                if ($document->getDownloadedAt() === null) {
                    $document->setDownloadedAt(new \DateTimeImmutable());
                }

                return true;
            }
        }

        $pdfUrl = $this->normalizeString($document->getInvoicePdfUrl());
        if ($pdfUrl === null) {
            $invoice = $this->refreshInvoiceDataFromStripe($document);
            if ($invoice !== null) {
                $this->applyStripeInvoiceToDocument($document, $invoice);
                $pdfUrl = $this->normalizeString($document->getInvoicePdfUrl());
                $relativePath = $this->normalizeStoredPath($document->getLocalPath()) ?? $this->buildRelativePath($document);
                $absolutePath = $this->buildAbsolutePath($relativePath);
            } else {
                $this->logStripeSyncDiagnostic($document, 'missing_invoice_after_refresh');
            }
        }

        if ($pdfUrl === null) {
            if ($document->getInvoicePdfUrl() === null) {
                $this->logStripeSyncDiagnostic($document, 'missing_invoice_pdf');
            }

            return false;
        }

        $currentUrl = $pdfUrl;
        $redirectCount = 0;

        try {
            $response = null;

            while ($redirectCount <= 5) {
                $response = $this->httpClient->request('GET', $currentUrl, [
                    'headers' => [
                        'Accept' => 'application/pdf',
                    ],
                    'timeout' => 30,
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 300 && $statusCode < 400) {
                    $headers = $response->getHeaders(false);
                    $location = trim((string) ($headers['location'][0] ?? ''));
                    if ($location === '') {
                        $this->logStripeDownloadFailure($document, 'http_error', $pdfUrl, $absolutePath, $relativePath, [
                            'status_code' => $statusCode,
                            'content_type' => (string) ($headers['content-type'][0] ?? ''),
                            'content_length' => (string) ($headers['content-length'][0] ?? ''),
                            'redirect_count' => $redirectCount,
                        ]);

                        return false;
                    }

                    $currentUrl = $location;
                    $redirectCount++;
                    continue;
                }

                break;
            }

            if (!$response instanceof \Symfony\Contracts\HttpClient\ResponseInterface) {
                $this->logStripeDownloadFailure($document, 'exception', $pdfUrl, $absolutePath, $relativePath, [
                    'message' => 'No HTTP response was returned.',
                    'redirect_count' => $redirectCount,
                ]);

                return false;
            }

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $contentTypeRaw = (string) ($headers['content-type'][0] ?? '');
            $contentType = strtolower($contentTypeRaw);
            $contentLengthHeader = (string) ($headers['content-length'][0] ?? '');

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logStripeDownloadFailure($document, 'http_error', $pdfUrl, $absolutePath, $relativePath, [
                    'status_code' => $statusCode,
                    'content_type' => $contentType,
                    'content_length' => $contentLengthHeader !== '' ? $contentLengthHeader : null,
                    'redirect_count' => $redirectCount,
                ]);
                return false;
            }

            $content = $response->getContent(false);
            $contentLength = is_string($content) ? strlen($content) : null;

            if (!is_string($content) || $content === '') {
                $this->logStripeDownloadFailure($document, 'empty_pdf', $pdfUrl, $absolutePath, $relativePath, [
                    'status_code' => $statusCode,
                    'content_type' => $contentType,
                    'content_length' => $contentLength ?? $contentLengthHeader,
                    'redirect_count' => $redirectCount,
                ]);
                return false;
            }

            if ($contentLength > self::MAX_BYTES) {
                $this->logStripeDownloadFailure($document, 'empty_pdf', $pdfUrl, $absolutePath, $relativePath, [
                    'status_code' => $statusCode,
                    'content_type' => $contentType,
                    'content_length' => $contentLength,
                    'redirect_count' => $redirectCount,
                ]);
                return false;
            }

            if (!str_starts_with($content, '%PDF-')) {
                $this->logStripeDownloadFailure($document, 'invalid_content_type', $pdfUrl, $absolutePath, $relativePath, [
                    'status_code' => $statusCode,
                    'content_type' => $contentTypeRaw,
                    'content_length' => $contentLength,
                    'redirect_count' => $redirectCount,
                ]);
                return false;
            }

            $filesystem = new Filesystem();
            try {
                $filesystem->mkdir(dirname($absolutePath));
            } catch (Throwable $throwable) {
                $this->logStripeDownloadFailure($document, 'directory_not_writable', $pdfUrl, $absolutePath, $relativePath, [
                    'status_code' => $statusCode,
                    'content_type' => $contentTypeRaw,
                    'content_length' => $contentLength,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                    'redirect_count' => $redirectCount,
                ]);
                return false;
            }

            if (!is_dir(dirname($absolutePath)) || !is_writable(dirname($absolutePath))) {
                $this->logStripeDownloadFailure($document, 'directory_not_writable', $pdfUrl, $absolutePath, $relativePath, [
                    'status_code' => $statusCode,
                    'content_type' => $contentTypeRaw,
                    'content_length' => $contentLength,
                    'redirect_count' => $redirectCount,
                ]);
                return false;
            }

            try {
                $filesystem->dumpFile($absolutePath, $content);
            } catch (Throwable $throwable) {
                $this->logStripeDownloadFailure($document, 'file_write_failed', $pdfUrl, $absolutePath, $relativePath, [
                    'status_code' => $statusCode,
                    'content_type' => $contentTypeRaw,
                    'content_length' => $contentLength,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                    'redirect_count' => $redirectCount,
                ]);
                return false;
            }

            $document
                ->setLocalPath($relativePath)
                ->setDownloadedAt(new \DateTimeImmutable());

            return true;
        } catch (Throwable $throwable) {
            $this->logStripeDownloadFailure($document, 'exception', $pdfUrl, $absolutePath, $relativePath, [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'redirect_count' => $redirectCount ?? null,
            ]);

            return false;
        }
    }

    public function upsertFromStripeCheckout(ProjectSubscription $subscription, array|object $session, array|object|null $invoice = null): ProjectBillingDocument
    {
        $project = $subscription->getProject();
        if (!$project instanceof \App\Entity\Project) {
            throw new \RuntimeException('A project subscription must belong to a project before creating billing documents.');
        }

        $normalizedSession = $this->extractObject($session);
        $normalizedInvoice = $this->extractObject($invoice);

        $sessionId = $this->normalizeString($this->readObjectValue($normalizedSession, 'id'));
        $paymentIntentId = $this->extractStripeObjectId($this->readObjectValue($normalizedSession, 'payment_intent'));
        $invoiceId = $this->extractStripeObjectId($normalizedInvoice ?? $this->readObjectValue($normalizedSession, 'invoice'));

        $document = $this->documentRepository->findOneMatchingStripeIdentifiers(
            $project,
            $invoiceId,
            $sessionId,
            $paymentIntentId
        ) ?? new ProjectBillingDocument();

        if ($document->getId() === null) {
            $document
                ->setProject($project)
                ->setProvider(ProjectBillingDocument::PROVIDER_STRIPE);
            $project->addBillingDocument($document);
            $this->entityManager->persist($document);
        }

        $document->setSubscription($subscription);
        $document->setProvider(ProjectBillingDocument::PROVIDER_STRIPE);

        $invoiceObject = $normalizedInvoice ?? $this->resolveInvoiceObject($normalizedSession);
        $amountTotal = $this->readObjectValue($normalizedSession, 'amount_total');
        $document
            ->setType($this->resolveDocumentType($invoiceObject))
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setStripeCheckoutSessionId($sessionId ?? $document->getStripeCheckoutSessionId())
            ->setStripePaymentIntentId($paymentIntentId ?? $document->getStripePaymentIntentId())
            ->setStripeInvoiceId($invoiceId ?? $document->getStripeInvoiceId())
            ->setStripeCustomerId($this->extractStripeObjectId($this->readObjectValue($normalizedSession, 'customer')) ?? $document->getStripeCustomerId())
            ->setPaymentReference(
                $this->normalizeString($this->readObjectValue($invoiceObject, 'number'))
                ?? $invoiceId
                ?? $sessionId
                ?? $document->getPaymentReference()
            )
            ->setAmountCents($amountTotal !== null ? (int) $amountTotal : $document->getAmountCents())
            ->setCurrency(
                $this->normalizeString($this->readObjectValue($normalizedSession, 'currency'))
                ?? $subscription->getCurrency()
                ?? $document->getCurrency()
                ?? 'EUR'
            )
            ->setHostedInvoiceUrl($this->normalizeString($this->readObjectValue($invoiceObject, 'hosted_invoice_url')) ?? $document->getHostedInvoiceUrl())
            ->setInvoicePdfUrl($this->normalizeString($this->readObjectValue($invoiceObject, 'invoice_pdf')) ?? $document->getInvoicePdfUrl())
            ->setIssuedAt($document->getIssuedAt() ?? $this->resolveIssuedAt($invoiceObject))
            ->setPaidAt($subscription->getPaidAt() ?? $document->getPaidAt() ?? new \DateTimeImmutable());

        if ($document->getPurchaseLabel() === null) {
            $document->setPurchaseLabel($this->resolvePurchaseLabel($normalizedSession));
        }

        if ($document->getInvoicePdfUrl() !== null) {
            $this->syncInvoicePdf($document);
        }

        return $document;
    }

    private function resolveInvoiceObject(array|object|null $session): array|object|null
    {
        if ($session === null) {
            return null;
        }

        $invoice = $this->readObjectValue($session, 'invoice');

        return is_array($invoice) || is_object($invoice) ? $invoice : null;
    }

    private function refreshInvoiceDataFromStripe(ProjectBillingDocument $document): array|object|null
    {
        $invoiceId = $this->normalizeString($document->getStripeInvoiceId())
            ?? $this->resolveStripeIdentifierFromReference($document->getPaymentReference(), 'in');
        if ($invoiceId !== null) {
            $invoice = $this->retrieveInvoice($invoiceId);
            if ($invoice !== null) {
                return $invoice;
            }
        }

        $sessionId = $this->normalizeString($document->getStripeCheckoutSessionId())
            ?? $this->resolveStripeIdentifierFromReference($document->getPaymentReference(), 'cs');
        if ($sessionId === null) {
            return null;
        }

        $session = $this->retrieveCheckoutSession($sessionId);
        if ($session === null) {
            return null;
        }

        $invoice = $this->resolveInvoiceObject($session);
        if ($invoice === null) {
            return null;
        }

        $nestedInvoiceId = $this->normalizeString($this->readObjectValue($invoice, 'id'));
        if (
            $nestedInvoiceId !== null
            && $this->normalizeString($this->readObjectValue($invoice, 'invoice_pdf')) === null
            && $this->normalizeString($this->readObjectValue($invoice, 'hosted_invoice_url')) === null
        ) {
            $refreshedInvoice = $this->retrieveInvoice($nestedInvoiceId);
            if ($refreshedInvoice !== null) {
                $invoice = $refreshedInvoice;
            }
        }

        return $invoice;
    }

    private function logStripeDownloadFailure(ProjectBillingDocument $document, string $reason, string $pdfUrl, string $absolutePath, string $relativePath, array $extra = []): void
    {
        $context = array_merge([
            'reason' => $reason,
            'project_id' => $document->getProject()?->getId(),
            'document_id' => $document->getId(),
            'invoice_pdf_url_present' => $document->getInvoicePdfUrl() !== null ? 'yes' : 'no',
            'invoice_url' => $pdfUrl,
            'status_code' => $extra['status_code'] ?? null,
            'content_type' => $extra['content_type'] ?? null,
            'content_length' => $extra['content_length'] ?? null,
            'downloaded_size' => $extra['content_length'] ?? null,
            'absolute_path' => $absolutePath,
            'relative_path' => $relativePath,
        ], $extra);

        $this->logWarning('Stripe invoice download failed.', $context);
    }

    private function logStripeSyncDiagnostic(ProjectBillingDocument $document, string $reason, array $extra = []): void
    {
        $session = null;
        $invoice = null;

        $sessionId = $this->normalizeString($document->getStripeCheckoutSessionId())
            ?? $this->resolveStripeIdentifierFromReference($document->getPaymentReference(), 'cs');
        if ($sessionId !== null) {
            $session = $this->retrieveCheckoutSession($sessionId);
        }

        $invoiceId = $this->normalizeString($document->getStripeInvoiceId())
            ?? $this->resolveStripeIdentifierFromReference($document->getPaymentReference(), 'in');
        if ($invoiceId !== null) {
            $invoice = $this->retrieveInvoice($invoiceId);
        } elseif ($session !== null) {
            $invoice = $this->resolveInvoiceObject($session);
        }

        $context = array_merge([
            'reason' => $reason,
            'project_id' => $document->getProject()?->getId(),
            'document_id' => $document->getId(),
            'document_type' => $document->getType(),
            'document_status' => $document->getStatus(),
            'stripe_checkout_session_id' => $document->getStripeCheckoutSessionId(),
            'stripe_invoice_id' => $document->getStripeInvoiceId(),
            'stripe_payment_intent_id' => $document->getStripePaymentIntentId(),
            'payment_reference' => $document->getPaymentReference(),
            'invoice_pdf_url' => $document->getInvoicePdfUrl(),
            'hosted_invoice_url' => $document->getHostedInvoiceUrl(),
        ], $extra);

        if ($session !== null) {
            $context['session_mode'] = $this->normalizeString($this->readObjectValue($session, 'mode'));
            $context['session_payment_status'] = $this->normalizeString($this->readObjectValue($session, 'payment_status'));
            $context['session_customer'] = $this->extractStripeObjectId($this->readObjectValue($session, 'customer'));
            $context['session_customer_email'] = $this->normalizeString($this->readObjectValue($session, 'customer_email'));
            $context['session_invoice'] = $this->extractStripeObjectId($this->readObjectValue($session, 'invoice'));
            $context['session_payment_intent'] = $this->extractStripeObjectId($this->readObjectValue($session, 'payment_intent'));
            $context['session_invoice_creation_enabled'] = $this->readObjectValue($this->readObjectValue($session, 'invoice_creation'), 'enabled') ? 'yes' : 'no';
        }

        if ($invoice !== null) {
            $context['invoice_status'] = $this->normalizeString($this->readObjectValue($invoice, 'status'));
            $context['invoice_hosted_invoice_url'] = $this->normalizeString($this->readObjectValue($invoice, 'hosted_invoice_url'));
            $context['invoice_pdf_present'] = $this->normalizeString($this->readObjectValue($invoice, 'invoice_pdf')) !== null ? 'yes' : 'no';
        }

        $this->logWarning('Stripe invoice sync diagnostic.', $context);
    }

    private function resolveStripeIdentifierFromReference(?string $reference, string $prefix): ?string
    {
        $normalizedReference = $this->normalizeString($reference);
        if ($normalizedReference === null || !str_starts_with($normalizedReference, $prefix.'_')) {
            return null;
        }

        return $normalizedReference;
    }

    private function applyStripeInvoiceToDocument(ProjectBillingDocument $document, array|object $invoice): void
    {
        $document
            ->setType(ProjectBillingDocument::TYPE_INVOICE)
            ->setStripeInvoiceId($this->extractStripeObjectId($invoice) ?? $document->getStripeInvoiceId())
            ->setHostedInvoiceUrl($this->normalizeString($this->readObjectValue($invoice, 'hosted_invoice_url')) ?? $document->getHostedInvoiceUrl())
            ->setInvoicePdfUrl($this->normalizeString($this->readObjectValue($invoice, 'invoice_pdf')) ?? $document->getInvoicePdfUrl())
            ->setPaymentReference(
                $this->normalizeString($this->readObjectValue($invoice, 'number'))
                ?? $document->getPaymentReference()
            );
    }

    private function retrieveInvoice(string $invoiceId): array|object|null
    {
        try {
            $invoice = $this->stripeClient->invoices->retrieve($invoiceId);
        } catch (Throwable) {
            return null;
        }

        return is_array($invoice) || is_object($invoice) ? $invoice : null;
    }

    private function retrieveCheckoutSession(string $sessionId): array|object|null
    {
        try {
            $session = $this->stripeClient->checkout->sessions->retrieve($sessionId, [
                'expand' => [
                    'payment_intent',
                    'invoice',
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        return is_array($session) || is_object($session) ? $session : null;
    }

    private function resolveDocumentType(array|object|null $invoice): string
    {
        if ($invoice !== null) {
            return ProjectBillingDocument::TYPE_INVOICE;
        }

        return ProjectBillingDocument::TYPE_RECEIPT;
    }

    private function resolvePurchaseLabel(array|object $session): ?string
    {
        $phase = $this->resolvePurchasePhase($session);
        if (!$phase instanceof CommercialPhase) {
            return null;
        }

        $phaseLabel = match ($phase) {
            CommercialPhase::ELABORATION => 'Elaboración',
            CommercialPhase::IMPLEMENTATION => 'Implementación',
        };

        $currentTier = $this->normalizeString($this->readSessionMetadataValue($session, 'current_tier'));
        $targetTier = $this->normalizeString($this->readSessionMetadataValue($session, 'target_tier'));
        $upgradeType = $this->normalizeString($this->readSessionMetadataValue($session, 'upgrade_type'));

        if (
            $currentTier === ProjectSubscription::TIER_STANDARD
            && $targetTier === ProjectSubscription::TIER_PRO
            && $upgradeType === 'standard_to_pro'
        ) {
            return sprintf('Upgrade %s Standard → Pro', $phaseLabel);
        }

        if (
            $currentTier === ProjectSubscription::TIER_BASIC
            && $targetTier === ProjectSubscription::TIER_PRO
            && $upgradeType === null
        ) {
            return sprintf('%s Pro', $phaseLabel);
        }

        if (
            $currentTier === ProjectSubscription::TIER_BASIC
            && $targetTier === ProjectSubscription::TIER_STANDARD
            && $upgradeType === null
        ) {
            return sprintf('%s Standard', $phaseLabel);
        }

        return null;
    }

    private function resolvePurchasePhase(array|object $session): ?CommercialPhase
    {
        $phase = $this->normalizeString($this->readSessionMetadataValue($session, 'commercial_phase'));
        if ($phase !== null) {
            $resolvedPhase = CommercialPhase::tryFrom($phase);
            if ($resolvedPhase instanceof CommercialPhase) {
                return $resolvedPhase;
            }
        }

        return null;
    }

    private function readSessionMetadataValue(array|object $session, string $key): mixed
    {
        $directValue = $this->readObjectValue($session, $key);
        if ($directValue !== null) {
            return $directValue;
        }

        return $this->readObjectValue($this->readObjectValue($session, 'metadata'), $key);
    }

    private function resolveIssuedAt(array|object|null $invoice): ?\DateTimeImmutable
    {
        $created = $this->readObjectValue($invoice, 'created');
        if (is_int($created) || is_float($created) || (is_string($created) && ctype_digit($created))) {
            return (new \DateTimeImmutable())->setTimestamp((int) $created);
        }

        return null;
    }

    private function resolveStoredAbsolutePath(ProjectBillingDocument $document): ?string
    {
        $storedPath = $this->normalizeStoredPath($document->getLocalPath());
        if ($storedPath === null) {
            return null;
        }

        $absolutePath = $this->buildAbsolutePath($storedPath);
        if (!is_file($absolutePath)) {
            return null;
        }

        return $absolutePath;
    }

    private function buildRelativePath(ProjectBillingDocument $document): string
    {
        $projectId = $document->getProject()?->getId() ?? 'unknown';
        $segment = $this->sanitizeSegment(
            $this->normalizeString($document->getStripeInvoiceId())
            ?? $this->normalizeString($document->getStripeCheckoutSessionId())
            ?? $this->normalizeString($document->getStripePaymentIntentId())
            ?? 'document'
        );

        $prefix = $document->getStripeInvoiceId() !== null
            ? 'invoice'
            : ($document->getStripeCheckoutSessionId() !== null ? 'checkout' : 'payment');

        return sprintf('%s/project-%s/%s-%s.pdf', self::STORAGE_ROOT, $projectId, $prefix, $segment);
    }

    private function buildAbsolutePath(string $relativePath): string
    {
        return rtrim($this->projectDir, '/').'/var/private/'.ltrim($relativePath, '/');
    }

    private function relativePathFromAbsolute(string $absolutePath): string
    {
        $normalizedBaseDir = rtrim($this->projectDir, '/').'/var/private';
        $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);
        $normalizedBaseDir = str_replace('\\', '/', $normalizedBaseDir);

        if (str_starts_with($normalizedAbsolutePath, $normalizedBaseDir.'/')) {
            return ltrim(substr($normalizedAbsolutePath, strlen($normalizedBaseDir) + 1), '/');
        }

        return ltrim($absolutePath, '/');
    }

    private function normalizeStoredPath(?string $path): ?string
    {
        $normalized = $this->normalizeString($path);
        if ($normalized === null) {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', $normalized), '/');
        if (!str_starts_with($normalized, self::STORAGE_ROOT.'/')) {
            return null;
        }

        return $normalized;
    }

    private function sanitizeSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?: 'document';

        $trimmed = trim($sanitized, '_');

        return $trimmed !== '' ? $trimmed : 'document';
    }

    private function extractObject(mixed $value): array|object|null
    {
        if (is_array($value) || is_object($value)) {
            return $value;
        }

        return null;
    }

    private function extractStripeObjectId(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $this->normalizeString($this->readObjectValue($value, 'id'));
    }

    private function normalizeString(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function readObjectValue(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            return $value[$key] ?? null;
        }

        if (is_object($value)) {
            return $value->{$key} ?? null;
        }

        return null;
    }

    private function logWarning(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message, $context);
        }
    }
}
