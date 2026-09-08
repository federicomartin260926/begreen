<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Entity\EmissionRecord;

final readonly class WaterEmissionRecordWriteResult
{
    public function __construct(
        public WaterEmissionResult $calculation,
        public EmissionRecord $record,
    ) {
    }
}
