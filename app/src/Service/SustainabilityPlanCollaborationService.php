<?php

namespace App\Service;

use App\Entity\CrewMember;
use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use Doctrine\Common\Collections\Collection;

final class SustainabilityPlanCollaborationService
{
    public function __construct(
        private readonly PlanMeasureCatalogResolver $catalogResolver,
        private readonly SustainabilityPlanCustomMeasureParser $customMeasureParser,
        private readonly PlanMeasureOperationalStateResolver $operationalStateResolver,
    ) {
    }

    /**
     * @return array{
     *     toImplement:int,
     *     implemented:int,
     *     notImplemented:int,
     *     inProgress:int,
     *     pending:int,
     *     verified:int,
     *     evidenceFiles:int,
     *     responsibles:int,
     *     executionIncidents:int,
     *     internalNotes:int,
     *     customMeasures:int,
     *     hasImplementationActivity:bool
     * }
     */
    public function buildProgressSummary(Plan $plan, Project $project): array
    {
        $summary = [
            'toImplement' => 0,
            'implemented' => 0,
            'notImplemented' => 0,
            'inProgress' => 0,
            'pending' => 0,
            'verified' => 0,
            'evidenceFiles' => 0,
            'responsibles' => 0,
            'executionIncidents' => 0,
            'internalNotes' => 0,
            'customMeasures' => count($this->customMeasureParser->parse($plan->getCustomMeasures())),
            'hasImplementationActivity' => $this->containsImplementationActivity($plan),
        ];

        $uniqueEvidence = [];
        foreach ($this->filterPlanMeasuresBySkippedBlocks($plan->getPlanMeasures(), $plan) as $planMeasure) {
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

            if ($measure->getProtocol()?->getId() !== $plan->getProtocol()?->getId()) {
                continue;
            }

            if ($planMeasure->isApplicable() === true && $planMeasure->willImplement() === true) {
                $summary['toImplement']++;
                match ($this->operationalStateResolver->resolve($planMeasure)) {
                    PlanMeasureOperationalStateResolver::IMPLEMENTED => $summary['implemented']++,
                    PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED => $summary['notImplemented']++,
                    PlanMeasureOperationalStateResolver::IN_PROGRESS => $summary['inProgress']++,
                    default => $summary['pending']++,
                };
            }

            if ($planMeasure->isVerification()) {
                $summary['verified']++;
            }

            if ((string) $planMeasure->getExecutionIncident() !== '') {
                $summary['executionIncidents']++;
            }

            if ((string) $planMeasure->getInternalNotes() !== '') {
                $summary['internalNotes']++;
            }

            if (!$planMeasure->getResponsibleCrewMembers()->isEmpty()) {
                $summary['responsibles']++;
            }

            foreach ($this->extractEvidenceFiles($planMeasure->getEvidence()) as $path) {
                $uniqueEvidence[$path] = true;
            }

        }

        $summary['evidenceFiles'] = count($uniqueEvidence);

        return $summary;
    }

    public function hasImplementationActivity(Plan $plan): bool
    {
        return $this->containsImplementationActivity($plan);
    }

    private function containsImplementationActivity(Plan $plan): bool
    {
        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure instanceof PlanMeasure && $this->planMeasureHasImplementationActivity($planMeasure)) {
                return true;
            }
        }

        return false;
    }

    private function planMeasureHasImplementationActivity(PlanMeasure $planMeasure): bool
    {
        return $planMeasure->hasActionTaken()
            || $planMeasure->hasEvidence()
            || !$planMeasure->getResponsibleCrewMembers()->isEmpty()
            || trim((string) $planMeasure->getExecutionIncident()) !== ''
            || trim((string) $planMeasure->getInternalNotes()) !== ''
            || $planMeasure->isImplemented() !== null
            || $planMeasure->isVerification();
    }

    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     score: int|null,
     *     state: string,
     *     raw: string
     * }>
     */
    public function getCustomMeasures(Plan $plan): array
    {
        return $this->customMeasureParser->parse($plan->getCustomMeasures());
    }

    /**
     * @return CrewMember[]
     */
    public function sortCrewMembersForMeasure(Measure $measure, Collection|array $crewMembers): array
    {
        $measureDepartmentIds = [];
        foreach ($measure->getResolvedDepartments() as $department) {
            if ($department->getId() !== null) {
                $measureDepartmentIds[(string) $department->getId()] = true;
            }
        }

        $compatible = [];
        $others = [];

        foreach ($crewMembers as $crewMember) {
            if (!$crewMember instanceof CrewMember) {
                continue;
            }

            $isCompatible = false;
            foreach ($crewMember->getAssignments() as $assignment) {
                $crewDepartment = $assignment->getCrewDepartment();
                if ($crewDepartment === null) {
                    continue;
                }

                foreach ($crewDepartment->getCompatibleMeasureDepartments() as $department) {
                    if ($department->getId() !== null
                        && isset($measureDepartmentIds[(string) $department->getId()])) {
                        $isCompatible = true;
                        break 2;
                    }
                }
            }

            if ($isCompatible) {
                $compatible[] = $crewMember;
                continue;
            }

            $others[] = $crewMember;
        }

        $sorter = static function (CrewMember $left, CrewMember $right): int {
            $leftLabel = trim((string) $left->getName() . ' ' . (string) $left->getLastName());
            $rightLabel = trim((string) $right->getName() . ' ' . (string) $right->getLastName());

            return strnatcasecmp($leftLabel, $rightLabel);
        };

        usort($compatible, $sorter);
        usort($others, $sorter);

        return array_merge($compatible, $others);
    }

    /**
     * @return array<int, string>
     */
    public function getResponsibleLabels(PlanMeasure $planMeasure): array
    {
        $labels = [];
        foreach ($planMeasure->getResponsibleCrewMembers() as $crewMember) {
            if (!$crewMember instanceof CrewMember) {
                continue;
            }

            $labels[] = trim((string) $crewMember->getName() . ' ' . (string) $crewMember->getLastName());
        }

        return array_values(array_filter(array_map('trim', $labels)));
    }

    public function syncResponsibleCrewMembers(PlanMeasure $planMeasure, array $crewMembers): void
    {
        $seen = [];
        foreach ($crewMembers as $crewMember) {
            if (!$crewMember instanceof CrewMember) {
                continue;
            }

            $id = (string) $crewMember->getId();
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
        }

        foreach ($planMeasure->getResponsibleCrewMembers() as $existing) {
            if ($existing instanceof CrewMember) {
                $planMeasure->removeResponsibleCrewMember($existing);
            }
        }

        foreach ($crewMembers as $crewMember) {
            if ($crewMember instanceof CrewMember) {
                $planMeasure->addResponsibleCrewMember($crewMember);
            }
        }
    }

    /**
     * @return string[]
     */
    private function extractEvidenceFiles(?string $evidence): array
    {
        $paths = array_filter(array_map('trim', preg_split('/\R/u', (string) $evidence) ?: []));
        return array_values(array_unique($paths));
    }

    /**
     * @param iterable<int, PlanMeasure> $planMeasures
     * @return array<int, PlanMeasure>
     */
    private function filterPlanMeasuresBySkippedBlocks(iterable $planMeasures, Plan $plan): array
    {
        $skippedBlockIds = $this->getSkippedBlockIds($plan);
        if ($skippedBlockIds === []) {
            return is_array($planMeasures) ? $planMeasures : iterator_to_array($planMeasures, false);
        }

        $result = [];
        foreach ($planMeasures as $planMeasure) {
            $blockId = $planMeasure->getMeasure()?->getMeasureBlock()?->getId();
            if ($blockId !== null && isset($skippedBlockIds[(int) $blockId])) {
                continue;
            }

            $result[] = $planMeasure;
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function getSkippedBlockIds(Plan $plan): array
    {
        $ids = [];

        foreach ($plan->getBlockAnswers() as $answer) {
            if ($answer->applies() === false && $answer->getMeasureBlock()?->getId() !== null) {
                $ids[(int) $answer->getMeasureBlock()->getId()] = (int) $answer->getMeasureBlock()->getId();
            }
        }

        return $ids;
    }
}
