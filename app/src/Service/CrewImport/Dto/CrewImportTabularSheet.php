<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportTabularSheet implements \JsonSerializable
{
    /**
     * @param list<CrewImportTabularRow> $rows
     * @param list<CrewImportTabularRow>|null $contextRows
     * @param list<CrewImportTabularRow>|null $targetRows
     */
    public function __construct(
        public string $name,
        public array $rows,
        public ?array $contextRows = null,
        public ?array $targetRows = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        if ($this->contextRows !== null && $this->targetRows !== null) {
            return [
                'sheet' => $this->name,
                'contextRows' => $this->contextRows,
                'targetRows' => $this->targetRows,
            ];
        }

        return ['sheet' => $this->name, 'rows' => $this->rows];
    }
}
