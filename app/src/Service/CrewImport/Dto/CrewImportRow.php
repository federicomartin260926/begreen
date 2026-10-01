<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportRow implements \JsonSerializable
{
    public function __construct(
        public int $sourceRow,
        public string $name,
        public string $lastName,
        public string $position,
        public string $department,
        public string $email,
        public string $phone,
    ) {
    }

    public function originalFullName(): string
    {
        return trim($this->name.' '.$this->lastName);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
