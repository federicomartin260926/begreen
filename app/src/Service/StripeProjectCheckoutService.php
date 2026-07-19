<?php

namespace App\Service;

use App\Exception\PendingStripeCheckoutException;
use App\Entity\CommercialPlan;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final class StripeProjectCheckoutService
{
    private const TARGET_CONFIG = [
        ProjectSubscription::TIER_STANDARD => 'Standard',
        ProjectSubscription::TIER_PRO => 'Pro',
    ];

    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly ProjectFeatureGate $featureGate,
        private readonly CommercialPlanRepository $commercialPlanRepository,
        private readonly PlanRepository $planRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeInvoiceStorageService $invoiceStorageService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?string $successUrlTemplate,
        private readonly ?string $cancelUrlTemplate,
        private readonly SustainabilityPlanCompletionService $planCompletionService,
    ) {
    }

    public function getAvailableUpgradeTargets(Project $project, CommercialPhase $phase): array
    {
        $currentTier = $this->featureGate->getTier($project, $phase);
        $available = [];

        foreach ([ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO] as $targetTier) {
            if (!$this->canUpgrade($currentTier, $phase, $targetTier)) {
                continue;
            }

            $targetPlan = $this->findTargetPlan($phase, $targetTier);
            if (!$targetPlan instanceof CommercialPlan) {
                continue;
            }

            $priceId = $this->resolvePriceId($phase, $currentTier, $targetTier);
            if ($priceId === null || $priceId === '') {
                continue;
            }

            $available[$targetTier] = [
                'targetTier' => $targetTier,
                'label' => $this->resolveTargetLabel($phase, $targetTier),
                'phase' => $phase->value,
                'amountCents' => $this->resolveAmountCents($phase, $currentTier, $targetTier),
                'priceId' => $priceId,
            ];
        }

        return $available;
    }

    public function inspectPendingCheckout(Project $project, CommercialPhase $phase): StripePendingCheckoutInspection
    {
        $subscription = $project->getSubscriptionForPhase($phase);
        if (!$subscription instanceof ProjectSubscription) {
            return StripePendingCheckoutInspection::none();
        }

        $sessionId = $this->normalizeString($subscription->getStripeCheckoutSessionId());
        $targetTier = $this->normalizeString($subscription->getTargetTier());
        if ($sessionId === null || $targetTier === null || $subscription->getStatus() !== ProjectSubscription::STATUS_PENDING_PAYMENT) {
            return StripePendingCheckoutInspection::none();
        }

        try {
            $session = $this->stripeClient->checkout->sessions->retrieve($sessionId, [
                'expand' => [
                    'payment_intent',
                    'invoice',
                    'customer',
                ],
            ]);
        } catch (Throwable) {
            return StripePendingCheckoutInspection::missing($subscription);
        }

        if (!is_object($session)) {
            return StripePendingCheckoutInspection::missing($subscription);
        }

        $retrievedSessionId = $this->normalizeString($session->id ?? null);
        if ($retrievedSessionId === null || $retrievedSessionId !== $sessionId) {
            return StripePendingCheckoutInspection::missing($subscription);
        }

        $metadata = is_object($session->metadata ?? null) || is_array($session->metadata ?? null)
            ? $session->metadata
            : [];
        $projectId = (int) ($this->readMetadataValue($metadata, 'project_id') ?? 0);
        $metadataPhase = $this->resolveMetadataPhase($metadata);
        $metadataTargetTier = $this->normalizeString($this->readMetadataValue($metadata, 'target_tier'));

        if (
            $projectId !== (int) $project->getId()
            || !$metadataPhase instanceof CommercialPhase
            || $metadataPhase !== $phase
            || ($metadataTargetTier !== null && $metadataTargetTier !== $targetTier)
        ) {
            return StripePendingCheckoutInspection::missing($subscription);
        }

        $sessionStatus = $this->normalizeString($session->status ?? null);
        $paymentStatus = $this->normalizeString($session->payment_status ?? null);
        $checkoutUrl = $this->normalizeString($session->url ?? null);
        $expiresAt = $this->normalizeTimestamp($session->expires_at ?? null);

        if ($sessionStatus === 'complete' && $paymentStatus === 'paid') {
            return StripePendingCheckoutInspection::paid($subscription, $sessionStatus, $paymentStatus);
        }

        if ($sessionStatus === 'expired') {
            return StripePendingCheckoutInspection::expired($subscription, $sessionStatus, $paymentStatus);
        }

        if ($sessionStatus === 'complete') {
            return StripePendingCheckoutInspection::missing($subscription, $sessionStatus, $paymentStatus);
        }

        if ($sessionStatus === 'open' && $paymentStatus === 'unpaid') {
            if ($expiresAt !== null && $expiresAt <= time()) {
                return StripePendingCheckoutInspection::expired($subscription, $sessionStatus, $paymentStatus);
            }

            if ($checkoutUrl !== null) {
                return StripePendingCheckoutInspection::open($subscription, $checkoutUrl, $sessionStatus, $paymentStatus);
            }
        }

        if ($checkoutUrl !== null && $sessionStatus === null) {
            return StripePendingCheckoutInspection::open($subscription, $checkoutUrl, $sessionStatus, $paymentStatus);
        }

        return StripePendingCheckoutInspection::missing($subscription, $sessionStatus, $paymentStatus);
    }

    public function reconcilePendingCheckout(Project $project, CommercialPhase $phase, ?string $sessionId = null): StripeCheckoutReconciliationResult
    {
        $subscription = $project->getSubscriptionForPhase($phase);
        if (!$subscription instanceof ProjectSubscription) {
            return StripeCheckoutReconciliationResult::nothingToConfirm();
        }

        $requestedSessionId = $this->normalizeString($sessionId);
        $storedSessionId = $this->normalizeString($subscription->getStripeCheckoutSessionId());
        $checkoutSessionId = $requestedSessionId ?? $storedSessionId;

        if ($checkoutSessionId === null) {
            return StripeCheckoutReconciliationResult::nothingToConfirm();
        }

        if (
            $requestedSessionId !== null
            && $storedSessionId !== null
            && $storedSessionId !== $requestedSessionId
        ) {
            return StripeCheckoutReconciliationResult::mismatch($subscription);
        }

        try {
            $session = $this->stripeClient->checkout->sessions->retrieve($checkoutSessionId, [
                'expand' => [
                    'payment_intent',
                    'invoice',
                    'customer',
                ],
            ]);
        } catch (Throwable) {
            return StripeCheckoutReconciliationResult::error($subscription);
        }

        if (!is_object($session)) {
            return StripeCheckoutReconciliationResult::error($subscription);
        }

        $retrievedSessionId = $this->normalizeString($session->id ?? null);
        if ($retrievedSessionId === null || $retrievedSessionId !== $checkoutSessionId) {
            return StripeCheckoutReconciliationResult::mismatch($subscription);
        }

        $metadata = is_object($session->metadata ?? null) || is_array($session->metadata ?? null)
            ? $session->metadata
            : [];
        $projectId = (int) ($this->readMetadataValue($metadata, 'project_id') ?? 0);
        if ($projectId !== (int) $project->getId()) {
            return StripeCheckoutReconciliationResult::mismatch($subscription);
        }
        $metadataPhase = $this->resolveMetadataPhase($metadata);
        if (!$metadataPhase instanceof CommercialPhase || $metadataPhase !== $phase || $subscription->getPhase() !== $phase) {
            return StripeCheckoutReconciliationResult::mismatch($subscription);
        }

        $targetTier = $this->resolveReconciledTargetTier($subscription, $metadata);
        if ($targetTier === null) {
            return StripeCheckoutReconciliationResult::mismatch($subscription);
        }

        $invoice = $this->resolveStripeInvoice($session->invoice ?? null);

        if (
            $subscription->getStatus() === ProjectSubscription::STATUS_ACTIVE
            && $subscription->getTier() === $targetTier
            && $storedSessionId !== null
            && $storedSessionId === $checkoutSessionId
        ) {
            $planBecameIncompleteAfterUpgrade = $this->syncPlanCompletionAndDetectIncomplete($project, $phase);
            $firstPendingVisibleMeasureIndex = $planBecameIncompleteAfterUpgrade
                ? $this->resolveFirstPendingVisibleMeasureIndex($project)
                : null;
            $this->fillMissingStripeReferences($subscription, $session, $invoice);
            $this->upsertBillingDocument($subscription, $session, $invoice);
            $this->entityManager->flush();

            return StripeCheckoutReconciliationResult::alreadyConfirmed(
                $subscription,
                $planBecameIncompleteAfterUpgrade,
                $firstPendingVisibleMeasureIndex
            );
        }

        $paymentStatus = $this->normalizeString($session->payment_status ?? null) ?? 'unknown';
        if ($paymentStatus !== 'paid') {
            $subscription->setLastPaymentStatus($paymentStatus);
            $this->entityManager->flush();

            return StripeCheckoutReconciliationResult::pending($subscription);
        }

        if (!$this->canUpgrade($subscription->getTier(), $phase, $targetTier) && $subscription->getTier() !== $targetTier) {
            return StripeCheckoutReconciliationResult::mismatch($subscription);
        }

        $paymentIntentId = $this->extractStripeObjectId($session->payment_intent ?? null);
        $customerId = $this->extractStripeObjectId($session->customer ?? null);
        $invoiceId = $this->extractStripeObjectId($invoice);
        $paidAmountCents = isset($session->amount_total) ? (int) $session->amount_total : null;
        $currency = strtoupper($this->normalizeString($session->currency ?? null) ?? $subscription->getCurrency() ?? 'EUR');
        $paymentReference = $this->normalizeString($this->readObjectValue($invoice, 'number'))
            ?? $invoiceId
            ?? $checkoutSessionId;

        $subscription
            ->setTier($targetTier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents($paidAmountCents)
            ->setCurrency($currency !== '' ? $currency : 'EUR')
            ->setPaymentReference($paymentReference)
            ->setStripeCheckoutSessionId($checkoutSessionId)
            ->setStripePaymentIntentId($paymentIntentId)
            ->setStripeInvoiceId($invoiceId)
            ->setStripeCustomerId($customerId)
            ->setStripeHostedInvoiceUrl($this->normalizeString($this->readObjectValue($invoice, 'hosted_invoice_url')))
            ->setStripeInvoicePdfUrl($this->normalizeString($this->readObjectValue($invoice, 'invoice_pdf')))
            ->setLastPaymentStatus($paymentStatus)
            ->setPaidAt(new \DateTimeImmutable())
            ->setTargetTier(null);

        $planBecameIncompleteAfterUpgrade = $this->syncPlanCompletionAndDetectIncomplete($project, $phase);
        $firstPendingVisibleMeasureIndex = $planBecameIncompleteAfterUpgrade
            ? $this->resolveFirstPendingVisibleMeasureIndex($project)
            : null;

        $this->upsertBillingDocument($subscription, $session, $invoice);

        $this->entityManager->flush();

        return StripeCheckoutReconciliationResult::confirmed(
            $subscription,
            $planBecameIncompleteAfterUpgrade,
            $firstPendingVisibleMeasureIndex
        );
    }

    public function canUpgrade(Project|string $projectOrTier, CommercialPhase $phase, string $targetTier): bool
    {
        $currentTier = $projectOrTier instanceof Project
            ? $this->featureGate->getTier($projectOrTier, $phase)
            : $projectOrTier;

        return $this->isValidTargetTier($targetTier) && $this->tierRank($targetTier) > $this->tierRank($currentTier);
    }

    public function startCheckout(Project $project, CommercialPhase $phase, string $targetTier, ?User $user = null): string
    {
        $subscription = $project->getSubscriptionForPhase($phase);
        if (!$subscription instanceof ProjectSubscription) {
            throw new \RuntimeException(sprintf('Missing project subscription for project "%s" and phase "%s".', $project->getId() ?? 'unpersisted', $phase->value));
        }

        $currentTier = $this->featureGate->getTier($project, $phase);
        if (!$this->canUpgrade($currentTier, $phase, $targetTier)) {
            throw new \InvalidArgumentException('Upgrade not allowed for the selected tier.');
        }

        $pendingCheckout = $this->inspectPendingCheckout($project, $phase);
        if (
            $subscription->getTargetTier() !== null
            && $this->normalizeString($subscription->getStripeCheckoutSessionId()) !== null
            && $subscription->getTargetTier() === $targetTier
        ) {
            if ($pendingCheckout->isReusable()) {
                return (string) $pendingCheckout->getCheckoutUrl();
            }

            if ($pendingCheckout->shouldRetry()) {
                $this->clearPendingCheckout($subscription);
            } elseif ($pendingCheckout->shouldVerify()) {
                throw new PendingStripeCheckoutException('A Stripe payment is already pending for this project. Verify or cancel it before starting another checkout.');
            } else {
                throw new PendingStripeCheckoutException('A Stripe payment is already pending for this project. Verify or cancel it before starting another checkout.');
            }
        } elseif (
            $subscription->getTargetTier() !== null
            && $this->normalizeString($subscription->getStripeCheckoutSessionId()) !== null
        ) {
            throw new PendingStripeCheckoutException('A Stripe payment is already pending for this project. Verify or cancel it before starting another checkout.');
        }

        $successUrl = $this->resolveUrlTemplate(
            $this->successUrlTemplate,
            'backend_project_subscription_success',
            $project,
            $phase,
            $targetTier,
            [
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ]
        );

        $cancelUrl = $this->resolveUrlTemplate(
            $this->cancelUrlTemplate,
            'backend_project_subscription_cancel',
            $project,
            $phase,
            $targetTier,
            [
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ]
        );

        $targetPlan = $this->resolveTargetPlan($phase, $targetTier);
        $priceId = $this->resolvePriceId($phase, $currentTier, $targetTier);
        if ($priceId === null || $priceId === '') {
            if ($currentTier === ProjectSubscription::TIER_STANDARD && $targetTier === ProjectSubscription::TIER_PRO) {
                throw new \RuntimeException('Stripe upgrade price id is not configured for the Standard -> Pro transition.');
            }

            throw new \RuntimeException(sprintf('Stripe price id is not configured for commercial plan "%s".', $targetPlan->getCode()));
        }

        $metadata = array_filter([
            'project_id' => (string) $project->getId(),
            'commercial_phase' => $phase->value,
            'current_tier' => $currentTier,
            'target_tier' => $targetTier,
            'commercial_plan_code' => $targetPlan->getCode(),
            'upgrade_from_tier' => $currentTier === ProjectSubscription::TIER_STANDARD && $targetTier === ProjectSubscription::TIER_PRO
                ? $currentTier
                : null,
            'upgrade_type' => $currentTier === ProjectSubscription::TIER_STANDARD && $targetTier === ProjectSubscription::TIER_PRO
                ? 'standard_to_pro'
                : null,
            'user_id' => $user?->getId() ? (string) $user->getId() : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $session = $this->stripeClient->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_creation' => 'always',
            'invoice_creation' => [
                'enabled' => true,
            ],
            'billing_address_collection' => 'required',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $project->getId(),
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata,
            ],
        ]);

        $subscription
            ->setTier($currentTier)
            ->setStatus(ProjectSubscription::STATUS_PENDING_PAYMENT)
            ->setCurrency($subscription->getCurrency() ?: 'EUR')
            ->setPaidAmountCents($subscription->getPaidAmountCents())
            ->setPaymentReference($subscription->getPaymentReference())
            ->setStripeCheckoutSessionId($session->id)
            ->setLastPaymentStatus('checkout_created')
            ->setTargetTier($targetTier);

        $this->entityManager->flush();

        if (!is_string($session->url) || $session->url === '') {
            throw new \RuntimeException('Stripe checkout session URL is not available.');
        }

        return $session->url;
    }

    public function clearPendingCheckout(ProjectSubscription $subscription): void
    {
        $subscription
            ->setStripeCheckoutSessionId(null)
            ->setTargetTier(null);

        if ($subscription->getPaidAmountCents() !== null || $subscription->getPaidAt() !== null) {
            $subscription->setLastPaymentStatus('paid');
        } else {
            $subscription->setLastPaymentStatus(null);
        }

        if ($subscription->getStatus() === ProjectSubscription::STATUS_PENDING_PAYMENT) {
            $subscription->setStatus(ProjectSubscription::STATUS_ACTIVE);
        }
    }

    public function resolveTargetLabel(CommercialPhase $phase, string $targetTier): string
    {
        $plan = $this->findTargetPlan($phase, $targetTier);
        if ($plan instanceof CommercialPlan && trim($plan->getName()) !== '') {
            return $plan->getName();
        }

        return self::TARGET_CONFIG[$targetTier] ?? ucfirst($targetTier);
    }

    public function resolveTargetAmountCents(Project|string $projectOrTier, CommercialPhase $phase, string $targetTier): ?int
    {
        $currentTier = $projectOrTier instanceof Project
            ? $this->featureGate->getTier($projectOrTier, $phase)
            : $projectOrTier;

        if (!$this->canUpgrade($currentTier, $phase, $targetTier)) {
            return null;
        }

        return $this->resolveAmountCents($phase, $currentTier, $targetTier);
    }

    private function resolveAmountCents(CommercialPhase $phase, string $currentTier, string $targetTier): ?int
    {
        $currentPlan = $this->resolveCurrentPlan($phase, $currentTier);
        $targetPlan = $this->findTargetPlan($phase, $targetTier);

        if (!$targetPlan instanceof CommercialPlan) {
            return null;
        }

        $currentAmount = $currentPlan?->getPriceAmount() ?? 0;
        $targetAmount = $targetPlan->getPriceAmount();

        if ($targetAmount === null) {
            return null;
        }

        return max(0, $targetAmount - $currentAmount);
    }

    private function resolvePriceId(CommercialPhase $phase, string $currentTier, string $targetTier): ?string
    {
        $targetPlan = $this->findTargetPlan($phase, $targetTier);
        if (!$targetPlan instanceof CommercialPlan) {
            return null;
        }

        if ($currentTier === ProjectSubscription::TIER_STANDARD && $targetTier === ProjectSubscription::TIER_PRO) {
            return $targetPlan->getStripeUpgradeFromStandardPriceId();
        }

        return $targetPlan->getStripePriceId();
    }

    private function resolveTargetPlan(CommercialPhase $phase, string $targetTier): CommercialPlan
    {
        $plan = $this->findTargetPlan($phase, $targetTier);
        if ($plan instanceof CommercialPlan) {
            return $plan;
        }

        throw new \RuntimeException(sprintf('Commercial plan "%s" is not available for checkout.', $targetTier));
    }

    private function findTargetPlan(CommercialPhase $phase, string $targetTier): ?CommercialPlan
    {
        return $this->commercialPlanRepository->findActiveByPhaseAndCode($phase, $targetTier);
    }

    private function resolveCurrentPlan(CommercialPhase $phase, string $currentTier): ?CommercialPlan
    {
        return $this->findTargetPlan($phase, $currentTier);
    }

    private function resolveReconciledTargetTier(ProjectSubscription $subscription, array|object $metadata): ?string
    {
        $targetTier = $this->normalizeString($this->readMetadataValue($metadata, 'target_tier'));
        if ($targetTier !== null && $this->isValidTargetTier($targetTier)) {
            return $targetTier;
        }

        $commercialPlanCode = $this->normalizeString($this->readMetadataValue($metadata, 'commercial_plan_code'));
        if ($commercialPlanCode !== null) {
            $mappedTier = strtolower($commercialPlanCode);
            if ($this->isValidTargetTier($mappedTier)) {
                return $mappedTier;
            }
        }

        $storedTargetTier = $this->normalizeString($subscription->getTargetTier());
        if ($storedTargetTier !== null && $this->isValidTargetTier($storedTargetTier)) {
            return $storedTargetTier;
        }

        return null;
    }

    private function syncPlanCompletionAndDetectIncomplete(Project $project, CommercialPhase $phase): bool
    {
        if ($phase !== CommercialPhase::ELABORATION) {
            return false;
        }

        $plan = $this->planRepository->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            return false;
        }

        $previousStatus = $plan->getStatus();
        $this->planCompletionService->syncStatus($plan, $project);

        return $previousStatus === 'completo' && $plan->getStatus() === 'incompleto';
    }

    private function resolveFirstPendingVisibleMeasureIndex(Project $project): ?int
    {
        $plan = $this->planRepository->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            return null;
        }

        $pendingMeasure = $this->planCompletionService->findFirstPendingVisibleMeasure($plan, $project);
        if (!is_array($pendingMeasure)) {
            return null;
        }

        $index = $pendingMeasure['index'] ?? null;
        return is_int($index) ? $index : null;
    }

    private function fillMissingStripeReferences(ProjectSubscription $subscription, object $session, array|object|null $invoice = null): void
    {
        $invoice ??= $this->resolveStripeInvoice($session->invoice ?? null);

        if ($subscription->getStripePaymentIntentId() === null) {
            $subscription->setStripePaymentIntentId($this->extractStripeObjectId($session->payment_intent ?? null));
        }
        if ($subscription->getStripeInvoiceId() === null) {
            $subscription->setStripeInvoiceId($this->extractStripeObjectId($invoice));
        }
        if ($subscription->getStripeCustomerId() === null) {
            $subscription->setStripeCustomerId($this->extractStripeObjectId($session->customer ?? null));
        }
        if ($subscription->getStripeHostedInvoiceUrl() === null) {
            $subscription->setStripeHostedInvoiceUrl($this->normalizeString($this->readObjectValue($invoice, 'hosted_invoice_url')));
        }
        if ($subscription->getStripeInvoicePdfUrl() === null) {
            $subscription->setStripeInvoicePdfUrl($this->normalizeString($this->readObjectValue($invoice, 'invoice_pdf')));
        }
        if ($subscription->getPaymentReference() === null) {
            $subscription->setPaymentReference(
                $this->normalizeString($this->readObjectValue($invoice, 'number'))
                ?? $this->extractStripeObjectId($invoice)
                ?? $this->normalizeString($session->id ?? null)
            );
        }
        if ($subscription->getLastPaymentStatus() === null) {
            $subscription->setLastPaymentStatus($this->normalizeString($session->payment_status ?? null) ?? 'paid');
        }
        if ($subscription->getPaidAt() === null) {
            $subscription->setPaidAt(new \DateTimeImmutable());
        }
        if ($subscription->getPaidAmountCents() === null && isset($session->amount_total)) {
            $subscription->setPaidAmountCents((int) $session->amount_total);
        }
        if ($subscription->getTargetTier() !== null) {
            $subscription->setTargetTier(null);
        }
    }

    private function upsertBillingDocument(ProjectSubscription $subscription, object $session, array|object|null $invoice): void
    {
        $this->invoiceStorageService->upsertFromStripeCheckout($subscription, $session, $invoice);
    }

    private function extractStripeObject(mixed $value): array|object|null
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

    private function resolveStripeInvoice(mixed $value): array|object|null
    {
        $originalInvoice = is_array($value) || is_object($value) ? $value : null;

        if (is_array($value) || is_object($value)) {
            $invoiceId = $this->normalizeString($this->readObjectValue($value, 'id'));
            $hasDownloadData = $this->normalizeString($this->readObjectValue($value, 'invoice_pdf')) !== null
                && $this->normalizeString($this->readObjectValue($value, 'hosted_invoice_url')) !== null;

            if ($invoiceId === null || $hasDownloadData) {
                return $value;
            }

            $value = $invoiceId;
        }

        $invoiceId = $this->normalizeString($value);
        if ($invoiceId === null) {
            return null;
        }

        try {
            $invoice = $this->stripeClient->invoices->retrieve($invoiceId);
        } catch (Throwable) {
            return $originalInvoice;
        }

        return is_array($invoice) || is_object($invoice) ? $invoice : null;
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

    private function normalizeTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
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

    private function readMetadataValue(array|object $metadata, string $key): ?string
    {
        return $this->normalizeString($this->readObjectValue($metadata, $key));
    }

    private function resolveMetadataPhase(array|object $metadata): ?CommercialPhase
    {
        $phase = $this->readMetadataValue($metadata, 'commercial_phase');
        if ($phase === null) {
            return null;
        }

        return CommercialPhase::tryFrom($phase);
    }

    private function resolveUrlTemplate(
        ?string $template,
        string $routeName,
        Project $project,
        CommercialPhase $phase,
        string $targetTier,
        array $query = []
    ): string
    {
        $fallback = $this->urlGenerator->generate($routeName, [
            'id' => $project->getId(),
            'phase' => $phase->value,
            'targetTier' => $targetTier,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        if ($query !== []) {
            $pairs = [];
            foreach ($query as $key => $value) {
                $pairs[] = rawurlencode((string) $key).'='.(string) $value;
            }
            $fallback .= '?'.implode('&', $pairs);
        }

        if ($template === null || trim($template) === '') {
            return $fallback;
        }

        if (!str_contains($template, '{COMMERCIAL_PHASE}') && !str_contains($template, '{commercial_phase}')) {
            return $fallback;
        }

        $resolved = str_replace(
            ['{PROJECT_ID}', '{project_id}', '{COMMERCIAL_PHASE}', '{commercial_phase}', '{TARGET_TIER}', '{target_tier}'],
            [(string) $project->getId(), (string) $project->getId(), $phase->value, $phase->value, $targetTier, $targetTier],
            $template
        );

        return $resolved;
    }

    private function isValidTargetTier(string $tier): bool
    {
        return in_array($tier, [ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO], true);
    }

    private function tierRank(string $tier): int
    {
        return match ($tier) {
            ProjectSubscription::TIER_STANDARD => 2,
            ProjectSubscription::TIER_PRO => 3,
            default => 1,
        };
    }
}
