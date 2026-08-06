<?php

namespace App\Service\Ai;

enum AiReportMeasureDecision: string
{
    case NOT_APPLICABLE = 'not_applicable';
    case PLANNED = 'planned';
    case NOT_PLANNED = 'not_planned';
}
