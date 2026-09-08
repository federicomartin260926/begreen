<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

final class AccommodationEmissionRequestMapper
{
    public function map(Request $request): AccommodationEmissionInput
    {
        $startDate = $this->date($request, 'startDate');
        $endDate = $this->date($request, 'endDate');
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException('endDate cannot be before startDate.');
        }
        if ($startDate->format('Y') !== $endDate->format('Y')) {
            throw new \InvalidArgumentException('Accommodation records must be split by year.');
        }

        return new AccommodationEmissionInput(
            startDate: $startDate,
            endDate: $endDate,
            iso3: $this->country($request),
            accommodationType: $this->requiredString($request, 'accommodationType'),
            stars: $this->optionalString($request, 'stars'),
            occupiedRooms: $this->optionalString($request, 'occupiedRooms'),
            nights: $this->optionalString($request, 'nights'),
            people: $this->optionalString($request, 'people'),
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
