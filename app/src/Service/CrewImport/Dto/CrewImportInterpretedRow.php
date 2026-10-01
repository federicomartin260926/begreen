<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportInterpretedRow
{
    public const CREW = 'crew';
    public const NON_CREW = 'non_crew';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $sourceReference,
        public string $fullName,
        public string $proposedName,
        public string $proposedLastName,
        public string $email,
        public string $phone,
        public string $originalDepartment,
        public string $originalPosition,
        public string $rowKind,
        public ?int $departmentId,
        public ?int $positionId,
        public bool $catalogMismatch = false,
    ) {
    }
}
