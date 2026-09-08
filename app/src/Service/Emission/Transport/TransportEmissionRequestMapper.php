<?php

namespace App\Service\Emission\Transport;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

final class TransportEmissionRequestMapper
{
    public function map(Request $request): TransportEmissionInput
    {
        $startDate = $this->requiredDate($request, 'startDate');
        $endDate = $this->requiredDate($request, 'endDate');
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException('endDate must be greater than or equal to startDate.');
        }

        $country = strtoupper($this->requiredString($request, 'country'));
        if (
            1 !== preg_match('/^[A-Z]{2}$/', $country)
            || !Countries::exists($country)
        ) {
            throw new \InvalidArgumentException('country must be a valid ISO-2 country code.');
        }

        return new TransportEmissionInput(
            $this->requiredString($request, 'category'),
            $this->requiredString($request, 'mode'),
            $this->requiredString($request, 'method'),
            $country,
            $startDate,
            $endDate,
            $this->requiredString($request, 'activityValue'),
            $this->requiredString($request, 'activityUnit'),
            $this->optionalString($request, 'repetitions') ?? '1',
            $this->optionalString($request, 'passengers'),
            $this->optionalString($request, 'weightValue'),
            $this->optionalString($request, 'weightUnit'),
            $this->optionalString($request, 'vehicleType'),
            $this->optionalString($request, 'carSize'),
            $this->optionalString($request, 'fuel'),
            $this->optionalString($request, 'thermalFuel'),
            $this->optionalString($request, 'routeClassification'),
            $this->optionalString($request, 'travelClass'),
        );
    }

    private function requiredDate(Request $request, string $field): \DateTimeImmutable
    {
        $value = $this->requiredString($request, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new \InvalidArgumentException(sprintf('%s must be a valid YYYY-MM-DD date.', $field));
        }

        return $date;
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

        return $value;
    }
}
