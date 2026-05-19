<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SustainabilityPlanGroupingService
{
    private const GROUPING_LABELS = [
        'category' => 'backend.plan.exports.grouping.category',
        'department' => 'backend.plan.exports.grouping.department',
        'impact_area' => 'backend.plan.exports.grouping.impact_area',
        'triple_balance' => 'backend.plan.exports.grouping.triple_balance',
        'ods' => 'backend.plan.exports.grouping.ods',
    ];

    public function __construct(
        private readonly PlanMeasureCatalogResolver $catalogResolver,
        private readonly MeasureTaxonomyPresenter $taxonomyPresenter,
        private readonly TranslatorInterface $translator,
        private readonly SustainabilityPlanCustomMeasureParser $customMeasureParser
    ) {
    }

    /**
     * @return array<int, array{label:string, rows:array<int, array<string, mixed>>}>
     */
    public function groupPlanMeasures(Plan $plan, Project $project, string $grouping): array
    {
        $this->assertValidGrouping($grouping);

        $groups = [];
        $planProtocolId = $plan->getProtocol()?->getId();

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if (!$planMeasure instanceof PlanMeasure) {
                continue;
            }

            $measure = $planMeasure->getMeasure();
            if (!$measure instanceof Measure) {
                continue;
            }

            if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
                continue;
            }

            if ($planProtocolId !== null && $measure->getProtocol()?->getId() !== $planProtocolId) {
                continue;
            }

            foreach ($this->resolveGroupingLabels($measure, $grouping) as $label) {
                $groups[$label]['label'] = $label;
                $groups[$label]['rows'][$measure->getId() ?? spl_object_id($measure)] = $this->buildRow($planMeasure, $grouping);
            }
        }

        $customMeasures = $this->customMeasureParser->parse($plan->getCustomMeasures());
        if ($customMeasures !== []) {
            $customLabel = $this->translator->trans('backend.plan.custom_measures.group_label');
            foreach ($customMeasures as $index => $customMeasure) {
                $groups[$customLabel]['label'] = $customLabel;
                $groups[$customLabel]['rows']['custom_' . $index] = $this->buildCustomRow($customMeasure, $grouping);
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_map(static function (array $group): array {
            $rows = array_values($group['rows'] ?? []);
            usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['displayName'] ?? '', $right['displayName'] ?? ''));

            return [
                'label' => (string) ($group['label'] ?? ''),
                'rows' => $rows,
            ];
        }, $groups));
    }

    public function getGroupingLabel(string $grouping): string
    {
        $this->assertValidGrouping($grouping);

        return $this->translator->trans(self::GROUPING_LABELS[$grouping]);
    }

    /**
     * @return string[]
     */
    public function getAvailableGroupings(): array
    {
        return array_keys(self::GROUPING_LABELS);
    }

    private function assertValidGrouping(string $grouping): void
    {
        if (!isset(self::GROUPING_LABELS[$grouping])) {
            throw new \InvalidArgumentException(sprintf('Grouping "%s" is not supported.', $grouping));
        }
    }

    /**
     * @return string[]
     */
    private function resolveGroupingLabels(Measure $measure, string $grouping): array
    {
        $labels = [];

        switch ($grouping) {
            case 'category':
                $labels[] = $measure->getCategory()?->getName() ?: $this->translator->trans('backend.common.no_category');
                break;

            case 'department':
                foreach ($this->taxonomyPresenter->departments($measure) as $department) {
                    $labels[] = $department['displayName'] ?: $department['name'];
                }
                if ($labels === []) {
                    $labels[] = $this->translator->trans('backend.plan.labels.no_department');
                }
                break;

            case 'impact_area':
                foreach ($this->taxonomyPresenter->impactAreas($measure) as $impactArea) {
                    $labels[] = $impactArea['name'];
                }
                if ($labels === []) {
                    $labels[] = $this->translator->trans('backend.plan.exports.no_impact_area');
                }
                break;

            case 'triple_balance':
                foreach ($this->taxonomyPresenter->tripleBalanceAxes($measure) as $axis) {
                    $labels[] = $axis['name'];
                }
                if ($labels === []) {
                    $labels[] = $this->translator->trans('backend.plan.exports.no_triple_balance');
                }
                break;

            case 'ods':
                foreach ($this->taxonomyPresenter->odsItems($measure) as $ods) {
                    $labels[] = $ods['label'];
                }
                if ($labels === []) {
                    $labels[] = $this->translator->trans('backend.plan.exports.no_ods');
                }
                break;
        }

        return array_values(array_unique(array_filter(array_map('trim', $labels))));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(PlanMeasure $planMeasure, string $grouping): array
    {
        $measure = $planMeasure->getMeasure();
        if (!$measure instanceof Measure) {
            return [];
        }

        $departments = array_map(
            static fn (array $department): string => $department['displayName'] ?: $department['name'],
            $this->taxonomyPresenter->departments($measure)
        );
        $odsItems = array_map(
            static fn (array $ods): string => $ods['label'],
            $this->taxonomyPresenter->odsItems($measure)
        );
        $impactAreas = array_map(
            static fn (array $impactArea): string => $impactArea['name'],
            $this->taxonomyPresenter->impactAreas($measure)
        );
        $tripleBalanceAxes = array_map(
            static fn (array $axis): string => $axis['name'],
            $this->taxonomyPresenter->tripleBalanceAxes($measure)
        );
        $verificationSources = array_map(
            static fn (array $source): string => sprintf('%d. %s', $source['priority'], $source['name']),
            $this->taxonomyPresenter->verificationSourcesWithPriority($measure)
        );

        $responsibles = [];
        foreach ($planMeasure->getResponsibleCrewMembers() as $crewMember) {
            $responsibles[] = trim((string) $crewMember->getName() . ' ' . (string) $crewMember->getLastName());
        }

        return [
            'grouping' => $grouping,
            'measureId' => $measure->getId(),
            'displayName' => $measure->getDisplayNameForReview(),
            'score' => $measure->getScore(),
            'category' => $measure->getCategory()?->getName() ?: $this->translator->trans('backend.common.no_category'),
            'block' => $measure->getMeasureBlock()?->getName() ?: '—',
            'departments' => $departments !== [] ? implode(', ', $departments) : $this->translator->trans('backend.plan.labels.no_department'),
            'ods' => $odsItems !== [] ? implode(', ', $odsItems) : $this->translator->trans('backend.plan.exports.no_ods'),
            'impactAreas' => $impactAreas !== [] ? implode(', ', $impactAreas) : $this->translator->trans('backend.plan.exports.no_impact_area'),
            'tripleBalanceAxes' => $tripleBalanceAxes !== [] ? implode(', ', $tripleBalanceAxes) : $this->translator->trans('backend.plan.exports.no_triple_balance'),
            'verificationSources' => $verificationSources !== [] ? implode(' | ', $verificationSources) : ($measure->getVerificationSourcesSummary() ?? '—'),
            'implemented' => $planMeasure->isImplemented(),
            'verified' => $planMeasure->isVerification(),
            'responsibles' => $responsibles !== [] ? implode(', ', array_filter($responsibles)) : '—',
            'publicComment' => (string) ($planMeasure->getPublicComment() ?? ''),
            'evidenceCount' => count(array_filter(array_map('trim', preg_split('/\R/u', (string) $planMeasure->getEvidence()) ?: []))),
            'description' => (string) ($measure->getDescription() ?? ''),
        ];
    }

    /**
     * @param array{title:string, description:string, score:int|null, state:string, raw:string} $customMeasure
     * @return array<string, mixed>
     */
    private function buildCustomRow(array $customMeasure, string $grouping): array
    {
        return [
            'grouping' => $grouping,
            'measureId' => null,
            'displayName' => $customMeasure['title'],
            'score' => $customMeasure['score'],
            'category' => $this->translator->trans('backend.plan.custom_measures.category'),
            'block' => '—',
            'departments' => '—',
            'ods' => '—',
            'impactAreas' => '—',
            'tripleBalanceAxes' => '—',
            'verificationSources' => '—',
            'implemented' => in_array($customMeasure['state'], ['implemented', 'verified'], true),
            'verified' => $customMeasure['state'] === 'verified',
            'responsibles' => '—',
            'publicComment' => '',
            'evidenceCount' => 0,
            'description' => $customMeasure['description'] !== '' ? $customMeasure['description'] : $this->translator->trans('backend.plan.custom_measures.no_description'),
            'statusLabel' => $this->translator->trans('backend.plan.review.custom_measures.state.' . $customMeasure['state']),
        ];
    }
}
