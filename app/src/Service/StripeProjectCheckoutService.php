<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\StripeClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final class StripeProjectCheckoutService
{
    private const TARGET_CONFIG = [
        ProjectSubscription::TIER_STANDARD => [
            'label' => 'Standard',
            'amount_cents' => 9900,
        ],
        ProjectSubscription::TIER_PRO => [
            'label' => 'Pro',
            'amount_cents' => 19900,
        ],
    ];

    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly ProjectFeatureGate $featureGate,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?string $standardPriceId,
        private readonly ?string $proPriceId,
        private readonly ?string $upgradeStandardToProPriceId,
        private readonly ?string $successUrlTemplate,
        private readonly ?string $cancelUrlTemplate,
    ) {
    }

    public function getAvailableUpgradeTargets(Project $project): array
    {
        $currentTier = $this->featureGate->getTier($project);
        $available = [];

        foreach ([ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO] as $targetTier) {
            if (!$this->canUpgrade($currentTier, $targetTier)) {
                continue;
            }

            $available[$targetTier] = [
                'targetTier' => $targetTier,
                'label' => self::TARGET_CONFIG[$targetTier]['label'],
                'amountCents' => $this->resolveAmountCents($currentTier, $targetTier),
                'priceId' => $this->resolvePriceId($currentTier, $targetTier),
            ];
        }

        return $available;
    }

    public function canUpgrade(Project|string $projectOrTier, string $targetTier): bool
    {
        $currentTier = $projectOrTier instanceof Project
            ? $this->featureGate->getTier($projectOrTier)
            : $projectOrTier;

        return $this->isValidTargetTier($targetTier) && $this->tierRank($targetTier) > $this->tierRank($currentTier);
    }

    public function startCheckout(Project $project, string $targetTier, ?User $user = null): string
    {
        $currentTier = $this->featureGate->getTier($project);
        if (!$this->canUpgrade($currentTier, $targetTier)) {
            throw new \InvalidArgumentException('Upgrade not allowed for the selected tier.');
        }

        $subscription = $project->getSubscription() ?? new ProjectSubscription();
        $subscription->setProject($project);

        if (
            $subscription->getStatus() === ProjectSubscription::STATUS_PENDING_PAYMENT
            && $subscription->getTargetTier() === $targetTier
            && $subscription->getStripeCheckoutSessionId()
        ) {
            try {
                $existingSession = $this->stripeClient->checkout->sessions->retrieve($subscription->getStripeCheckoutSessionId());
                if ($existingSession instanceof StripeCheckoutSession && is_string($existingSession->url) && $existingSession->url !== '') {
                    return $existingSession->url;
                }
            } catch (Throwable) {
                // Rehacemos la sesión si Stripe ya no reconoce la anterior.
            }
        }

        $successUrl = $this->resolveUrlTemplate(
            $this->successUrlTemplate,
            'backend_project_subscription_success',
            $project,
            [
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ]
        );

        $cancelUrl = $this->resolveUrlTemplate(
            $this->cancelUrlTemplate,
            'backend_project_subscription_cancel',
            $project,
            [
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ]
        );

        $priceId = $this->resolvePriceId($currentTier, $targetTier);
        if ($priceId === null || $priceId === '') {
            throw new \RuntimeException('Stripe price id is not configured for the selected upgrade.');
        }

        $metadata = array_filter([
            'project_id' => (string) $project->getId(),
            'current_tier' => $currentTier,
            'target_tier' => $targetTier,
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
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setCurrency('EUR')
            ->setPaidAmountCents(null)
            ->setPaymentReference($session->id)
            ->setStripeCheckoutSessionId($session->id)
            ->setStripePaymentIntentId(null)
            ->setStripeInvoiceId(null)
            ->setStripeCustomerId(null)
            ->setStripeHostedInvoiceUrl(null)
            ->setStripeInvoicePdfUrl(null)
            ->setLastPaymentStatus('checkout_created')
            ->setPaidAt(null)
            ->setTargetTier($targetTier);

        if (!$project->getSubscription()) {
            $project->setSubscription($subscription);
            $this->entityManager->persist($subscription);
        }

        $this->entityManager->flush();

        if (!is_string($session->url) || $session->url === '') {
            throw new \RuntimeException('Stripe checkout session URL is not available.');
        }

        return $session->url;
    }

    public function resolveTargetLabel(string $targetTier): string
    {
        return self::TARGET_CONFIG[$targetTier]['label'] ?? ucfirst($targetTier);
    }

    public function resolveTargetAmountCents(Project|string $projectOrTier, string $targetTier): ?int
    {
        $currentTier = $projectOrTier instanceof Project
            ? $this->featureGate->getTier($projectOrTier)
            : $projectOrTier;

        if (!$this->canUpgrade($currentTier, $targetTier)) {
            return null;
        }

        return $this->resolveAmountCents($currentTier, $targetTier);
    }

    private function resolveAmountCents(string $currentTier, string $targetTier): int
    {
        if ($currentTier === ProjectSubscription::TIER_STANDARD && $targetTier === ProjectSubscription::TIER_PRO) {
            return 10000;
        }

        return self::TARGET_CONFIG[$targetTier]['amount_cents'] ?? 0;
    }

    private function resolvePriceId(string $currentTier, string $targetTier): ?string
    {
        if ($targetTier === ProjectSubscription::TIER_STANDARD) {
            return $this->standardPriceId ?: null;
        }

        if ($currentTier === ProjectSubscription::TIER_STANDARD && $targetTier === ProjectSubscription::TIER_PRO) {
            return $this->upgradeStandardToProPriceId ?: null;
        }

        if ($targetTier === ProjectSubscription::TIER_PRO) {
            return $this->proPriceId ?: null;
        }

        return null;
    }

    private function resolveUrlTemplate(?string $template, string $routeName, Project $project, array $query = []): string
    {
        $fallback = $this->urlGenerator->generate($routeName, ['id' => $project->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
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

        $resolved = str_replace(
            ['{PROJECT_ID}', '{project_id}'],
            (string) $project->getId(),
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
