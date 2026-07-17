<?php

namespace App\Tests\Support;

use App\Entity\CommercialPlan;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\ProjectSubscriptionRepository;
use App\Service\CommercialPlanResolver;

trait CommercialPlanTestHelpers
{
    /**
     * @return array<string, CommercialPlan>
     */
    private function makeDefaultCommercialPlans(): array
    {
        return [
            'basic' => $this->makeCommercialPlan('basic'),
            'standard' => $this->makeCommercialPlan('standard'),
            'pro' => $this->makeCommercialPlan('pro'),
            'implementation_basic' => $this->makeCommercialPlan('basic', ['phase' => CommercialPhase::IMPLEMENTATION]),
            'implementation_standard' => $this->makeCommercialPlan('standard', ['phase' => CommercialPhase::IMPLEMENTATION]),
            'implementation_pro' => $this->makeCommercialPlan('pro', ['phase' => CommercialPhase::IMPLEMENTATION]),
        ];
    }

    private function makeCommercialPlanResolver(array $plans = [], ?ProjectSubscriptionRepository $subscriptionRepository = null): CommercialPlanResolver
    {
        $planRepository = $this->createMock(CommercialPlanRepository::class);
        $indexedPlans = [];
        foreach ($plans as $plan) {
            if ($plan instanceof CommercialPlan) {
                $indexedPlans[$plan->getPhase()->value . ':' . strtolower($plan->getCode())] = $plan;
            }
        }
        $planRepository->method('findActiveByPhaseAndCode')->willReturnCallback(
            static function (CommercialPhase $phase, string $code) use ($indexedPlans): ?CommercialPlan {
                $normalized = strtolower(trim($code));

                return $indexedPlans[$phase->value . ':' . $normalized] ?? null;
            }
        );
        $planRepository->method('findActiveOrdered')->willReturnCallback(
            static function () use ($indexedPlans): array {
                $plans = array_values($indexedPlans);
                usort($plans, static function (CommercialPlan $left, CommercialPlan $right): int {
                    $phaseCompare = $left->getPhase()->value <=> $right->getPhase()->value;
                    if ($phaseCompare !== 0) {
                        return $phaseCompare;
                    }

                    $sortCompare = $left->getSortOrder() <=> $right->getSortOrder();
                    if ($sortCompare !== 0) {
                        return $sortCompare;
                    }

                    return 0;
                });

                return $plans;
            }
        );

        $subscriptionRepository ??= $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProjectAndPhase')->willReturnCallback(
            static fn (Project $project, CommercialPhase $phase): ?ProjectSubscription => null
        );

        return new CommercialPlanResolver($planRepository, $subscriptionRepository);
    }

    private function makeProjectFeatureGate(array $plans = [], ?ProjectSubscriptionRepository $subscriptionRepository = null): \App\Service\ProjectFeatureGate
    {
        return new \App\Service\ProjectFeatureGate(
            $this->makeCommercialPlanResolver($plans, $subscriptionRepository)
        );
    }

    private function makeProjectWithTier(string $tier): Project
    {
        return $this->makeProjectWithTiers($tier, $tier);
    }

    private function makeProjectWithTiers(string $elaborationTier, string $implementationTier): Project
    {
        $project = new Project();
        foreach ([
            [CommercialPhase::ELABORATION, $elaborationTier],
            [CommercialPhase::IMPLEMENTATION, $implementationTier],
        ] as [$phase, $tier]) {
            $subscription = (new ProjectSubscription())
                ->setPhase($phase)
                ->setTier($tier)
                ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                ->setSource(ProjectSubscription::SOURCE_MANUAL);

            $project->addSubscription($subscription);
        }

        return $project;
    }

    private function makeCommercialPlan(string $code, array $overrides = []): CommercialPlan
    {
        $phase = $overrides['phase'] ?? CommercialPhase::ELABORATION;
        $definition = $phase === CommercialPhase::IMPLEMENTATION
            ? $this->defaultImplementationCommercialPlanDefinition($code)
            : $this->defaultCommercialPlanDefinition($code);
        foreach ($overrides as $key => $value) {
            $definition[$key] = $value;
        }

        return (new CommercialPlan())
            ->setPhase($definition['phase'] ?? CommercialPhase::ELABORATION)
            ->setCode($definition['code'])
            ->setName($definition['name'])
            ->setDescription($definition['description'])
            ->setPriceAmount($definition['priceAmount'])
            ->setPriceCurrency($definition['priceCurrency'])
            ->setStripePriceId($definition['stripePriceId'])
            ->setStripeUpgradeFromStandardPriceId($definition['stripeUpgradeFromStandardPriceId'])
            ->setMaxEvidenceCount($definition['maxEvidenceCount'])
            ->setWatermarkEnabled($definition['watermarkEnabled'])
            ->setActive($definition['active'])
            ->setSortOrder($definition['sortOrder'])
            ->setFeatures($definition['features']);
    }

    private function defaultCommercialPlanDefinition(string $code): array
    {
        return match (strtolower(trim($code))) {
            'standard' => [
                'phase' => CommercialPhase::ELABORATION,
                'code' => 'standard',
                'name' => 'Standard',
                'description' => 'Incluye PDF agrupado por departamentos, marca de agua desactivada y evidencias ilimitadas para gestionar proyectos con más detalle.',
                'priceAmount' => 9900,
                'priceCurrency' => 'EUR',
                'stripePriceId' => null,
                'stripeUpgradeFromStandardPriceId' => null,
                'maxEvidenceCount' => null,
                'watermarkEnabled' => false,
                'active' => true,
                'sortOrder' => 2,
                'features' => [
                    'allowed_scores' => [3, 4, 5],
                    'sustainability_plan.unified_pdf' => true,
                    'sustainability_plan.evidence_upload' => true,
                    'sustainability_plan.watermark_free_pdf' => true,
                    'sustainability_plan.department_pdf' => true,
                    'sustainability_plan.export.department_pdf' => true,
                    'sustainability_plan.history' => true,
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
                    'sustainability_plan.custom_measures' => true,
                    'sustainability_plan.validation_summary' => false,
                    'sustainability_plan.branding' => false,
                ],
            ],
            'pro' => [
                'phase' => CommercialPhase::ELABORATION,
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Incluye exportaciones avanzadas por categorías, departamentos, áreas de impacto, triple balance y ODS, además de campos colaborativos y medidas custom.',
                'priceAmount' => 19900,
                'priceCurrency' => 'EUR',
                'stripePriceId' => null,
                'stripeUpgradeFromStandardPriceId' => 'price_1TedI4QbEObZty5pDwVrk5PS',
                'maxEvidenceCount' => null,
                'watermarkEnabled' => false,
                'active' => true,
                'sortOrder' => 3,
                'features' => [
                    'allowed_scores' => [1, 2, 3, 4, 5],
                    'sustainability_plan.unified_pdf' => true,
                    'sustainability_plan.evidence_upload' => true,
                    'sustainability_plan.watermark_free_pdf' => true,
                    'sustainability_plan.department_pdf' => true,
                    'sustainability_plan.export.department_pdf' => true,
                    'sustainability_plan.history' => true,
                    'sustainability_plan.advanced_exports' => true,
                    'sustainability_plan.export.category' => true,
                    'sustainability_plan.export.department' => true,
                    'sustainability_plan.export.impact_area' => true,
                    'sustainability_plan.export.triple_balance' => true,
                    'sustainability_plan.export.ods' => true,
                    'sustainability_plan.export.excel' => true,
                    'sustainability_plan.public_comments' => true,
                    'sustainability_plan.internal_notes' => true,
                    'sustainability_plan.responsibles' => true,
                    'sustainability_plan.checklist' => true,
                    'sustainability_plan.custom_measures' => true,
                    'sustainability_plan.validation_summary' => true,
                    'sustainability_plan.branding' => true,
                ],
            ],
            default => [
                'phase' => CommercialPhase::ELABORATION,
                'code' => 'basic',
                'name' => 'Basic',
                'description' => 'Plan gratuito para empezar, con PDF unificado, marca de agua activa y límite de 10 evidencias por proyecto.',
                'priceAmount' => 0,
                'priceCurrency' => 'EUR',
                'stripePriceId' => null,
                'stripeUpgradeFromStandardPriceId' => null,
                'maxEvidenceCount' => 10,
                'watermarkEnabled' => true,
                'active' => true,
                'sortOrder' => 1,
                'features' => [
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
                ],
            ],
        };
    }

    private function defaultImplementationCommercialPlanDefinition(string $code): array
    {
        $definition = $this->defaultCommercialPlanDefinition($code);
        $definition['phase'] = CommercialPhase::IMPLEMENTATION;
        $definition['features']['allowed_scores'] = [4, 5];
        $definition['features']['sustainability_plan.custom_measures'] = false;
        $definition['features']['sustainability_plan.public_comments'] = false;

        if ($definition['code'] === 'basic') {
            return $definition;
        }

        $definition['maxEvidenceCount'] = null;
        $definition['watermarkEnabled'] = false;
        $definition['features']['sustainability_plan.watermark_free_pdf'] = true;
        $definition['features']['sustainability_plan.department_pdf'] = true;
        $definition['features']['sustainability_plan.export.department_pdf'] = true;
        $definition['features']['sustainability_plan.history'] = true;
        $definition['features']['sustainability_plan.internal_notes'] = true;
        $definition['features']['sustainability_plan.responsibles'] = true;
        $definition['features']['sustainability_plan.checklist'] = true;

        if ($definition['code'] === 'standard') {
            return $definition;
        }

        $definition['features']['sustainability_plan.advanced_exports'] = true;
        $definition['features']['sustainability_plan.export.category'] = true;
        $definition['features']['sustainability_plan.export.department'] = true;
        $definition['features']['sustainability_plan.export.impact_area'] = true;
        $definition['features']['sustainability_plan.export.triple_balance'] = true;
        $definition['features']['sustainability_plan.export.ods'] = true;
        $definition['features']['sustainability_plan.export.excel'] = true;
        $definition['features']['sustainability_plan.validation_summary'] = true;
        $definition['features']['sustainability_plan.branding'] = true;

        return $definition;
    }
}
