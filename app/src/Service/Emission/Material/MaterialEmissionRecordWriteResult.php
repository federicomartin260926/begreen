<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionRecord;

final readonly class MaterialEmissionRecordWriteResult
{
    public function __construct(
        public MaterialEmissionResult $calculation,
        public EmissionRecord $record,
    ) {
    }
}
