<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\EmissionRecord;

final readonly class MaterialEmissionResult
{
    /** @param list<MaterialFactorTrace> $factorTraces
     *  @param list<string> $messages
     */
    public function __construct(
        public string $status,
        public ?string $emissionKgCo2e,
        public ?string $normalizedAmount,
        public ?string $normalizedUnit,
        public ?int $activityYear,
        public array $factorTraces = [],
        public array $messages = [],
    ) {
    }

    public function isCalculated(): bool
    {
        return EmissionRecord::STATUS_CALCULATED === $this->status;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'emissionKgCo2e' => $this->emissionKgCo2e,
            'normalizedAmount' => $this->normalizedAmount,
            'normalizedUnit' => $this->normalizedUnit,
            'activityYear' => $this->activityYear,
            'messages' => $this->messages,
            'factorTraces' => array_map(
                static fn (MaterialFactorTrace $trace): array => $trace->toArray(),
                $this->factorTraces,
            ),
        ];
    }
}
