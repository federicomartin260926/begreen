<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportProposal implements \JsonSerializable
{
    /** @param list<CrewImportPersonProposal> $people */
    public function __construct(
        public int $projectId,
        public string $extractionStatus,
        public array $people,
    ) {
    }

    public function isApplicable(): bool
    {
        if ($this->extractionStatus !== CrewImportExtraction::OFFICIAL_TEMPLATE) {
            return false;
        }

        foreach ($this->people as $person) {
            if (!$person->isConfirmable()) {
                return false;
            }
        }

        return true;
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
