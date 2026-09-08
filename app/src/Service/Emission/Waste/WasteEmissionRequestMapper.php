<?php

declare(strict_types=1);

namespace App\Service\Emission\Waste;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

final class WasteEmissionRequestMapper
{
    public function map(Request $request): WasteEmissionInput
    {
        $startDate = $this->date($request, 'startDate');
        $endDate = $this->date($request, 'endDate');
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException('endDate cannot be before startDate.');
        }
        if ($startDate->format('Y') !== $endDate->format('Y')) {
            throw new \InvalidArgumentException('Waste records must be split by year.');
        }

        return new WasteEmissionInput(
            startDate: $startDate,
            endDate: $endDate,
            country: $this->country($request),
            wasteType: $this->requiredString($request, 'wasteType'),
            wasteActivity: $this->optionalString($request, 'wasteActivity'),
            treatment: $this->optionalString($request, 'treatment'),
            weight: $this->requiredString($request, 'weight'),
            weightUnit: $this->weightUnit($request),
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

    private function country(Request $request): string
    {
        $iso3 = mb_strtoupper($this->requiredString($request, 'country'), 'UTF-8');
        if (!Countries::alpha3CodeExists($iso3)) {
            throw new \InvalidArgumentException('country must be a valid ISO-3 country code.');
        }

        return $iso3;
    }

    private function weightUnit(Request $request): string
    {
        $unit = $this->requiredString($request, 'weightUnit');
        if (!in_array($unit, WasteEmissionInput::weightUnits(), true)) {
            throw new \InvalidArgumentException('weightUnit must be kg or t.');
        }

        return $unit;
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
        if (null === $value || (is_string($value) && '' === trim($value))) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        return trim($value);
    }
}
