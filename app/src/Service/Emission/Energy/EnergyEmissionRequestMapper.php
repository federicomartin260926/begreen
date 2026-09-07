<?php

namespace App\Service\Emission\Energy;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

final class EnergyEmissionRequestMapper
{
    public function map(Request $request): EnergyEmissionInput
    {
        return new EnergyEmissionInput(
            family: $this->requiredString($request, 'family'),
            startDate: $this->date($request, 'startDate'),
            endDate: $this->date($request, 'endDate'),
            country: $this->country($request, 'country'),
            origin: $this->optionalString($request, 'origin'),
            amount: $this->optionalString($request, 'amount'),
            unit: $this->optionalString($request, 'unit'),
            initialReading: $this->optionalString($request, 'initialReading'),
            finalReading: $this->optionalString($request, 'finalReading'),
            gridKwh: $this->optionalString($request, 'gridKwh'),
            solarKwh: $this->optionalString($request, 'solarKwh'),
            supplier: $this->optionalString($request, 'supplier'),
            labeling: $this->optionalString($request, 'labeling'),
            equipmentType: $this->optionalString($request, 'equipmentType'),
            fuel: $this->optionalString($request, 'fuel'),
            mode: $this->optionalString($request, 'mode') ?? EnergyEmissionInput::EQUIPMENT_MODE_DIRECT,
            bottleSizeKg: $this->optionalString($request, 'bottleSizeKg'),
            bottleCount: $this->optionalString($request, 'bottleCount'),
            batteryType: $this->optionalString($request, 'batteryType'),
            chargeSource: $this->optionalString($request, 'chargeSource'),
            chargedKwh: $this->optionalString($request, 'chargedKwh'),
            digitalType: $this->optionalString($request, 'digitalType'),
            digitalLocation: $this->optionalString($request, 'digitalLocation'),
            digitalCountry: $this->optionalCountry($request, 'digitalCountry'),
            knownKwh: $this->optionalString($request, 'knownKwh'),
            hours: $this->optionalString($request, 'hours'),
            units: $this->optionalString($request, 'units'),
            gpu: $this->optionalString($request, 'gpu'),
            service: $this->optionalString($request, 'service'),
            model: $this->optionalString($request, 'model'),
            provider: $this->optionalString($request, 'provider'),
            ownership: $this->optionalString($request, 'ownership'),
        );
    }

    private function date(Request $request, string $field): \DateTimeImmutable
    {
        $value = $this->requiredString($request, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new \InvalidArgumentException(sprintf('%s must be a valid YYYY-MM-DD date.', $field));
        }

        return $date;
    }

    private function country(Request $request, string $field): string
    {
        $country = mb_strtoupper($this->requiredString($request, $field), 'UTF-8');
        if (1 !== preg_match('/^[A-Z]{2}$/', $country) || !Countries::exists($country)) {
            throw new \InvalidArgumentException(sprintf('%s must be a valid ISO-2 country code.', $field));
        }

        return $country;
    }

    private function optionalCountry(Request $request, string $field): ?string
    {
        return null === $this->optionalString($request, $field) ? null : $this->country($request, $field);
    }

    private function requiredString(Request $request, string $field): string
    {
        $value = $this->optionalString($request, $field);
        if (null === $value) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }

        return $value;
    }

    private function optionalString(Request $request, string $field): ?string
    {
        $value = $request->request->get($field);
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
