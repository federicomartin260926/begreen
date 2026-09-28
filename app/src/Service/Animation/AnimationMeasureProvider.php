<?php

namespace App\Service\Animation;

use App\Entity\Measure;
use App\Entity\Protocol;

interface AnimationMeasureProvider
{
    /** @return list<Measure> */
    public function forProtocol(Protocol $protocol): array;
}
