<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportTabularRow implements \JsonSerializable
{
    /** @param list<string> $cells */
    public function __construct(public int $rowNumber, public array $cells)
    {
    }

    public function jsonSerialize(): array
    {
        return ['row' => $this->rowNumber, 'cells' => $this->cells];
    }
}
