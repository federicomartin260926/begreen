<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

final class MaterialEmissionRequestMapper
{
    private const OPTIONAL_FIELDS = [
        'subproduct', 'origin', 'inputQuantity', 'inputUnit', 'woodType',
        'boardFamily', 'boardThickness', 'lengthMeters', 'widthMeters',
        'thicknessMeters', 'unitCount', 'pieceWeightKg', 'grammageGm2',
        'paperFormat', 'sheetsPerPackage', 'cardboardType',
        'batteryChemistry', 'batterySize',
    ];

    public function __construct(private readonly MaterialUiCatalog $catalog)
    {
    }

    public function map(Request $request): MaterialEmissionInput
    {
        $startDate = $this->date($request, 'startDate');
        $endDate = $this->date($request, 'endDate');
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException('endDate cannot be before startDate.');
        }

        $optional = [];
        foreach (self::OPTIONAL_FIELDS as $field) {
            $optional[$field] = $this->optionalString($request, $field);
        }
        $activity = $this->requiredString($request, 'activity');
        $family = $this->catalog->familyForActivity($activity);
        $requestedFamily = $this->optionalString($request, 'family');
        if (null !== $requestedFamily && $family !== $requestedFamily) {
            throw new \InvalidArgumentException('family does not match activity.');
        }

        return new MaterialEmissionInput(
            startDate: $startDate,
            endDate: $endDate,
            country: $this->country($request),
            activity: $activity,
            subproduct: $optional['subproduct'],
            origin: $optional['origin'],
            measurementMethod: $this->requiredString($request, 'measurementMethod'),
            inputQuantity: $optional['inputQuantity'],
            inputUnit: $optional['inputUnit'],
            woodType: $optional['woodType'],
            boardFamily: $optional['boardFamily'],
            boardThickness: $optional['boardThickness'],
            lengthMeters: $optional['lengthMeters'],
            widthMeters: $optional['widthMeters'],
            thicknessMeters: $optional['thicknessMeters'],
            unitCount: $optional['unitCount'],
            pieceWeightKg: $optional['pieceWeightKg'],
            grammageGm2: $optional['grammageGm2'],
            paperFormat: $optional['paperFormat'],
            sheetsPerPackage: $optional['sheetsPerPackage'],
            cardboardType: $optional['cardboardType'],
            batteryChemistry: $optional['batteryChemistry'],
            batterySize: $optional['batterySize'],
            family: $family,
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
        $country = mb_strtoupper($this->requiredString($request, 'country'), 'UTF-8');
        if (!Countries::alpha3CodeExists($country)) {
            throw new \InvalidArgumentException('country must be a valid ISO-3 country code.');
        }

        return $country;
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
