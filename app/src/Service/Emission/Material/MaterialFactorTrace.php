<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

final readonly class MaterialFactorTrace
{
    public function __construct(
        public MaterialFactorResolution $resolution,
        public string $normalizedAmount,
        public string $normalizedUnit,
        public ?string $emissionKgCo2e,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->resolution->toArray(),
            'normalizedAmount' => $this->normalizedAmount,
            'normalizedUnit' => $this->normalizedUnit,
            'emissionKgCo2e' => $this->emissionKgCo2e,
        ];
    }
}
