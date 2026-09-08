<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use App\Entity\EmissionRecord;

final readonly class WasteEmissionResult
{
    /** @param list<WasteFactorTrace> $factorTraces
     *  @param list<string> $messages
     */
    public function __construct(
        public string $status,
        public ?string $emissionKgCo2e,
        public ?string $normalizedAmount,
        public ?string $normalizedUnit,
        public ?int $activityYear,
        public ?string $resolvedWasteActivity = null,
        public ?string $resolvedTreatment = null,
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
            'resolvedWasteActivity' => $this->resolvedWasteActivity,
            'resolvedTreatment' => $this->resolvedTreatment,
            'messages' => $this->messages,
            'factorTraces' => array_map(
                static fn (WasteFactorTrace $trace): array => $trace->toArray(),
                $this->factorTraces,
            ),
        ];
    }
}
