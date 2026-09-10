<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

use App\Service\Emission\EmissionFactorResolver;

final readonly class AccommodationFactorResolver
{
    private const CATEGORY_KEY = 'accommodation';

    public function __construct(private EmissionFactorResolver $factorResolver)
    {
    }

    public function resolveHotel(string $iso3, string $stars, int $activityYear): AccommodationFactorResolution
    {
        $iso3 = strtoupper(trim($iso3));
        if (!preg_match('/^[A-Z]{3}$/', $iso3)) {
            throw new \InvalidArgumentException(sprintf('Unsupported accommodation ISO3 "%s".', $iso3));
        }
        if (!in_array($stars, AccommodationEmissionInput::hotelStars(), true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported hotel stars "%s".', $stars));
        }

        return AccommodationFactorResolution::fromResolution(
            $this->factorResolver->resolve(self::CATEGORY_KEY, $this->hotelCriteria($iso3, $stars), $activityYear),
            AccommodationEmissionInput::TYPE_HOTEL,
            requestedActivityYear: $activityYear,
        );
    }

    public function resolveApartment(int $activityYear): AccommodationFactorResolution
    {
        $resolution = $this->factorResolver->resolveVersioned(
            self::CATEGORY_KEY,
            $this->apartmentCriteria(),
            $activityYear,
        );
        $factor = $resolution->factor;
        $scope = $factor?->getMetadata()['activityYearScope'] ?? null;
        if (null !== $factor && (!$this->yearIsInScope($activityYear, $scope))) {
            $resolution = new \App\Service\Emission\EmissionFactorResolution(
                null,
                $activityYear,
                null,
                false,
                null,
                \App\Entity\EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            );
        }

        return AccommodationFactorResolution::fromResolution(
            $resolution,
            AccommodationEmissionInput::TYPE_APARTMENT,
            'Land 2025 contextual apartment factor for all countries',
            $activityYear,
        );
    }

    /** @return array<string, string> */
    private function hotelCriteria(string $iso3, string $stars): array
    {
        return [
            'accommodationType' => 'hotel',
            'iso3' => $iso3,
            'stars' => $stars,
            'unit' => 'occupied room-night',
        ];
    }

    /** @return array<string, string> */
    private function apartmentCriteria(): array
    {
        return [
            'accommodationType' => 'apartment',
            'countryScope' => 'TODOS',
            'unit' => 'persona-noche',
        ];
    }

    private function yearIsInScope(int $year, mixed $scope): bool
    {
        if (!is_string($scope) || !preg_match('/^(\d{4})-(\d{4})$/', $scope, $matches)) {
            return false;
        }

        return $year >= (int) $matches[1] && $year <= (int) $matches[2];
    }
}
