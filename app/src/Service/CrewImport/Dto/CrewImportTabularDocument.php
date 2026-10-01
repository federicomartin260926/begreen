<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportTabularDocument implements \JsonSerializable
{
    /** @param list<CrewImportTabularSheet> $sheets */
    public function __construct(public array $sheets)
    {
    }

    public function jsonSerialize(): array
    {
        return ['sheets' => $this->sheets];
    }
}
