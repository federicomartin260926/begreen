<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

use App\Entity\EmissionRecord;

final readonly class AccommodationEmissionRecordWriteResult
{
    public function __construct(
        public AccommodationEmissionResult $calculation,
        public EmissionRecord $record,
    ) {
    }
}
