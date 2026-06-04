<?php

namespace App\Service;

use App\Entity\CommercialPlan;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\CommercialPlanRepository;
use App\Repository\ProjectSubscriptionRepository;

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

    public function getSubscription(Project $project): ?ProjectSubscription
    {
        return $project->getSubscription() ?? $this->subscriptionRepository->findOneByProject($project);
    }

    public function getTierCode(Project $project): string
    {
        return $this->normalizeCode($this->getSubscription($project)?->getTier());
    }

    public function getPlanForProject(Project $project): CommercialPlan
    {
        return $this->getPlanByCode($this->getTierCode($project));
    }

    public function getPlanByCode(string $code): CommercialPlan
    {
        $normalizedCode = $this->normalizeCode($code);
        $plan = $this->commercialPlanRepository->findActiveByCode($normalizedCode);
        if ($plan instanceof CommercialPlan) {
            return $plan;
        }

        if ($normalizedCode !== self::BASIC_CODE) {
            $basicPlan = $this->commercialPlanRepository->findActiveByCode(self::BASIC_CODE);
            if ($basicPlan instanceof CommercialPlan) {
                return $basicPlan;
            }
        }

        return $this->createFallbackBasicPlan();
    }

    /**
     * @return int[]
     */
    public function getAllowedScores(Project $project): array
    {
        return $this->getPlanForProject($project)->getAllowedScores();
    }

    public function getMaxEvidenceCount(Project $project): ?int
    {
        return $this->getPlanForProject($project)->getMaxEvidenceCount();
    }

    public function hasWatermark(Project $project): bool
    {
        return $this->getPlanForProject($project)->isWatermarkEnabled();
    }

    public function canUseFeature(Project $project, string $feature): bool
    {
        return (bool) $this->getPlanForProject($project)->getFeature($feature, false);
    }

    public function getUpgradeTarget(Project $project, string $feature): ?string
    {
        if ($this->canUseFeature($project, $feature)) {
            return null;
        }

        foreach ($this->getActivePlansOrdered() as $plan) {
            if ((bool) $plan->getFeature($feature, false)) {
                return $plan->getCode();
            }
        }

        return null;
    }

    public function getFeatureState(Project $project, string $feature): array
    {
        $requiredTier = $this->getUpgradeTarget($project, $feature);
        $enabled = $this->canUseFeature($project, $feature);
        $visible = $this->isFeatureKnown($feature);

        return [
            'visible' => $visible,
            'enabled' => $enabled,
            'requiredTier' => $requiredTier,
            'reason' => $enabled || $requiredTier === null
                ? null
                : sprintf('Disponible en %s', $this->getPlanByCode($requiredTier)->getName()),
        ];
    }

    public function getPlanLabel(Project $project): string
    {
        return $this->getPlanForProject($project)->getName();
    }

    public function getPlanDescription(Project $project): ?string
    {
        return $this->getPlanForProject($project)->getDescription();
    }

    private function normalizeCode(?string $code): string
    {
        $normalized = strtolower(trim((string) $code));

        return match ($normalized) {
            self::STANDARD_CODE, self::PRO_CODE => $normalized,
            default => self::BASIC_CODE,
        };
    }

    /**
     * @return CommercialPlan[]
     */
    private function getActivePlansOrdered(): array
    {
        return $this->commercialPlanRepository->findActiveOrdered();
    }

    private function isFeatureKnown(string $feature): bool
    {
        foreach ($this->getActivePlansOrdered() as $plan) {
            if ($plan->hasFeature($feature)) {
                return true;
            }
        }

        return $this->createFallbackBasicPlan()->hasFeature($feature);
    }

    private function createFallbackBasicPlan(): CommercialPlan
    {
        return (new CommercialPlan())
            ->setCode(self::BASIC_CODE)
            ->setName('Basic')
            ->setDescription(null)
            ->setPriceAmount(0)
            ->setPriceCurrency('EUR')
            ->setMaxEvidenceCount(10)
            ->setWatermarkEnabled(true)
            ->setActive(true)
            ->setSortOrder(1)
            ->setFeatures([
                'allowed_scores' => [4, 5],
                'sustainability_plan.unified_pdf' => true,
                'sustainability_plan.evidence_upload' => true,
                'sustainability_plan.watermark_free_pdf' => false,
                'sustainability_plan.department_pdf' => false,
                'sustainability_plan.export.department_pdf' => false,
                'sustainability_plan.history' => false,
                'sustainability_plan.advanced_exports' => false,
                'sustainability_plan.export.category' => false,
                'sustainability_plan.export.department' => false,
                'sustainability_plan.export.impact_area' => false,
                'sustainability_plan.export.triple_balance' => false,
                'sustainability_plan.export.ods' => false,
                'sustainability_plan.export.excel' => false,
                'sustainability_plan.public_comments' => false,
                'sustainability_plan.internal_notes' => false,
                'sustainability_plan.responsibles' => false,
                'sustainability_plan.checklist' => false,
                'sustainability_plan.custom_measures' => false,
                'sustainability_plan.validation_summary' => false,
                'sustainability_plan.branding' => false,
            ]);
    }
}
