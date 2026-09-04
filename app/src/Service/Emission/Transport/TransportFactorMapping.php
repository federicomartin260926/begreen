<?php

namespace App\Service\Emission\Transport;

final readonly class TransportFactorMapping
{
    /** @param array<string, string> $criteria */
    public function __construct(public array $criteria)
    {
    }
}
