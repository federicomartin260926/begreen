<?php

namespace App\Service;

use App\Entity\CommercialPlan;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Repository\CommercialPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session as StripeCheckoutSession;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
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

            $targetPlan = $this->findTargetPlan($targetTier);
            if (!$targetPlan instanceof CommercialPlan) {
                continue;
            }

            $available[$targetTier] = [
                'targetTier' => $targetTier,
                'label' => $this->resolveTargetLabel($targetTier),
                'amountCents' => $this->resolveAmountCents($currentTier, $targetTier),
                'priceId' => $this->resolvePriceId($targetTier),
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

        $targetPlan = $this->resolveTargetPlan($targetTier);
        $priceId = $targetPlan->getStripePriceId();
        if ($priceId === null || $priceId === '') {
            throw new \RuntimeException(sprintf('Stripe price id is not configured for commercial plan "%s".', $targetPlan->getCode()));
        }

        $metadata = array_filter([
            'project_id' => (string) $project->getId(),
            'current_tier' => $currentTier,
            'target_tier' => $targetTier,
            'commercial_plan_code' => $targetPlan->getCode(),
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
        $plan = $this->findTargetPlan($targetTier);
        if ($plan instanceof CommercialPlan && trim($plan->getName()) !== '') {
            return $plan->getName();
        }

        return self::TARGET_CONFIG[$targetTier] ?? ucfirst($targetTier);
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

    private function resolveAmountCents(string $currentTier, string $targetTier): ?int
    {
        $currentPlan = $this->resolveCurrentPlan($currentTier);
        $targetPlan = $this->findTargetPlan($targetTier);

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

    private function resolvePriceId(string $targetTier): ?string
    {
        return $this->findTargetPlan($targetTier)?->getStripePriceId();
    }

    private function resolveTargetPlan(string $targetTier): CommercialPlan
    {
        $plan = $this->findTargetPlan($targetTier);
        if ($plan instanceof CommercialPlan) {
            return $plan;
        }

        throw new \RuntimeException(sprintf('Commercial plan "%s" is not available for checkout.', $targetTier));
    }

    private function findTargetPlan(string $targetTier): ?CommercialPlan
    {
        return $this->commercialPlanRepository->findActiveByCode($targetTier);
    }

    private function resolveCurrentPlan(string $currentTier): ?CommercialPlan
    {
        return $this->findTargetPlan($currentTier);
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
