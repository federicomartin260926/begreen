<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Repository\ProjectSubscriptionRepository;

final class ProjectFeatureGate
{
    private const TIER_RANK = [
        ProjectSubscription::TIER_BASIC => 1,
        ProjectSubscription::TIER_STANDARD => 2,
        ProjectSubscription::TIER_PRO => 3,
    ];

    private const FEATURE_REQUIREMENTS = [
        'sustainability_plan.unified_pdf' => ProjectSubscription::TIER_BASIC,
        'sustainability_plan.evidence_upload' => ProjectSubscription::TIER_BASIC,
        'sustainability_plan.watermark_free_pdf' => ProjectSubscription::TIER_STANDARD,
        'sustainability_plan.department_pdf' => ProjectSubscription::TIER_STANDARD,
        'sustainability_plan.history' => ProjectSubscription::TIER_STANDARD,
        'sustainability_plan.advanced_exports' => ProjectSubscription::TIER_PRO,
        'sustainability_plan.custom_comments' => ProjectSubscription::TIER_PRO,
        'sustainability_plan.internal_notes' => ProjectSubscription::TIER_PRO,
        'sustainability_plan.responsibles' => ProjectSubscription::TIER_PRO,
        'sustainability_plan.checklist' => ProjectSubscription::TIER_PRO,
        'sustainability_plan.custom_measures' => ProjectSubscription::TIER_PRO,
        'sustainability_plan.branding' => ProjectSubscription::TIER_PRO,
    ];

    public function __construct(private readonly ProjectSubscriptionRepository $subscriptionRepository)
    {
    }

    public function getSubscription(Project $project): ?ProjectSubscription
    {
        return $project->getSubscription() ?? $this->subscriptionRepository->findOneByProject($project);
    }

    public function getTier(Project $project): string
    {
        return $this->getSubscription($project)?->getTier() ?? ProjectSubscription::TIER_BASIC;
    }

    public function isBasic(Project $project): bool
    {
        return $this->getTier($project) === ProjectSubscription::TIER_BASIC;
    }

    public function isStandard(Project $project): bool
    {
        return $this->getTier($project) === ProjectSubscription::TIER_STANDARD;
    }

    public function isPro(Project $project): bool
    {
        return $this->getTier($project) === ProjectSubscription::TIER_PRO;
    }

    public function getAllowedScores(Project $project): array
    {
        return match ($this->getTier($project)) {
            ProjectSubscription::TIER_PRO => [5, 4, 3, 2, 1],
            ProjectSubscription::TIER_STANDARD => [5, 4, 3],
            default => [5, 4],
        };
    }

    public function getMaxEvidenceCount(Project $project): ?int
    {
        return $this->isBasic($project) ? 10 : null;
    }

    public function hasWatermark(Project $project): bool
    {
        return $this->isBasic($project);
    }

    public function canUseFeature(Project $project, string $feature): bool
    {
        $requiredTier = self::FEATURE_REQUIREMENTS[$feature] ?? null;
        if ($requiredTier === null) {
            return false;
        }

        return $this->tierRank($this->getTier($project)) >= $this->tierRank($requiredTier);
    }

    public function getUpgradeTarget(Project $project, string $feature): ?string
    {
        $requiredTier = self::FEATURE_REQUIREMENTS[$feature] ?? null;
        if ($requiredTier === null) {
            return null;
        }

        return $this->canUseFeature($project, $feature) ? null : $requiredTier;
    }

    public function getFeatureState(Project $project, string $feature): array
    {
        $requiredTier = self::FEATURE_REQUIREMENTS[$feature] ?? null;
        $enabled = $requiredTier !== null && $this->canUseFeature($project, $feature);

        return [
            'visible' => $requiredTier !== null,
            'enabled' => $enabled,
            'requiredTier' => $requiredTier,
            'reason' => $enabled || $requiredTier === null
                ? null
                : sprintf('Disponible en %s', $this->tierLabel($requiredTier)),
        ];
    }

    private function tierRank(string $tier): int
    {
        return self::TIER_RANK[$tier] ?? 1;
    }

    private function tierLabel(string $tier): string
    {
        return match ($tier) {
            ProjectSubscription::TIER_STANDARD => 'Standard',
            ProjectSubscription::TIER_PRO => 'Pro',
            default => 'Basic',
        };
    }
}
