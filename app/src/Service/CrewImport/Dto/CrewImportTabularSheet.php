<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportTabularSheet implements \JsonSerializable
{
    /** @param list<CrewImportTabularRow> $rows */
    public function __construct(public string $name, public array $rows)
    {
    }

    public function jsonSerialize(): array
    {
        return ['sheet' => $this->name, 'rows' => $this->rows];
    }
}
