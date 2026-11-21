<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Service\Animation\AnimationConfigurationCatalog;
use InvalidArgumentException;

final readonly class PlanFilterApplicabilityService
{
    public function __construct(
        private MeasureApplicabilityRuleCatalog $ruleCatalog,
        private PlanMeasureGetOrCreate $planMeasureGetOrCreate,
    ) {
    }

    /** @return array<string, bool|string|string[]> */
    public function initialAnswers(Plan $plan, Project $project): array
    {
        if ($plan->getFilterAnswers() !== []) {
            return $plan->getFilterAnswers();
        }

        if ($plan->getProtocol()?->getCode() !== 'be-green-my-animation') {
            return [];
        }

        $configuration = $project->getAnimationConfiguration();
        if ($configuration === null) {
            return [];
        }

        $techniques = $configuration->getTechniques();
        $forcesShooting = array_intersect($techniques, AnimationConfigurationCatalog::SHOOTING_FORCED_TECHNIQUES) !== [];
        $distribution = $project->getDistributionMedia();

        return array_filter([
            'A01' => $configuration->getUsesAi(),
            'A02' => $techniques,
            'A03' => $configuration->getProcessingLevel(),
            'A04' => $forcesShooting ? true : $configuration->getShootingAnswered(),
            'A05' => $configuration->getStructure(),
            'A06' => $configuration->getProcessingInfrastructures(),
            'A07' => $distribution === [] ? null : in_array(AnimationConfigurationCatalog::INTERACTIVE_DISTRIBUTION, $distribution, true),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param array<string, mixed> $rawAnswers
     * @return array<string, bool|string|string[]>
     */
    public function normalizeAnswers(Plan $plan, array $rawAnswers): array
    {
        $normalized = [];
        foreach ($this->ruleCatalog->filtersForProtocol($plan->getProtocol()?->getCode()) as $filter) {
            $code = (string) ($filter['code'] ?? '');
            $type = (string) ($filter['type'] ?? 'boolean');
            $raw = $rawAnswers[$code] ?? null;

            if ($type === 'boolean') {
                if (!in_array($raw, ['0', '1', 0, 1, false, true], true)) {
                    throw new InvalidArgumentException(sprintf('Debes responder el filtro %s.', $code));
                }
                $normalized[$code] = in_array($raw, ['1', 1, true], true);
                continue;
            }

            $allowed = array_column((array) ($filter['options'] ?? []), 'value');
            if ($type === 'multiple') {
                $values = is_array($raw) ? array_values(array_unique(array_map('strval', $raw))) : [];
                if ($values === [] || array_diff($values, $allowed) !== []) {
                    throw new InvalidArgumentException(sprintf('Selecciona al menos una opción válida en %s.', $code));
                }
                $normalized[$code] = array_values(array_filter($allowed, static fn (string $value): bool => in_array($value, $values, true)));
                continue;
            }

            if (!is_string($raw) || !in_array($raw, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Selecciona una opción válida en %s.', $code));
            }
            $normalized[$code] = $raw;
        }

        return $normalized;
    }

    /**
     * @param iterable<int, Measure> $measures
     * @param list<string> $changedFilterCodes
     */
    public function recalculate(Plan $plan, iterable $measures, array $changedFilterCodes = []): void
    {
        $toResolve = [];
        $reasonsByMeasureId = [];
        foreach ($measures as $measure) {
            if (!$measure instanceof Measure || $measure->getId() === null) {
                continue;
            }
            $existing = $this->findPlanMeasure($plan, $measure);
            $rule = $this->ruleCatalog->ruleForMeasure($measure);

            // R08: an edited profile must reopen a prior manual N/A when that
            // measure depends on one of the changed filters. Keep evidence,
            // observations and implementation data intact for reassessment.
            if ($existing?->isApplicable() === false
                && $existing->getApplicabilitySource() === PlanMeasure::APPLICABILITY_MANUAL
                && array_intersect($changedFilterCodes, (array) ($rule['filterCodes'] ?? [])) !== []) {
                $existing->setIsApplicable(null);
            }

            $reasons = $this->automaticExclusionCodes($plan, $measure);
            if ($reasons !== [] || $existing?->isProfileFiltered()) {
                $toResolve[$measure->getId()] = $measure;
                $reasonsByMeasureId[$measure->getId()] = $reasons;
            }
        }

        foreach ($this->planMeasureGetOrCreate->getOrCreateMany($plan, $toResolve) as $measureId => $planMeasure) {
            $reasons = $reasonsByMeasureId[$measureId] ?? [];
            if ($reasons !== []) {
                if ($planMeasure->getApplicabilitySource() === PlanMeasure::APPLICABILITY_BLOCK_SKIP
                    || ($planMeasure->getApplicabilitySource() === PlanMeasure::APPLICABILITY_MANUAL && $planMeasure->isApplicable() === false)) {
                    continue;
                }
                $planMeasure->markAsProfileFiltered();
                continue;
            }

            $planMeasure->restoreAfterProfileFilter();
        }
    }

    /** @return list<string> */
    public function automaticExclusionCodes(Plan $plan, Measure $measure): array
    {
        $rule = $this->ruleCatalog->ruleForMeasure($measure);
        $answers = $plan->getFilterAnswers();
        $reasons = [];
        foreach ((array) ($rule['filterCodes'] ?? []) as $code) {
            if (!array_key_exists($code, $answers)) {
                continue;
            }
            if ($code[0] !== 'A' && $answers[$code] === false) {
                $reasons[] = $code;
                continue;
            }
            if (in_array($code, ['A01', 'A04', 'A07'], true) && $answers[$code] === false) {
                $reasons[] = $code;
                continue;
            }
            if ($code === 'A02' && !$this->matchesAnimationTechnique($measure, $answers[$code])) {
                $reasons[] = $code;
            }
        }

        return array_values(array_unique($reasons));
    }

    private function matchesAnimationTechnique(Measure $measure, mixed $selected): bool
    {
        if (!is_array($selected) || $selected === []) {
            return true;
        }
        $compatibility = $measure->getAnimationMetadata()?->getTechniqueCompatibility() ?? [];
        foreach ($selected as $technique) {
            if (($compatibility[(string) $technique] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function findPlanMeasure(Plan $plan, Measure $measure): ?PlanMeasure
    {
        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure->getMeasure()?->getId() === $measure->getId()) {
                return $planMeasure;
            }
        }

        return null;
    }
}
