<?php

namespace App\Service\CrewImport\Dto;

final readonly class CrewImportExtraction implements \JsonSerializable
{
    public const OFFICIAL_TEMPLATE = 'official_template';
    public const UNSUPPORTED_TEMPLATE = 'unsupported_template';
    public const READ_ERROR = 'read_error';

    /** @param list<CrewImportRow> $rows */
    public function __construct(
        public string $status,
        public array $rows = [],
    ) {
    }

    public function isOfficialTemplate(): bool
    {
        return $this->status === self::OFFICIAL_TEMPLATE;
    }

    public function jsonSerialize(): array
    {
        return ['status' => $this->status, 'rows' => $this->rows];
    }
}
