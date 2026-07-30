<?php

namespace App\Service;

use App\Entity\CommercialPlan;
use App\Entity\Plan;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CommercialPlanComparisonBuilder
{
    public function __construct(
        private readonly CommercialPlanRepository $commercialPlanRepository,
        private readonly MeasureRepository $measureRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, array{targetTier?: string, amountCents?: int|null, priceId?: string|null}> $availableUpgradeTargets
     *
     * @return array{phase: string, plans: array<string, array<string, mixed>>, rows: array<int, array<string, mixed>>}
     */
    public function build(
        CommercialPhase $phase,
        string $currentTier,
        ?Plan $projectPlan,
        array $availableUpgradeTargets,
    ): array {
        $currentTier = strtolower(trim($currentTier));
        $upgradeTargets = [];
        foreach ($availableUpgradeTargets as $key => $target) {
            if (!is_array($target)) {
                continue;
            }

            $targetTier = strtolower(trim((string) ($target['targetTier'] ?? $key)));
            if ($targetTier !== '') {
                $upgradeTargets[$targetTier] = $target;
            }
        }

        $plans = [];
        foreach ($this->commercialPlanRepository->findByPhaseOrdered($phase) as $commercialPlan) {
            $code = strtolower(trim($commercialPlan->getCode()));
            if (!in_array($code, [
                ProjectSubscription::TIER_BASIC,
                ProjectSubscription::TIER_STANDARD,
                ProjectSubscription::TIER_PRO,
            ], true)) {
                continue;
            }

            $upgradeTarget = $upgradeTargets[$code] ?? null;
            $upgradeAmount = is_array($upgradeTarget) && isset($upgradeTarget['amountCents']) && is_int($upgradeTarget['amountCents'])
                ? $upgradeTarget['amountCents']
                : null;
            $displayAmount = $upgradeAmount ?? $commercialPlan->getPriceAmount();
            $features = $commercialPlan->getFeatures();

            $plans[$code] = [
                'name' => $commercialPlan->getName(),
                'description' => $commercialPlan->getDescription(),
                'code' => $code,
                'priceAmount' => $displayAmount,
                'basePriceAmount' => $commercialPlan->getPriceAmount(),
                'priceCurrency' => $commercialPlan->getPriceCurrency(),
                'priceLabel' => $this->formatPrice($displayAmount, $commercialPlan->getPriceCurrency()),
                'sortOrder' => $commercialPlan->getSortOrder(),
                'active' => $commercialPlan->isActive(),
                'maxEvidenceCount' => $commercialPlan->getMaxEvidenceCount(),
                'watermarkEnabled' => $commercialPlan->isWatermarkEnabled(),
                'allowedScores' => $commercialPlan->getAllowedScores(),
                'features' => $features,
                'current' => $code === $currentTier,
                'upgrade' => $commercialPlan->isActive() && is_array($upgradeTarget) && $upgradeAmount !== null
                    ? [
                        'targetTier' => $code,
                        'priceAmount' => $upgradeAmount,
                        'priceCurrency' => $commercialPlan->getPriceCurrency(),
                        'priceLabel' => $this->formatPrice($upgradeAmount, $commercialPlan->getPriceCurrency()),
                    ]
                    : null,
                'entity' => $commercialPlan,
            ];
        }

        return [
            'phase' => $phase->value,
            'plans' => $this->withoutEntities($plans),
            'rows' => $phase === CommercialPhase::IMPLEMENTATION
                ? $this->buildImplementationRows($plans)
                : $this->buildElaborationRows($plans, $projectPlan),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $plans
     * @return array<int, array<string, mixed>>
     */
    private function buildElaborationRows(array $plans, ?Plan $projectPlan): array
    {
        return [
            $this->row('measure_count', 'backend.commercial_plan_comparison.elaboration.measure_count', 'derived:allowed_scores', $this->mapPlans(
                $plans,
                fn (CommercialPlan $plan): array => $this->value(
                    $projectPlan?->getProtocol()
                        ? (string) $this->measureRepository->countCatalogMeasuresForProtocol($projectPlan->getProtocol(), $plan->getAllowedScores())
                        : $this->formatScores($plan->getAllowedScores())
                )
            )),
            $this->staticRow('critical', 'backend.commercial_plan_comparison.elaboration.critical', ['basic' => 'yes', 'standard' => 'yes', 'pro' => 'yes']),
            $this->staticRow('observations', 'backend.commercial_plan_comparison.elaboration.observations', ['basic' => 'yes', 'standard' => 'yes', 'pro' => 'yes']),
            $this->row('pdf', 'backend.commercial_plan_comparison.elaboration.pdf', 'derived:department_pdf+advanced_exports', $this->mapPlans(
                $plans,
                fn (CommercialPlan $plan): array => $this->value($this->translator->trans(
                    (bool) $plan->getFeature('sustainability_plan.advanced_exports', false)
                        ? 'backend.commercial_plan_comparison.values.elaboration.pdf_pro'
                        : ((bool) $plan->getFeature('sustainability_plan.department_pdf', false)
                            ? 'backend.commercial_plan_comparison.values.elaboration.pdf_standard'
                            : 'backend.commercial_plan_comparison.values.elaboration.pdf_basic')
                ))
            )),
            $this->row('pdf_branding', 'backend.commercial_plan_comparison.elaboration.pdf_branding', 'derived:watermarkEnabled+branding', $this->mapPlans(
                $plans,
                fn (CommercialPlan $plan): array => (bool) $plan->getFeature('sustainability_plan.branding', false)
                    ? $this->booleanValue(true)
                    : ($plan->isWatermarkEnabled()
                        ? $this->value($this->translator->trans('backend.commercial_plan_comparison.values.elaboration.branding_basic'))
                        : $this->booleanValue(false))
            )),
            $this->staticRow('commitment_levels', 'backend.commercial_plan_comparison.elaboration.commitment_levels', ['basic' => 'yes', 'standard' => 'yes', 'pro' => 'yes']),
            $this->staticRow('concurrent_projects', 'backend.commercial_plan_comparison.elaboration.concurrent_projects', ['basic' => 'unlimited', 'standard' => 'unlimited', 'pro' => 'unlimited']),
            $this->featureRow('custom_measure', 'backend.commercial_plan_comparison.elaboration.custom_measure', $plans, 'sustainability_plan.custom_measures'),
            $this->staticRow('recover_measure', 'backend.commercial_plan_comparison.elaboration.recover_measure', ['basic' => 'no', 'standard' => 'yes', 'pro' => 'yes']),
            $this->featureRow('email_pdf', 'backend.commercial_plan_comparison.elaboration.email_pdf', $plans, 'sustainability_plan.export.email'),
            $this->featureRow('history', 'backend.commercial_plan_comparison.elaboration.history', $plans, 'sustainability_plan.history'),
            $this->staticRow('level_alerts', 'backend.commercial_plan_comparison.elaboration.level_alerts', ['basic' => 'no', 'standard' => 'email', 'pro' => 'email_in_app']),
            $this->featureRow('excel', 'backend.commercial_plan_comparison.elaboration.excel', $plans, 'sustainability_plan.export.excel'),
            $this->staticRow('selection_percentage', 'backend.commercial_plan_comparison.elaboration.selection_percentage', ['basic' => 'no', 'standard' => 'no', 'pro' => 'yes']),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $plans
     * @return array<int, array<string, mixed>>
     */
    private function buildImplementationRows(array $plans): array
    {
        return [
            $this->staticRow('executed_users', 'backend.commercial_plan_comparison.implementation.executed_users', ['basic' => 'implementation.users_basic', 'standard' => 'implementation.users_standard', 'pro' => 'implementation.users_pro']),
            $this->row('evidence', 'backend.commercial_plan_comparison.implementation.evidence', 'limit:maxEvidenceCount+feature:evidence_upload', $this->mapPlans(
                $plans,
                fn (CommercialPlan $plan): array => !(bool) $plan->getFeature('sustainability_plan.evidence_upload', false)
                    ? $this->booleanValue(false)
                    : $this->value(
                        $plan->getMaxEvidenceCount() === null
                            ? $this->translator->trans('backend.commercial_plan_comparison.values.unlimited_feminine')
                            : (string) $plan->getMaxEvidenceCount(),
                        true
                    )
            )),
            $this->staticRow('progress', 'backend.commercial_plan_comparison.implementation.progress', ['basic' => 'global_percentage', 'standard' => 'global_percentage', 'pro' => 'global_percentage']),
            $this->staticRow('commitment_levels', 'backend.commercial_plan_comparison.implementation.commitment_levels', ['basic' => 'yes', 'standard' => 'yes', 'pro' => 'yes']),
            $this->staticRow('concurrent_projects', 'backend.commercial_plan_comparison.implementation.concurrent_projects', ['basic' => 'unlimited', 'standard' => 'unlimited', 'pro' => 'unlimited']),
            $this->staticRow('observations', 'backend.commercial_plan_comparison.implementation.observations', ['basic' => 'yes', 'standard' => 'yes', 'pro' => 'yes']),
            $this->featureRow('internal_notes', 'backend.commercial_plans.form.internal_notes', $plans, 'sustainability_plan.internal_notes'),
            $this->featureRow('responsibles', 'backend.commercial_plans.form.responsibles', $plans, 'sustainability_plan.responsibles'),
            $this->featureRow('checklist', 'backend.commercial_plans.form.checklist', $plans, 'sustainability_plan.checklist'),
            $this->featureRow('department_pdf', 'backend.commercial_plans.form.pdf_by_departments', $plans, 'sustainability_plan.department_pdf'),
            $this->featureRow('total_export', 'backend.commercial_plan_comparison.implementation.total_export', $plans, 'sustainability_plan.advanced_exports'),
            $this->featureRow('validation_summary', 'backend.commercial_plans.form.validation_summary', $plans, 'sustainability_plan.validation_summary'),
            $this->featureRow('branding', 'backend.commercial_plans.form.branding', $plans, 'sustainability_plan.branding'),
            $this->staticRow('level_alerts', 'backend.commercial_plan_comparison.implementation.level_alerts', ['basic' => 'no', 'standard' => 'email', 'pro' => 'email_in_app']),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $plans
     */
    private function featureRow(string $id, string $labelKey, array $plans, string $feature): array
    {
        return $this->row($id, $labelKey, 'feature:'.$feature, $this->mapPlans(
            $plans,
            fn (CommercialPlan $plan): array => $this->booleanValue((bool) $plan->getFeature($feature, false))
        ));
    }

    /**
     * @param array<string, string> $valueKeys
     */
    private function staticRow(string $id, string $labelKey, array $valueKeys): array
    {
        $values = [];
        foreach ($valueKeys as $code => $valueKey) {
            $enabled = $valueKey === 'no' ? false : ($valueKey === 'yes' ? true : null);
            $values[$code] = $this->value(
                $this->translator->trans('backend.commercial_plan_comparison.values.'.$valueKey),
                $enabled
            );
        }

        return $this->row($id, $labelKey, 'static', $values);
    }

    /**
     * @param array<string, array{label: string, enabled: bool|null}> $values
     */
    private function row(string $id, string $labelKey, string $source, array $values): array
    {
        return [
            'id' => $id,
            'label' => $this->translator->trans($labelKey),
            'source' => $source,
            'values' => $values,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $plans
     * @return array<string, array{label: string, enabled: bool|null}>
     */
    private function mapPlans(array $plans, callable $resolver): array
    {
        $values = [];
        foreach ($plans as $code => $data) {
            $plan = $data['entity'] ?? null;
            if ($plan instanceof CommercialPlan) {
                $values[$code] = $resolver($plan);
            }
        }

        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $plans
     * @return array<string, array<string, mixed>>
     */
    private function withoutEntities(array $plans): array
    {
        foreach ($plans as &$plan) {
            unset($plan['entity']);
        }
        unset($plan);

        return $plans;
    }

    /** @return array{label: string, enabled: bool|null} */
    private function booleanValue(bool $enabled): array
    {
        return $this->value(
            $this->translator->trans('backend.commercial_plan_comparison.values.'.($enabled ? 'yes' : 'no')),
            $enabled
        );
    }

    /** @return array{label: string, enabled: bool|null} */
    private function value(string $label, ?bool $enabled = null): array
    {
        return ['label' => $label, 'enabled' => $enabled];
    }

    /** @param int[] $scores */
    private function formatScores(array $scores): string
    {
        sort($scores);

        return $scores === [] ? $this->translator->trans('backend.common.placeholder') : implode(', ', $scores);
    }

    private function formatPrice(?int $priceAmount, string $currency): string
    {
        if ($priceAmount === null) {
            return $this->translator->trans('backend.common.placeholder');
        }

        if ($priceAmount === 0) {
            return $this->translator->trans('backend.commercial_plan_comparison.free');
        }

        $currency = strtoupper(trim($currency));

        return sprintf(
            '%s %s',
            number_format($priceAmount / 100, 2, ',', '.'),
            $currency === 'EUR' ? '€' : $currency
        );
    }
}
