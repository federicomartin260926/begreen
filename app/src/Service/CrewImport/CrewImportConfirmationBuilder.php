<?php

namespace App\Service\CrewImport;

use App\Entity\CrewMember;
use App\Entity\Project;
use App\Exception\CrewImport\CrewImportReviewValidationException;
use App\Repository\CrewMemberRepository;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;

final readonly class CrewImportConfirmationBuilder
{
    public function __construct(
        private CrewCatalogContextProvider $catalogContextProvider,
        private CrewMemberRepository $crewMemberRepository,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function build(CrewImportProposal $stored, Project $project, array $input): CrewImportProposal
    {
        $projectId = $project->getId();
        if ($projectId === null || $stored->projectId !== $projectId) {
            throw new CrewImportReviewValidationException(['backend.projects.crew.import.review.errors.invalid']);
        }

        if ($this->hasUnexpectedKeys($input, ['people'])) {
            throw new CrewImportReviewValidationException(['backend.projects.crew.import.review.errors.structure']);
        }

        $submittedPeople = $input['people'] ?? null;
        if (!is_array($submittedPeople) || array_keys($submittedPeople) !== array_keys($stored->people)) {
            throw new CrewImportReviewValidationException(['backend.projects.crew.import.review.errors.structure']);
        }

        $catalog = $this->catalog($project);
        $confirmedPeople = [];
        $errors = [];

        foreach ($stored->people as $personIndex => $storedPerson) {
            $personData = $submittedPeople[$personIndex] ?? null;
            if (!is_array($personData)) {
                $errors[] = 'backend.projects.crew.import.review.errors.structure';
                continue;
            }

            $allowedPersonKeys = ['include', 'name', 'lastName', 'email', 'phone', 'action', 'existingCrewMemberId', 'assignments'];
            if ($this->hasUnexpectedKeys($personData, $allowedPersonKeys)) {
                $errors[] = 'backend.projects.crew.import.review.errors.structure';
                continue;
            }

            $include = $this->checkbox($personData['include'] ?? null);
            if ($include === null) {
                $errors[] = 'backend.projects.crew.import.review.errors.structure';
                continue;
            }
            if (!$include) {
                continue;
            }

            $name = $this->text($personData['name'] ?? null, 100);
            $lastName = $this->text($personData['lastName'] ?? null, 255);
            $email = $this->text($personData['email'] ?? null, 150);
            $phone = $this->text($personData['phone'] ?? null, 20);
            if ($name === null || $lastName === null || $email === null || $phone === null) {
                $errors[] = 'backend.projects.crew.import.review.errors.fields';
                continue;
            }
            if ($name === '') {
                $errors[] = 'backend.projects.crew.import.review.errors.name';
            }
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'backend.projects.crew.import.review.errors.email';
            }

            $action = $personData['action'] ?? null;
            $existingId = null;
            if ($action === CrewImportPersonProposal::ASSOCIATE) {
                $existingId = $this->positiveInt($personData['existingCrewMemberId'] ?? null);
                $member = $existingId !== null ? $this->crewMemberRepository->find($existingId) : null;
                if (!$member instanceof CrewMember || $member->getProject()?->getId() !== $projectId) {
                    $errors[] = 'backend.projects.crew.import.review.errors.member';
                }
            } elseif ($action !== CrewImportPersonProposal::CREATE) {
                $errors[] = 'backend.projects.crew.import.review.errors.action';
            }

            $submittedAssignments = $personData['assignments'] ?? null;
            if (!is_array($submittedAssignments) || array_keys($submittedAssignments) !== array_keys($storedPerson->assignments)) {
                $errors[] = 'backend.projects.crew.import.review.errors.structure';
                continue;
            }

            $assignments = [];
            foreach ($storedPerson->assignments as $assignmentIndex => $storedAssignment) {
                $assignmentData = $submittedAssignments[$assignmentIndex] ?? null;
                if (!is_array($assignmentData) || $this->hasUnexpectedKeys($assignmentData, ['departmentId', 'positionId'])) {
                    $errors[] = 'backend.projects.crew.import.review.errors.structure';
                    continue;
                }

                $departmentId = $this->nullablePositiveInt($assignmentData['departmentId'] ?? null);
                $positionId = $this->nullablePositiveInt($assignmentData['positionId'] ?? null);
                if ($departmentId === false || $positionId === false) {
                    $errors[] = 'backend.projects.crew.import.review.errors.catalog';
                    continue;
                }
                if ($departmentId === null && $positionId !== null) {
                    $errors[] = 'backend.projects.crew.import.review.errors.position_without_department';
                    continue;
                }
                if ($departmentId !== null && !isset($catalog[$departmentId])) {
                    $errors[] = 'backend.projects.crew.import.review.errors.catalog';
                    continue;
                }
                if ($positionId !== null && !isset($catalog[$departmentId]['positions'][$positionId])) {
                    $errors[] = 'backend.projects.crew.import.review.errors.position_department';
                    continue;
                }

                $assignments[] = new CrewImportAssignmentProposal(
                    $storedAssignment->sourceRow,
                    $storedAssignment->originalDepartment,
                    $storedAssignment->originalPosition,
                    $departmentId,
                    $positionId,
                    $departmentId === null ? CrewImportAssignmentProposal::NONE : CrewImportAssignmentProposal::RESOLVED,
                    [],
                );
            }

            $confirmedPeople[] = new CrewImportPersonProposal(
                $storedPerson->sourceRows,
                $storedPerson->originalFullName,
                $name,
                $lastName,
                $email,
                $phone,
                $action === CrewImportPersonProposal::ASSOCIATE ? $existingId : null,
                is_string($action) ? $action : CrewImportPersonProposal::REVIEW,
                false,
                in_array(CrewImportWarning::DUPLICATE_IN_FILE, $storedPerson->warningCodes, true)
                    ? [CrewImportWarning::DUPLICATE_IN_FILE]
                    : [],
                $assignments,
                $storedPerson->sourceReferences,
            );
        }

        if ($errors !== []) {
            throw new CrewImportReviewValidationException(array_values(array_unique($errors)));
        }

        return new CrewImportProposal($projectId, CrewImportExtraction::OFFICIAL_TEMPLATE, $confirmedPeople);
    }

    /** @return array<int, array{positions: array<int, true>}> */
    private function catalog(Project $project): array
    {
        $catalog = [];
        foreach ($this->catalogContextProvider->provide($project) as $department) {
            $positions = [];
            foreach ($department['positions'] as $position) {
                $positions[$position['id']] = true;
            }
            $catalog[$department['id']] = ['positions' => $positions];
        }

        return $catalog;
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function hasUnexpectedKeys(array $data, array $allowed): bool
    {
        return array_diff(array_keys($data), $allowed) !== [];
    }

    private function checkbox(mixed $value): ?bool
    {
        return match ($value) {
            null, '', '0', 0, false => false,
            '1', 1, true => true,
            default => null,
        };
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return mb_strlen($value) <= $maxLength ? $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        if (!preg_match('/\A[1-9][0-9]*\z/D', (string) $value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullablePositiveInt(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveInt($value) ?? false;
    }
}
