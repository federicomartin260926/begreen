<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

use App\Entity\EmissionRecord;

final readonly class CateringEmissionRecordWriteResult
{
    public function __construct(
        public CateringEmissionResult $calculation,
        public EmissionRecord $record,
    ) {
    }
}
