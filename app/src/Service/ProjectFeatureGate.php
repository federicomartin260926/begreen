<?php

namespace App\Service;

use App\Entity\Project;
use App\Enum\CommercialPhase;

final class ProjectFeatureGate
{
    public function __construct(private readonly CommercialPlanResolver $commercialPlanResolver)
    {
    }

    public function getTier(Project $project, CommercialPhase $phase): string
    {
        return $this->commercialPlanResolver->getTierCode($project, $phase);
    }

    public function isBasic(Project $project, CommercialPhase $phase): bool
    {
        return $this->getTier($project, $phase) === 'basic';
    }

    public function isStandard(Project $project, CommercialPhase $phase): bool
    {
        return $this->getTier($project, $phase) === 'standard';
    }

    public function isPro(Project $project, CommercialPhase $phase): bool
    {
        return $this->getTier($project, $phase) === 'pro';
    }

    public function getAllowedScores(Project $project, CommercialPhase $phase): array
    {
        return $this->commercialPlanResolver->getAllowedScores($project, $phase);
    }

    public function getMaxEvidenceCount(Project $project, CommercialPhase $phase): ?int
    {
        return $this->commercialPlanResolver->getMaxEvidenceCount($project, $phase);
    }

    public function getPlanLabel(Project $project, CommercialPhase $phase): string
    {
        return $this->commercialPlanResolver->getPlanLabel($project, $phase);
    }

    public function getPlanDescription(Project $project, CommercialPhase $phase): ?string
    {
        return $this->commercialPlanResolver->getPlanDescription($project, $phase);
    }

    public function hasWatermark(Project $project, CommercialPhase $phase): bool
    {
        return $this->commercialPlanResolver->hasWatermark($project, $phase);
    }

    public function canUseFeature(Project $project, CommercialPhase $phase, string $feature): bool
    {
        return $this->commercialPlanResolver->canUseFeature($project, $phase, $feature);
    }

    public function getUpgradeTarget(Project $project, CommercialPhase $phase, string $feature): ?string
    {
        return $this->commercialPlanResolver->getUpgradeTarget($project, $phase, $feature);
    }

    public function getFeatureState(Project $project, CommercialPhase $phase, string $feature): array
    {
        return $this->commercialPlanResolver->getFeatureState($project, $phase, $feature);
    }
}
