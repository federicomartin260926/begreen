<?php

namespace App\Enum;

/**
 * CommercialPhase identifies the platform's functional/commercial scope.
 * ProjectPhaseDate keeps representing preproduction, production and postproduction.
 */
enum CommercialPhase: string
{
    case ELABORATION = 'elaboration';
    case SIGNAGE = 'signage';
    case IMPLEMENTATION = 'implementation';
    case CO2 = 'co2';
    case REPORT = 'report';
    case COMPENSATION = 'compensation';
    case CERTIFICATION = 'certification';
}
