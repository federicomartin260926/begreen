<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportPersonProposal implements \JsonSerializable
{
    public const CREATE = 'create';
    public const ASSOCIATE = 'associate';
    public const REVIEW = 'review';
    public const CONFLICT = 'conflict';
    public const OMIT = 'omit';

    /**
     * @param list<int> $sourceRows
     * @param list<string> $warningCodes
     * @param list<CrewImportAssignmentProposal> $assignments
     */
    public function __construct(
        public array $sourceRows,
        public string $originalFullName,
        public string $name,
        public string $lastName,
        public string $email,
        public string $phone,
        public ?int $existingCrewMemberId,
        public string $action,
        public bool $reviewRequired,
        public array $warningCodes,
        public array $assignments,
    ) {
    }

    public function isConfirmable(): bool
    {
        return !$this->reviewRequired && in_array($this->action, [self::CREATE, self::ASSOCIATE], true);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
