<?php

namespace App\Service\Emission\Transport;

use App\Entity\EmissionRecord;

final readonly class TransportEmissionRecordWriteResult
{
    public function __construct(
        public TransportEmissionResult $calculation,
        public ?EmissionRecord $record,
    ) {
    }

    public function isPersisted(): bool
    {
        return null !== $this->record;
    }
}
