<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

final class WaterEmissionRequestMapper
{
    public function map(Request $request): WaterEmissionInput
    {
        return new WaterEmissionInput(
            startDate: $this->date($request, 'startDate'),
            endDate: $this->date($request, 'endDate'),
            country: $this->country($request, 'country'),
            waterUseType: $this->requiredString($request, 'waterUseType'),
            volumeInput: $this->requiredString($request, 'volumeInput'),
            volumeInputUnit: $this->requiredString($request, 'volumeInputUnit'),
            destination: $this->requiredString($request, 'destination'),
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

    private function requiredString(Request $request, string $field): string
    {
        $value = $request->request->get($field);
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }

        return trim($value);
    }
}
