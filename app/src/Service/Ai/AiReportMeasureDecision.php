<?php

namespace App\Service\Ai;

enum AiReportMeasureDecision: string
{
    case APPLIES = 'applies';
    case DOES_NOT_APPLY = 'does_not_apply';
    case IMPLEMENTED = 'implemented';
    case NOT_IMPLEMENTED = 'not_implemented';
}
