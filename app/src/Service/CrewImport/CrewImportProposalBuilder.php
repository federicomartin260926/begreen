<?php

namespace App\Service\CrewImport;

use App\Entity\CrewMember;
use App\Entity\Project;
use App\Repository\CrewMemberRepository;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;
use App\Service\CrewImport\Dto\CrewImportRow;

final readonly class CrewImportProposalBuilder
{
    public function __construct(
        private CrewCatalogContextProvider $catalogContextProvider,
        private CrewMemberRepository $crewMemberRepository,
    ) {
    }

    public function proposal(Project $project, CrewImportExtraction $extraction): CrewImportProposal
    {
        $projectId = $project->getId()
            ?? throw new \LogicException('The project must be persisted before building a crew import proposal.');

        if (!$extraction->isOfficialTemplate()) {
            return new CrewImportProposal($projectId, $extraction->status, []);
        }

        $catalog = $this->catalog($project);
        $existing = $this->existingIdentityMaps($project);
        $groups = $this->groupRows($extraction->rows);
        $people = [];

        foreach ($groups as $group) {
            $assignments = [];
            $assignmentKeys = [];
            $warnings = $group['conflict'] ? [CrewImportWarning::PERSON_IDENTITY_CONFLICT] : [];
            if (count($group['rows']) > 1) {
                $warnings[] = CrewImportWarning::DUPLICATE_IN_FILE;
            }

            foreach ($group['rows'] as $row) {
                $assignment = $this->resolveAssignment($row, $catalog);
                $key = implode('|', [
                    $assignment->departmentId ?? 'null',
                    $assignment->positionId ?? 'null',
                    $assignment->resolutionStatus,
                    self::normalizeLabel($assignment->originalDepartment),
                    self::normalizeLabel($assignment->originalPosition),
                ]);
                if (!isset($assignmentKeys[$key])) {
                    $assignments[] = $assignment;
                    $assignmentKeys[$key] = true;
                }
                $warnings = [...$warnings, ...$assignment->warningCodes];
                $warnings = [...$warnings, ...$row->warningCodes];
            }

            $data = $this->lastNonEmptyPersonalData($group['rows']);
            if ($data['name'] === '') {
                $warnings[] = CrewImportWarning::NAME_REQUIRED;
            }

            $matchedIds = [];
            $emailMatchedIds = $this->matchedIds($group['emails'], $existing['email']);
            $phoneMatchedIds = $this->matchedIds($group['phones'], $existing['phone']);
            foreach ([...$emailMatchedIds, ...$phoneMatchedIds] as $matchedId) {
                $matchedIds[$matchedId] = true;
            }

            if (
                count($matchedIds) > 1
                || ($emailMatchedIds !== [] && $phoneMatchedIds !== [] && $emailMatchedIds !== $phoneMatchedIds)
            ) {
                $warnings[] = CrewImportWarning::PERSON_IDENTITY_CONFLICT;
            }

            $warnings = array_values(array_unique($warnings));
            $sourceReferences = [];
            foreach ($group['rows'] as $row) {
                if ($row->sourceReference !== '' && !in_array($row->sourceReference, $sourceReferences, true)) {
                    $sourceReferences[] = $row->sourceReference;
                }
            }
            $conflict = in_array(CrewImportWarning::PERSON_IDENTITY_CONFLICT, $warnings, true);
            $reviewRequired = $conflict
                || in_array(CrewImportWarning::NAME_REQUIRED, $warnings, true)
                || in_array(CrewImportWarning::AI_CATALOG_MISMATCH, $warnings, true)
                || in_array(CrewImportWarning::AI_ROW_UNKNOWN, $warnings, true)
                || in_array(CrewImportWarning::AI_NON_CREW, $warnings, true)
                || count(array_filter($assignments, static fn (CrewImportAssignmentProposal $item): bool => !$item->isResolved())) > 0;
            $existingId = count($matchedIds) === 1 ? (int) array_key_first($matchedIds) : null;
            $action = $conflict
                ? CrewImportPersonProposal::CONFLICT
                : ($reviewRequired
                    ? CrewImportPersonProposal::REVIEW
                    : ($existingId === null ? CrewImportPersonProposal::CREATE : CrewImportPersonProposal::ASSOCIATE));

            $people[] = new CrewImportPersonProposal(
                array_map(static fn (CrewImportRow $row): int => $row->sourceRow, $group['rows']),
                $data['fullName'],
                $data['name'],
                $data['lastName'],
                $data['email'],
                $data['phone'],
                $existingId,
                $action,
                $reviewRequired,
                $warnings,
                $assignments,
                $sourceReferences,
            );
        }

        return new CrewImportProposal($projectId, $extraction->status, $people);
    }

    /** @return array{departments: array<string, list<array>>, positions: array<string, list<array>>, positionsByDepartment: array<int, array<string, list<array>>>, byId: array<int, array{positions: array<int, true>}>} */
    private function catalog(Project $project): array
    {
        $departments = [];
        $positions = [];
        $positionsByDepartment = [];
        $byId = [];

        foreach ($this->catalogContextProvider->provide($project) as $department) {
            $byId[$department['id']] = ['positions' => []];
            foreach (['es', 'en'] as $locale) {
                $departments[self::normalizeLabel($department['name'][$locale])][$department['id']] = $department;
            }
            foreach ($department['positions'] as $position) {
                $byId[$department['id']]['positions'][$position['id']] = true;
                foreach (['es', 'en'] as $locale) {
                    $key = self::normalizeLabel($position['name'][$locale]);
                    $positions[$key][$position['id']] = [
                        'id' => $position['id'],
                        'departmentId' => $department['id'],
                    ];
                    $positionsByDepartment[$department['id']][$key][$position['id']] = [
                        'id' => $position['id'],
                        'departmentId' => $department['id'],
                    ];
                }
            }
        }

        return ['departments' => $departments, 'positions' => $positions, 'positionsByDepartment' => $positionsByDepartment, 'byId' => $byId];
    }

    /** @param array{departments: array, positions: array, positionsByDepartment: array, byId: array} $catalog */
    private function resolveAssignment(CrewImportRow $row, array $catalog): CrewImportAssignmentProposal
    {
        if ($row->candidateDepartmentId !== null || $row->candidatePositionId !== null) {
            if (
                $row->candidateDepartmentId === null
                || !isset($catalog['byId'][$row->candidateDepartmentId])
                || (
                    $row->candidatePositionId !== null
                    && !isset($catalog['byId'][$row->candidateDepartmentId]['positions'][$row->candidatePositionId])
                )
            ) {
                return new CrewImportAssignmentProposal(
                    $row->sourceRow,
                    $row->department,
                    $row->position,
                    null,
                    null,
                    CrewImportAssignmentProposal::POSITION_DEPARTMENT_MISMATCH,
                    [CrewImportWarning::AI_CATALOG_MISMATCH],
                );
            }

            return new CrewImportAssignmentProposal(
                $row->sourceRow,
                $row->department,
                $row->position,
                $row->candidateDepartmentId,
                $row->candidatePositionId,
                CrewImportAssignmentProposal::RESOLVED,
            );
        }

        if ($row->department === '' && $row->position === '') {
            return new CrewImportAssignmentProposal($row->sourceRow, '', '', null, null, CrewImportAssignmentProposal::NONE);
        }

        $department = null;
        if ($row->department !== '') {
            $departmentMatches = array_values($catalog['departments'][self::normalizeLabel($row->department)] ?? []);
            if (count($departmentMatches) !== 1) {
                return new CrewImportAssignmentProposal(
                    $row->sourceRow,
                    $row->department,
                    $row->position,
                    null,
                    null,
                    CrewImportAssignmentProposal::UNKNOWN_DEPARTMENT,
                    [CrewImportWarning::UNKNOWN_DEPARTMENT]
                );
            }
            $department = $departmentMatches[0];
        }

        if ($row->position === '') {
            return new CrewImportAssignmentProposal(
                $row->sourceRow,
                $row->department,
                '',
                $department['id'],
                null,
                CrewImportAssignmentProposal::RESOLVED
            );
        }

        $positionKey = self::normalizeLabel($row->position);
        if ($department !== null) {
            $matches = array_values($catalog['positionsByDepartment'][$department['id']][$positionKey] ?? []);
            if (count($matches) === 1) {
                return new CrewImportAssignmentProposal(
                    $row->sourceRow,
                    $row->department,
                    $row->position,
                    $department['id'],
                    $matches[0]['id'],
                    CrewImportAssignmentProposal::RESOLVED
                );
            }

            $status = isset($catalog['positions'][$positionKey])
                ? CrewImportAssignmentProposal::POSITION_DEPARTMENT_MISMATCH
                : CrewImportAssignmentProposal::UNKNOWN_POSITION;
            $warning = $status === CrewImportAssignmentProposal::POSITION_DEPARTMENT_MISMATCH
                ? CrewImportWarning::POSITION_DEPARTMENT_MISMATCH
                : CrewImportWarning::UNKNOWN_POSITION;

            return new CrewImportAssignmentProposal(
                $row->sourceRow,
                $row->department,
                $row->position,
                $department['id'],
                null,
                $status,
                [$warning]
            );
        }

        $matches = array_values($catalog['positions'][$positionKey] ?? []);
        if (count($matches) === 1) {
            return new CrewImportAssignmentProposal(
                $row->sourceRow,
                '',
                $row->position,
                $matches[0]['departmentId'],
                $matches[0]['id'],
                CrewImportAssignmentProposal::RESOLVED
            );
        }

        $status = $matches === []
            ? CrewImportAssignmentProposal::UNKNOWN_POSITION
            : CrewImportAssignmentProposal::AMBIGUOUS_POSITION;

        return new CrewImportAssignmentProposal(
            $row->sourceRow,
            '',
            $row->position,
            null,
            null,
            $status,
            [$matches === [] ? CrewImportWarning::UNKNOWN_POSITION : CrewImportWarning::AMBIGUOUS_POSITION]
        );
    }

    /** @param list<CrewImportRow> $rows @return list<array{rows: list<CrewImportRow>, emails: array<string, true>, phones: array<string, true>, conflict: bool}> */
    private function groupRows(array $rows): array
    {
        $groups = [];
        $emailGroups = [];
        $phoneGroups = [];
        $nextGroup = 0;

        foreach ($rows as $row) {
            $email = self::normalizeEmail($row->email);
            $phone = self::normalizePhone($row->phone);
            $emailGroup = $email !== '' ? ($emailGroups[$email] ?? null) : null;
            $phoneGroup = $phone !== '' ? ($phoneGroups[$phone] ?? null) : null;

            if ($emailGroup !== null && $phoneGroup !== null && $emailGroup !== $phoneGroup) {
                $groupId = $emailGroup;
                $groups[$groupId]['rows'] = [...$groups[$groupId]['rows'], ...$groups[$phoneGroup]['rows']];
                $groups[$groupId]['emails'] += $groups[$phoneGroup]['emails'];
                $groups[$groupId]['phones'] += $groups[$phoneGroup]['phones'];
                $groups[$groupId]['conflict'] = true;
                unset($groups[$phoneGroup]);
                foreach ($emailGroups as $key => $id) {
                    if ($id === $phoneGroup) {
                        $emailGroups[$key] = $groupId;
                    }
                }
                foreach ($phoneGroups as $key => $id) {
                    if ($id === $phoneGroup) {
                        $phoneGroups[$key] = $groupId;
                    }
                }
            } else {
                $groupId = $emailGroup ?? $phoneGroup;
            }

            if ($groupId === null) {
                $groupId = $nextGroup++;
                $groups[$groupId] = ['rows' => [], 'emails' => [], 'phones' => [], 'conflict' => false];
            }

            $groups[$groupId]['rows'][] = $row;
            if ($email !== '') {
                $groups[$groupId]['emails'][$email] = true;
                $emailGroups[$email] = $groupId;
            }
            if ($phone !== '') {
                $groups[$groupId]['phones'][$phone] = true;
                $phoneGroups[$phone] = $groupId;
            }

            // Email and phone are both strong identifiers. Multiple phone
            // values may represent an updated phone for the same email, but
            // two distinct emails connected through a phone are ambiguous
            // and must never be merged silently.
            if (count($groups[$groupId]['emails']) > 1) {
                $groups[$groupId]['conflict'] = true;
            }
        }

        return array_values($groups);
    }

    /** @return array{email: array<string, list<int>>, phone: array<string, list<int>>} */
    private function existingIdentityMaps(Project $project): array
    {
        $maps = ['email' => [], 'phone' => []];
        foreach ($this->crewMemberRepository->findByProject($project) as $member) {
            $id = $member->getId();
            if ($id === null) {
                continue;
            }
            $email = self::normalizeEmail($member->getEmail());
            $phone = self::normalizePhone($member->getPhone());
            if ($email !== '') {
                $maps['email'][$email][] = $id;
            }
            if ($phone !== '') {
                $maps['phone'][$phone][] = $id;
            }
        }

        return $maps;
    }

    /** @param array<string, true> $keys @param array<string, list<int>> $existing @return list<int> */
    private function matchedIds(array $keys, array $existing): array
    {
        $ids = [];
        foreach (array_keys($keys) as $key) {
            foreach ($existing[$key] ?? [] as $id) {
                $ids[$id] = true;
            }
        }

        $ids = array_map('intval', array_keys($ids));
        sort($ids);

        return $ids;
    }

    /** @param list<CrewImportRow> $rows @return array{fullName: string, name: string, lastName: string, email: string, phone: string} */
    private function lastNonEmptyPersonalData(array $rows): array
    {
        $data = ['fullName' => '', 'name' => '', 'lastName' => '', 'email' => '', 'phone' => ''];
        foreach ($rows as $row) {
            foreach (['name', 'lastName', 'email', 'phone'] as $field) {
                if ($row->{$field} !== '') {
                    $data[$field] = $row->{$field};
                }
            }
            if ($row->originalFullName() !== '') {
                $data['fullName'] = $row->originalFullName();
            }
        }

        return $data;
    }

    private static function normalizeEmail(?string $email): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{Cf}\x{00A0}]+/u', '', trim((string) $email)));
    }

    private static function normalizePhone(?string $phone): string
    {
        return (string) preg_replace('/[\s\p{Cf}\x{00A0}\-–—().]+/u', '', trim((string) $phone));
    }

    private static function normalizeLabel(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
