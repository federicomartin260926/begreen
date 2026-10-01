<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportAssignmentProposal implements \JsonSerializable
{
    public const NONE = 'none';
    public const RESOLVED = 'resolved';
    public const UNKNOWN_DEPARTMENT = 'unknown_department';
    public const UNKNOWN_POSITION = 'unknown_position';
    public const AMBIGUOUS_POSITION = 'ambiguous_position';
    public const POSITION_DEPARTMENT_MISMATCH = 'position_department_mismatch';

    /** @param list<string> $warningCodes */
    public function __construct(
        public int $sourceRow,
        public string $originalDepartment,
        public string $originalPosition,
        public ?int $departmentId,
        public ?int $positionId,
        public string $resolutionStatus,
        public array $warningCodes = [],
    ) {
    }

    public function isResolved(): bool
    {
        return in_array($this->resolutionStatus, [self::NONE, self::RESOLVED], true);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
