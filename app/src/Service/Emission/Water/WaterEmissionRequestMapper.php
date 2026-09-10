<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Service\Emission\EmissionCountryCatalog;
use Symfony\Component\HttpFoundation\Request;

final class WaterEmissionRequestMapper
{
    private readonly EmissionCountryCatalog $countryCatalog;

    public function __construct(?EmissionCountryCatalog $countryCatalog = null)
    {
        $this->countryCatalog = $countryCatalog ?? new EmissionCountryCatalog();
    }

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
        return $this->countryCatalog->iso2FromIso3($this->requiredString($request, $field));
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
