<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportTabularRow implements \JsonSerializable
{
    /** @param list<string> $cells */
    public function __construct(
        public int $rowNumber,
        public array $cells,
        public string $sourceReference = '',
    )
    {
    }

    public function jsonSerialize(): array
    {
        $data = ['row' => $this->rowNumber, 'cells' => $this->cells];
        if ($this->sourceReference !== '') {
            $data['sourceReference'] = $this->sourceReference;
        }

        return $data;
    }
}
