<?php

namespace App\Service;

use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Repository\ProjectBillingDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class StripeInvoiceStorageService
{
    private const MAX_BYTES = 10_485_760;
    private const STORAGE_ROOT = 'stripe-invoices';

    public function __construct(
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
        $pdfUrl = $this->normalizeString($document->getInvoicePdfUrl());
        if ($pdfUrl === null) {
            return false;
        }

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

        try {
            $response = $this->httpClient->request('GET', $pdfUrl, [
                'headers' => [
                    'Accept' => 'application/pdf',
                ],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logWarning('Stripe invoice download failed: unexpected status code.', [
                    'project_id' => $document->getProject()?->getId(),
                    'document_id' => $document->getId(),
                    'invoice_url' => $pdfUrl,
                    'status_code' => $response->getStatusCode(),
                ]);

                return false;
            }

            $headers = $response->getHeaders(false);
            $contentType = strtolower((string) ($headers['content-type'][0] ?? ''));
            if ($contentType !== '' && !str_contains($contentType, 'application/pdf')) {
                $this->logWarning('Stripe invoice download failed: invalid content type.', [
                    'project_id' => $document->getProject()?->getId(),
                    'document_id' => $document->getId(),
                    'invoice_url' => $pdfUrl,
                    'content_type' => $contentType,
                ]);

                return false;
            }

            $content = $response->getContent(false);
            if (!is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES) {
                $this->logWarning('Stripe invoice download failed: invalid response body.', [
                    'project_id' => $document->getProject()?->getId(),
                    'document_id' => $document->getId(),
                    'invoice_url' => $pdfUrl,
                    'content_length' => is_string($content) ? strlen($content) : null,
                ]);

                return false;
            }

            if (!str_starts_with($content, '%PDF-')) {
                $this->logWarning('Stripe invoice download failed: response is not a PDF.', [
                    'project_id' => $document->getProject()?->getId(),
                    'document_id' => $document->getId(),
                    'invoice_url' => $pdfUrl,
                ]);

                return false;
            }

            $filesystem = new Filesystem();
            $filesystem->mkdir(dirname($absolutePath));
            $filesystem->dumpFile($absolutePath, $content);

            $document
                ->setLocalPath($relativePath)
                ->setDownloadedAt(new \DateTimeImmutable());

            return true;
        } catch (Throwable $throwable) {
            $this->logWarning('Stripe invoice download failed with exception.', [
                'project_id' => $document->getProject()?->getId(),
                'document_id' => $document->getId(),
                'invoice_url' => $pdfUrl,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
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

    private function resolveDocumentType(array|object|null $invoice): string
    {
        if ($invoice !== null) {
            return ProjectBillingDocument::TYPE_INVOICE;
        }

        return ProjectBillingDocument::TYPE_RECEIPT;
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
