<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use App\Entity\EmissionRecord;

final readonly class WasteEmissionRecordWriteResult
{
    public function __construct(
        public WasteEmissionResult $calculation,
        public EmissionRecord $record,
    ) {
    }
}
