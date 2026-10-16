<?php

declare(strict_types=1);

namespace App\Service\FinalReport;

use App\Entity\CrewMember;
use App\Entity\EmissionRecord;
use App\Entity\Measure;
use App\Entity\Ods;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\EmissionRecordRepository;
use App\Service\PlanMeasureOperationalStateResolver;
use App\Service\SustainabilityCommitmentLevelService;

final class FinalReportDataBuilder
{
    public function __construct(
        private readonly EmissionRecordRepository $emissionRecordRepository,
        private readonly PlanMeasureOperationalStateResolver $operationalStateResolver,
        private readonly SustainabilityCommitmentLevelService $commitmentLevelService,
    ) {
    }

    /**
     * Builds the factual base shared by the later final-report presentation layers.
     *
     * @return array<string, mixed>
     */
    public function build(Project $project, Plan $plan): array
    {
        $planProject = $plan->getProject();
        $samePersistedProject = $planProject instanceof Project
            && $project->getId() !== null
            && $planProject->getId() === $project->getId();
        if (!$samePersistedProject && $planProject !== $project) {
            throw new \InvalidArgumentException('The sustainability plan does not belong to the requested project.');
        }

        $planMeasures = array_values(array_filter(
            $plan->getPlanMeasures()->toArray(),
            static fn (mixed $item): bool => $item instanceof PlanMeasure,
        ));

        return [
            'project' => $this->buildProject($project),
            'plan' => $this->buildPlan($plan, $planMeasures),
            'implementation' => $this->buildImplementation($planMeasures),
            'emissions' => $this->buildEmissions($project),
            'ods' => $this->buildOds($planMeasures),
            'commitment' => $this->commitmentLevelService->buildSummary($plan, $project),
        ];
    }

    /** @return array<string, mixed> */
    private function buildProject(Project $project): array
    {
        $phases = array_values(array_filter(
            $project->getPhaseDates()->toArray(),
            static fn (mixed $item): bool => $item instanceof ProjectPhaseDate,
        ));
        usort($phases, static function (ProjectPhaseDate $left, ProjectPhaseDate $right): int {
            return ($left->getStartDate()?->getTimestamp() ?? PHP_INT_MAX)
                <=> ($right->getStartDate()?->getTimestamp() ?? PHP_INT_MAX)
                ?: strcmp((string) $left->getPhase(), (string) $right->getPhase());
        });

        return [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'type' => $project->getType(),
            'country' => $project->getCountry(),
            'mainLocation' => $project->getMainLocation(),
            'phases' => array_map(static fn (ProjectPhaseDate $phase): array => [
                'id' => $phase->getId(),
                'code' => $phase->getPhase(),
                'startDate' => $phase->getStartDate()?->format('Y-m-d'),
                'endDate' => $phase->getEndDate()?->format('Y-m-d'),
            ], $phases),
        ];
    }

    /**
     * @param PlanMeasure[] $planMeasures
     * @return array<string, mixed>
     */
    private function buildPlan(Plan $plan, array $planMeasures): array
    {
        $protocol = $plan->getProtocol();

        return [
            'id' => $plan->getId(),
            'status' => $plan->getStatus(),
            'statusChangedAt' => $plan->getStatusChangedAt()?->format(DATE_ATOM),
            'protocol' => $protocol === null ? null : [
                'id' => $protocol->getId(),
                'code' => $protocol->getCode(),
                'name' => $protocol->getName(),
            ],
            'measures' => array_map(static function (PlanMeasure $planMeasure): array {
                $measure = $planMeasure->getMeasure();

                return [
                    'planMeasureId' => $planMeasure->getId(),
                    'measure' => $measure instanceof Measure ? [
                        'id' => $measure->getId(),
                        'name' => $measure->getDisplayNameForReview(),
                        'description' => $measure->getDescription(),
                        'implementationGuidance' => $measure->getImplementation(),
                    ] : null,
                    'decision' => [
                        'applicable' => $planMeasure->isApplicable(),
                        'willImplement' => $planMeasure->willImplement(),
                        'primary' => $planMeasure->getPrimaryDecision(),
                        'critical' => $planMeasure->isCritical(),
                    ],
                ];
            }, $planMeasures),
        ];
    }

    /**
     * @param PlanMeasure[] $planMeasures
     * @return array{
     *     summary:array{
     *         totalMeasures:int,
     *         implementedMeasures:int,
     *         resolvedMeasures:int,
     *         byState:array<string, int>
     *     },
     *     measures:array<int, array<string, mixed>>
     * }
     */
    private function buildImplementation(array $planMeasures): array
    {
        $byState = [
            PlanMeasureOperationalStateResolver::PENDING => 0,
            PlanMeasureOperationalStateResolver::IN_PROGRESS => 0,
            PlanMeasureOperationalStateResolver::IMPLEMENTED => 0,
            PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED => 0,
            PlanMeasureOperationalStateResolver::DISCARDED => 0,
            PlanMeasureOperationalStateResolver::NOT_APPLICABLE => 0,
        ];
        $measures = array_map(function (PlanMeasure $planMeasure) use (&$byState): array {
            $measure = $planMeasure->getMeasure();
            $state = $this->operationalStateResolver->resolve($planMeasure);
            ++$byState[$state];

            return [
                'planMeasureId' => $planMeasure->getId(),
                'measureId' => $measure?->getId(),
                'measureName' => $measure?->getDisplayNameForReview(),
                'state' => $state,
                'actionTaken' => $this->optionalText($planMeasure->getActionTaken()),
                'incident' => $this->optionalText($planMeasure->getExecutionIncident()),
                'observations' => $this->optionalText($planMeasure->getObservations()),
                'verification' => $planMeasure->isVerification(),
                'responsibleCrewMembers' => array_values(array_map(
                    static fn (CrewMember $crewMember): array => [
                        'id' => $crewMember->getId(),
                        'name' => $crewMember->getName(),
                        'lastName' => $crewMember->getLastName(),
                    ],
                    array_filter(
                        $planMeasure->getResponsibleCrewMembers()->toArray(),
                        static fn (mixed $item): bool => $item instanceof CrewMember,
                    ),
                )),
            ];
        }, $planMeasures);
        $resolvedStates = [
            PlanMeasureOperationalStateResolver::IMPLEMENTED,
            PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED,
            PlanMeasureOperationalStateResolver::DISCARDED,
            PlanMeasureOperationalStateResolver::NOT_APPLICABLE,
        ];

        return [
            'summary' => [
                'totalMeasures' => count($measures),
                'implementedMeasures' => $byState[PlanMeasureOperationalStateResolver::IMPLEMENTED],
                'resolvedMeasures' => array_sum(array_intersect_key($byState, array_flip($resolvedStates))),
                'byState' => $byState,
            ],
            'measures' => $measures,
        ];
    }

    /** @return array<string, mixed> */
    private function buildEmissions(Project $project): array
    {
        $records = $this->emissionRecordRepository->findByProjectOrderByPhaseAndDate($project);
        $total = 0.0;
        $validRecordCount = 0;
        $byCategory = [];
        $byPhase = [];

        foreach ($records as $record) {
            if (!$record instanceof EmissionRecord || !$this->isValidEmission($record)) {
                continue;
            }

            $emission = (float) $record->getEmission();
            $total += $emission;
            ++$validRecordCount;

            $category = $record->getEffectiveCategory();
            $categoryKey = $category?->getId() !== null
                ? 'id:'.$category->getId()
                : 'name:'.(string) $category?->getName();
            $byCategory[$categoryKey] ??= [
                'id' => $category?->getId(),
                'name' => $this->optionalText($category?->getName()),
                'kgCo2e' => 0.0,
            ];
            $byCategory[$categoryKey]['kgCo2e'] += $emission;

            $phase = $record->getPhase();
            $phaseKey = $phase->getId() !== null
                ? 'id:'.$phase->getId()
                : 'code:'.(string) $phase->getPhase();
            $byPhase[$phaseKey] ??= [
                'id' => $phase->getId(),
                'code' => $phase->getPhase(),
                'kgCo2e' => 0.0,
            ];
            $byPhase[$phaseKey]['kgCo2e'] += $emission;
        }

        return [
            'available' => $validRecordCount > 0,
            'validRecordCount' => $validRecordCount,
            'totalKgCo2e' => $validRecordCount > 0 ? $total : null,
            'byCategory' => array_values($byCategory),
            'byPhase' => array_values($byPhase),
        ];
    }

    private function isValidEmission(EmissionRecord $record): bool
    {
        return in_array($record->getStatus(), [
            EmissionRecord::STATUS_CALCULATED,
            EmissionRecord::STATUS_CLOSED,
        ], true)
            && $record->getEmission() !== null
            && is_finite($record->getEmission());
    }

    /**
     * @param PlanMeasure[] $planMeasures
     * @return array<int, array{id:int|null, code:string|null, name:string|null}>
     */
    private function buildOds(array $planMeasures): array
    {
        $odsItems = [];

        foreach ($planMeasures as $planMeasure) {
            if ($this->operationalStateResolver->resolve($planMeasure) !== PlanMeasureOperationalStateResolver::IMPLEMENTED) {
                continue;
            }

            $associatedOds = [];
            if ($planMeasure->getOds() instanceof Ods) {
                $associatedOds[] = $planMeasure->getOds();
            }
            foreach ($planMeasure->getMeasure()?->getResolvedOdsItems() ?? [] as $ods) {
                if ($ods instanceof Ods) {
                    $associatedOds[] = $ods;
                }
            }

            foreach ($associatedOds as $ods) {
                $key = $ods->getId() !== null
                    ? 'id:'.$ods->getId()
                    : 'code:'.(string) $ods->getCode();
                $odsItems[$key] ??= [
                    'id' => $ods->getId(),
                    'code' => $ods->getCode(),
                    'name' => $ods->getName(),
                ];
            }
        }

        return array_values($odsItems);
    }

    private function optionalText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
