<?php

namespace App\Service\Emission\Energy;

use App\Entity\EmissionRecord;

final readonly class EnergyEmissionRecordWriteResult
{
    public function __construct(
        public EnergyEmissionResult $calculation,
        public EmissionRecord $record,
    ) {
    }
}
