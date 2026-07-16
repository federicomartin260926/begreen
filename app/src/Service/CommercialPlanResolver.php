<?php

namespace App\Service;

use App\Entity\CommercialPlan;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\ProjectSubscriptionRepository;
use LogicException;

final class CommercialPlanResolver
{
    private const BASIC_CODE = 'basic';
    private const STANDARD_CODE = 'standard';
    private const PRO_CODE = 'pro';

    public function __construct(
        private readonly CommercialPlanRepository $commercialPlanRepository,
        private readonly ProjectSubscriptionRepository $subscriptionRepository,
    ) {
    }

    public function getSubscription(Project $project, CommercialPhase $phase): ?ProjectSubscription
    {
        return $project->getSubscriptionForPhase($phase)
            ?? $this->subscriptionRepository->findOneByProjectAndPhase($project, $phase);
    }

    public function getTierCode(Project $project, CommercialPhase $phase): string
    {
        $subscription = $this->getSubscription($project, $phase);

        if (!$subscription instanceof ProjectSubscription) {
            return self::BASIC_CODE;
        }

        return $this->normalizeCode($subscription->getTier());
    }

    public function getPlanForProject(Project $project, CommercialPhase $phase): CommercialPlan
    {
        return $this->getPlanByCode($phase, $this->getTierCode($project, $phase));
    }

    public function getPlanByCode(CommercialPhase $phase, string $code): CommercialPlan
    {
        $normalizedCode = $this->normalizeCode($code);
        $plan = $this->commercialPlanRepository->findActiveByPhaseAndCode($phase, $normalizedCode);

        if (!$plan instanceof CommercialPlan) {
            throw new LogicException(sprintf(
                'Missing active commercial plan for phase "%s" and code "%s".',
                $phase->value,
                $normalizedCode
            ));
        }

        return $plan;
    }

    /**
     * @return int[]
     */
    public function getAllowedScores(Project $project, CommercialPhase $phase): array
    {
        return $this->getPlanForProject($project, $phase)->getAllowedScores();
    }

    public function getMaxEvidenceCount(Project $project, CommercialPhase $phase): ?int
    {
        return $this->getPlanForProject($project, $phase)->getMaxEvidenceCount();
    }

    public function hasWatermark(Project $project, CommercialPhase $phase): bool
    {
        return $this->getPlanForProject($project, $phase)->isWatermarkEnabled();
    }

    public function canUseFeature(Project $project, CommercialPhase $phase, string $feature): bool
    {
        return (bool) $this->getPlanForProject($project, $phase)->getFeature($feature, false);
    }

    public function getUpgradeTarget(Project $project, CommercialPhase $phase, string $feature): ?string
    {
        if ($this->canUseFeature($project, $phase, $feature)) {
            return null;
        }

        foreach ($this->getActivePlansOrdered($phase) as $plan) {
            if ((bool) $plan->getFeature($feature, false)) {
                return $plan->getCode();
            }
        }

        return null;
    }

    public function getFeatureState(Project $project, CommercialPhase $phase, string $feature): array
    {
        $requiredTier = $this->getUpgradeTarget($project, $phase, $feature);
        $enabled = $this->canUseFeature($project, $phase, $feature);
        $visible = $this->isFeatureKnown($phase, $feature);

        return [
            'visible' => $visible,
            'enabled' => $enabled,
            'requiredTier' => $requiredTier,
            'reason' => $enabled || $requiredTier === null
                ? null
                : sprintf('Disponible en %s', $this->getPlanByCode($phase, $requiredTier)->getName()),
        ];
    }

    public function getPlanLabel(Project $project, CommercialPhase $phase): string
    {
        return $this->getPlanForProject($project, $phase)->getName();
    }

    public function getPlanDescription(Project $project, CommercialPhase $phase): ?string
    {
        return $this->getPlanForProject($project, $phase)->getDescription();
    }

    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    /**
     * @return CommercialPlan[]
     */
    private function getActivePlansOrdered(CommercialPhase $phase): array
    {
        return array_values(array_filter(
            $this->commercialPlanRepository->findActiveOrdered(),
            static fn (CommercialPlan $plan): bool => $plan->getPhase() === $phase
        ));
    }

    private function isFeatureKnown(CommercialPhase $phase, string $feature): bool
    {
        foreach ($this->getActivePlansOrdered($phase) as $plan) {
            if ($plan->hasFeature($feature)) {
                return true;
            }
        }

        return false;
    }
}
