<?php

namespace App\Service;

use App\Entity\Project;

final class ProjectFeatureGate
{
    public function __construct(private readonly CommercialPlanResolver $commercialPlanResolver)
    {
    }

    public function getSubscription(Project $project): ?\App\Entity\ProjectSubscription
    {
        return $this->commercialPlanResolver->getSubscription($project);
    }

    public function getTier(Project $project): string
    {
        return $this->commercialPlanResolver->getTierCode($project);
    }

    public function isBasic(Project $project): bool
    {
        return $this->getTier($project) === 'basic';
    }

    public function isStandard(Project $project): bool
    {
        return $this->getTier($project) === 'standard';
    }

    public function isPro(Project $project): bool
    {
        return $this->getTier($project) === 'pro';
    }

    public function getAllowedScores(Project $project): array
    {
        return $this->commercialPlanResolver->getAllowedScores($project);
    }

    public function getMaxEvidenceCount(Project $project): ?int
    {
        return $this->commercialPlanResolver->getMaxEvidenceCount($project);
    }

    public function getPlanLabel(Project $project): string
    {
        return $this->commercialPlanResolver->getPlanLabel($project);
    }

    public function getPlanDescription(Project $project): ?string
    {
        return $this->commercialPlanResolver->getPlanDescription($project);
    }

    public function hasWatermark(Project $project): bool
    {
        return $this->commercialPlanResolver->hasWatermark($project);
    }

    public function canUseFeature(Project $project, string $feature): bool
    {
        return $this->commercialPlanResolver->canUseFeature($project, $feature);
    }

    public function getUpgradeTarget(Project $project, string $feature): ?string
    {
        return $this->commercialPlanResolver->getUpgradeTarget($project, $feature);
    }

    public function getFeatureState(Project $project, string $feature): array
    {
        return $this->commercialPlanResolver->getFeatureState($project, $feature);
    }
}
