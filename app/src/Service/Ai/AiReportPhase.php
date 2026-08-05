<?php

namespace App\Service\Ai;

enum AiReportPhase: string
{
    case ELABORATION = 'elaboration';
    case IMPLEMENTATION = 'implementation';
}
