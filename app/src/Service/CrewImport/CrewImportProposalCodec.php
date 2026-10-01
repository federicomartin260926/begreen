<?php

namespace App\Service\CrewImport;

use App\Exception\CrewImport\CrewImportProposalCodecException;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;

final class CrewImportProposalCodec
{
    public const MAX_PEOPLE = 500;
    public const MAX_ASSIGNMENTS_PER_PERSON = 50;
    public const MAX_SOURCE_ROWS_PER_PERSON = 100;

    private const EXTRACTION_STATUSES = [
        CrewImportExtraction::OFFICIAL_TEMPLATE,
        CrewImportExtraction::UNSUPPORTED_TEMPLATE,
        CrewImportExtraction::READ_ERROR,
    ];

    private const ACTIONS = [
        CrewImportPersonProposal::CREATE,
        CrewImportPersonProposal::ASSOCIATE,
        CrewImportPersonProposal::REVIEW,
        CrewImportPersonProposal::CONFLICT,
        CrewImportPersonProposal::OMIT,
    ];

    private const ASSIGNMENT_STATUSES = [
        CrewImportAssignmentProposal::NONE,
        CrewImportAssignmentProposal::RESOLVED,
        CrewImportAssignmentProposal::UNKNOWN_DEPARTMENT,
        CrewImportAssignmentProposal::UNKNOWN_POSITION,
        CrewImportAssignmentProposal::AMBIGUOUS_POSITION,
        CrewImportAssignmentProposal::POSITION_DEPARTMENT_MISMATCH,
    ];

    private const WARNINGS = [
        CrewImportWarning::NAME_REQUIRED,
        CrewImportWarning::UNKNOWN_DEPARTMENT,
        CrewImportWarning::UNKNOWN_POSITION,
        CrewImportWarning::AMBIGUOUS_POSITION,
        CrewImportWarning::POSITION_DEPARTMENT_MISMATCH,
        CrewImportWarning::PERSON_IDENTITY_CONFLICT,
        CrewImportWarning::DUPLICATE_IN_FILE,
    ];

    /** @return array<string, mixed> */
    public function toArray(CrewImportProposal $proposal): array
    {
        try {
            $json = json_encode(
                $proposal,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CrewImportProposalCodecException(
                'Crew import proposal could not be serialized.',
                0,
                $exception
            );
        }

        if (!is_array($data)) {
            throw new CrewImportProposalCodecException('Crew import proposal payload must be an object.');
        }

        // Validate our own representation as well. This guarantees that what
        // we write is also accepted by the strict decoder.
        $this->fromArray($data);

        return $data;
    }

    public function toJson(CrewImportProposal $proposal): string
    {
        try {
            return json_encode(
                $this->toArray($proposal),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $exception) {
            throw new CrewImportProposalCodecException(
                'Crew import proposal could not be encoded as JSON.',
                0,
                $exception
            );
        }
    }

    public function fromJson(string $json): CrewImportProposal
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CrewImportProposalCodecException(
                'Crew import proposal JSON is invalid.',
                0,
                $exception
            );
        }

        if (!is_array($data)) {
            throw new CrewImportProposalCodecException('Crew import proposal payload must be an object.');
        }

        return $this->fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CrewImportProposal
    {
        $projectId = $this->positiveInt($data, 'projectId');
        $extractionStatus = $this->string($data, 'extractionStatus', 64);

        if (!in_array($extractionStatus, self::EXTRACTION_STATUSES, true)) {
            throw new CrewImportProposalCodecException('Invalid crew import extraction status.');
        }

        $peopleData = $this->list($data, 'people', self::MAX_PEOPLE);
        $people = [];

        foreach ($peopleData as $personData) {
            if (!is_array($personData)) {
                throw new CrewImportProposalCodecException('Crew import person payload must be an object.');
            }

            $sourceRowsData = $this->list(
                $personData,
                'sourceRows',
                self::MAX_SOURCE_ROWS_PER_PERSON
            );

            if ($sourceRowsData === []) {
                throw new CrewImportProposalCodecException('Crew import person requires at least one source row.');
            }

            $sourceRows = [];
            foreach ($sourceRowsData as $row) {
                if (!is_int($row) || $row <= 0) {
                    throw new CrewImportProposalCodecException('Invalid crew import source row.');
                }
                $sourceRows[] = $row;
            }

            $action = $this->string($personData, 'action', 32);
            if (!in_array($action, self::ACTIONS, true)) {
                throw new CrewImportProposalCodecException('Invalid crew import person action.');
            }

            $warningCodes = $this->warningList($personData, 'warningCodes');

            $assignmentsData = $this->list(
                $personData,
                'assignments',
                self::MAX_ASSIGNMENTS_PER_PERSON
            );
            $assignments = [];

            foreach ($assignmentsData as $assignmentData) {
                if (!is_array($assignmentData)) {
                    throw new CrewImportProposalCodecException('Crew import assignment payload must be an object.');
                }

                $resolutionStatus = $this->string($assignmentData, 'resolutionStatus', 64);
                if (!in_array($resolutionStatus, self::ASSIGNMENT_STATUSES, true)) {
                    throw new CrewImportProposalCodecException('Invalid crew import assignment status.');
                }

                $departmentId = $this->nullablePositiveInt($assignmentData, 'departmentId');
                $positionId = $this->nullablePositiveInt($assignmentData, 'positionId');

                if (
                    $resolutionStatus === CrewImportAssignmentProposal::NONE
                    && ($departmentId !== null || $positionId !== null)
                ) {
                    throw new CrewImportProposalCodecException('Empty assignment cannot contain catalog IDs.');
                }

                if (
                    $resolutionStatus === CrewImportAssignmentProposal::RESOLVED
                    && $departmentId === null
                ) {
                    throw new CrewImportProposalCodecException('Resolved assignment requires a department ID.');
                }

                $assignments[] = new CrewImportAssignmentProposal(
                    $this->positiveInt($assignmentData, 'sourceRow'),
                    $this->string($assignmentData, 'originalDepartment', 255),
                    $this->string($assignmentData, 'originalPosition', 255),
                    $departmentId,
                    $positionId,
                    $resolutionStatus,
                    $this->warningList($assignmentData, 'warningCodes'),
                );
            }

            $people[] = new CrewImportPersonProposal(
                $sourceRows,
                $this->string($personData, 'originalFullName', 512),
                $this->string($personData, 'name', 255),
                $this->string($personData, 'lastName', 255),
                $this->string($personData, 'email', 320),
                $this->string($personData, 'phone', 100),
                $this->nullablePositiveInt($personData, 'existingCrewMemberId'),
                $action,
                $this->bool($personData, 'reviewRequired'),
                $warningCodes,
                $assignments,
            );
        }

        return new CrewImportProposal($projectId, $extractionStatus, $people);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function warningList(array $data, string $key): array
    {
        $values = $this->list($data, $key, 32);
        $warnings = [];

        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, self::WARNINGS, true)) {
                throw new CrewImportProposalCodecException('Invalid crew import warning code.');
            }
            $warnings[] = $value;
        }

        return array_values(array_unique($warnings));
    }

    /** @param array<string, mixed> $data */
    private function positiveInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new CrewImportProposalCodecException(sprintf('"%s" must be a positive integer.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function nullablePositiveInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return $this->positiveInt($data, $key);
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key, int $maxLength): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new CrewImportProposalCodecException(sprintf('Invalid "%s" string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new CrewImportProposalCodecException(sprintf('"%s" must be boolean.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function list(array $data, string $key, int $maxItems): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            throw new CrewImportProposalCodecException(sprintf('Invalid "%s" list.', $key));
        }

        return $value;
    }
}
