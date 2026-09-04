<?php

namespace App\Service\Emission\Transport;

use Symfony\Component\HttpFoundation\Request;

final class TransportEmissionRequestMapper
{
    public function map(Request $request): TransportEmissionInput
    {
        $startedAtValue = $this->requiredString($request, 'startedAt');
        $startedAt = \DateTimeImmutable::createFromFormat('!Y-m-d', $startedAtValue);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$startedAt || (is_array($dateErrors) && (0 !== $dateErrors['warning_count'] || 0 !== $dateErrors['error_count']))) {
            throw new \InvalidArgumentException('startedAt must be a valid YYYY-MM-DD date.');
        }

        $country = strtoupper($this->requiredString($request, 'country'));
        if (1 !== preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException('country must be an ISO-2 code.');
        }

        return new TransportEmissionInput(
            $this->requiredString($request, 'category'),
            $this->requiredString($request, 'mode'),
            $this->requiredString($request, 'method'),
            $country,
            $startedAt,
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
