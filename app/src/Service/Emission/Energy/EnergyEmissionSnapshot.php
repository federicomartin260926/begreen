<?php

declare(strict_types=1);

namespace App\Service\Emission\Energy;

final class EnergyEmissionSnapshot
{
    public const VERSION = 'energy-v1';

    /** @param array<string, scalar|null> $presentation */
    public function encode(EnergyEmissionInput $input, EnergyEmissionResult $result, array $presentation = []): string
    {
        return json_encode([
            'version' => self::VERSION,
            'calculatorVersion' => self::VERSION,
            'family' => $input->family,
            'input' => $this->inputToArray($input),
            'calculation' => $result->toArray(),
            'presentation' => $presentation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function inputToArray(EnergyEmissionInput $input): array
    {
        return [
            'family' => $input->family,
            'startDate' => $input->startDate?->format('Y-m-d'),
            'endDate' => $input->endDate?->format('Y-m-d'),
            'country' => $input->country,
            'origin' => $input->origin,
            'amount' => $input->amount,
            'unit' => $input->unit,
            'initialReading' => $input->initialReading,
            'finalReading' => $input->finalReading,
            'gridKwh' => $input->gridKwh,
            'solarKwh' => $input->solarKwh,
            'supplier' => $input->supplier,
            'labeling' => $input->labeling,
            'equipmentType' => $input->equipmentType,
            'fuel' => $input->fuel,
            'mode' => $input->mode,
            'bottleSizeKg' => $input->bottleSizeKg,
            'bottleCount' => $input->bottleCount,
            'batteryType' => $input->batteryType,
            'chargeSource' => $input->chargeSource,
            'chargedKwh' => $input->chargedKwh,
            'digitalType' => $input->digitalType,
            'digitalLocation' => $input->digitalLocation,
            'digitalCountry' => $input->digitalCountry,
            'knownKwh' => $input->knownKwh,
            'hours' => $input->hours,
            'units' => $input->units,
            'gpu' => $input->gpu,
            'service' => $input->service,
            'model' => $input->model,
            'provider' => $input->provider,
            'ownership' => $input->ownership,
        ];
    }
}
