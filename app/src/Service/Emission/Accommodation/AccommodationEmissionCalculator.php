<?php

declare(strict_types=1);

namespace App\Service\Emission\Accommodation;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;

final readonly class AccommodationEmissionCalculator
{
    private const SCALE = 18;
    private const HOTEL_UNIT = 'occupied room-night';
    private const GUEST_UNIT = 'guest-night';
    private const PERSON_UNIT = 'persona-noche';
    private const AVERAGE_OCCUPANCY = '1.5';
    private const HOSTEL_REDUCTION_FACTOR = '0.25';

    public function __construct(private AccommodationFactorResolver $factorResolver)
    {
    }

    public function calculate(AccommodationEmissionInput $input): AccommodationEmissionResult
    {
        $dateValidation = $this->validateDates($input);
        if ($dateValidation instanceof AccommodationEmissionResult) {
            return $dateValidation;
        }

        $activityYear = (int) $input->startDate->format('Y');
        $iso3 = strtoupper(trim((string) $input->iso3));
        if ('' === $iso3) {
            return $this->pending($activityYear, ['country_required']);
        }
        if (!preg_match('/^[A-Z]{3}$/', $iso3)) {
            return $this->pending($activityYear, ['country_invalid']);
        }
        if (null === $input->accommodationType || '' === trim($input->accommodationType)) {
            return $this->pending($activityYear, ['accommodation_type_required']);
        }
        if (!in_array($input->accommodationType, AccommodationEmissionInput::accommodationTypes(), true)) {
            return $this->pending($activityYear, ['accommodation_type_unknown']);
        }

        return match ($input->accommodationType) {
            AccommodationEmissionInput::TYPE_HOTEL => $this->calculateHotel($input, $iso3, $activityYear),
            AccommodationEmissionInput::TYPE_HOSTEL => $this->calculateHostel($input, $iso3, $activityYear),
            AccommodationEmissionInput::TYPE_APARTMENT => $this->calculateApartment($input, $activityYear),
            AccommodationEmissionInput::TYPE_OTHER => $this->notAutomaticallyCalculable(
                $activityYear,
                null,
                null,
                null,
                [],
                ['automatic_factor_unavailable'],
            ),
        };
    }

    private function calculateHotel(AccommodationEmissionInput $input, string $iso3, int $activityYear): AccommodationEmissionResult
    {
        if (null === $input->stars || '' === trim($input->stars)) {
            return $this->pending($activityYear, ['stars_required']);
        }
        if (!in_array($input->stars, AccommodationEmissionInput::hotelStars(), true)) {
            return $this->pending($activityYear, ['stars_unknown']);
        }
        $rooms = $this->positiveDecimal($input->occupiedRooms);
        if (null === $rooms) {
            return $this->pending($activityYear, ['occupied_rooms_invalid']);
        }
        $nights = $this->positiveDecimal($input->nights);
        if (null === $nights) {
            return $this->pending($activityYear, ['nights_invalid']);
        }

        $amount = $this->multiply($rooms, $nights);
        $resolution = $this->factorResolver->resolveHotel($iso3, $input->stars, $activityYear);

        return $this->calculatedFromResolution(
            $resolution,
            AccommodationEmissionInput::TYPE_HOTEL,
            $amount,
            self::HOTEL_UNIT,
            $resolution->factorValue,
            'kgCO2e/occupied room-night',
        );
    }

    private function calculateHostel(AccommodationEmissionInput $input, string $iso3, int $activityYear): AccommodationEmissionResult
    {
        $people = $this->positiveDecimal($input->people);
        if (null === $people) {
            return $this->pending($activityYear, ['people_invalid']);
        }
        $nights = $this->positiveDecimal($input->nights);
        if (null === $nights) {
            return $this->pending($activityYear, ['nights_invalid']);
        }

        $amount = $this->multiply($people, $nights);
        $resolution = $this->factorResolver->resolveHotel($iso3, '4', $activityYear);
        $effectiveFactor = null === $resolution->factorValue
            ? null
            : $this->multiply(
                $this->divide($resolution->factorValue, self::AVERAGE_OCCUPANCY),
                self::HOSTEL_REDUCTION_FACTOR,
            );

        return $this->calculatedFromResolution(
            $resolution,
            AccommodationEmissionInput::TYPE_HOSTEL,
            $amount,
            self::GUEST_UNIT,
            $effectiveFactor,
            'kgCO2e/guest-night',
            self::AVERAGE_OCCUPANCY,
            self::HOSTEL_REDUCTION_FACTOR,
            'Travel & Climate v5.1 proxy derived from the Greenview hotel 4-star factor',
        );
    }

    private function calculateApartment(AccommodationEmissionInput $input, int $activityYear): AccommodationEmissionResult
    {
        $people = $this->positiveDecimal($input->people);
        if (null === $people) {
            return $this->pending($activityYear, ['people_invalid']);
        }
        $nights = $this->positiveDecimal($input->nights);
        if (null === $nights) {
            return $this->pending($activityYear, ['nights_invalid']);
        }

        $amount = $this->multiply($people, $nights);
        $resolution = $this->factorResolver->resolveApartment($activityYear);

        return $this->calculatedFromResolution(
            $resolution,
            AccommodationEmissionInput::TYPE_APARTMENT,
            $amount,
            self::PERSON_UNIT,
            $resolution->factorValue,
            'kgCO2e/persona-noche',
        );
    }

    private function calculatedFromResolution(
        AccommodationFactorResolution $resolution,
        string $accommodationType,
        string $amount,
        string $amountUnit,
        ?string $effectiveFactor,
        string $effectiveFactorUnit,
        ?string $averageOccupancy = null,
        ?string $hostelReductionFactor = null,
        ?string $proxyReason = null,
    ): AccommodationEmissionResult {
        if (!$resolution->hasFactor() || !$resolution->isCalculable() || null === $effectiveFactor) {
            $trace = AccommodationFactorTrace::fromResolution(
                $resolution,
                $accommodationType,
                $amount,
                $amountUnit,
                $effectiveFactor,
                $effectiveFactorUnit,
                null,
                $averageOccupancy,
                $hostelReductionFactor,
                $proxyReason,
            );

            return $this->notAutomaticallyCalculable(
                $resolution->activityYear,
                $amount,
                $amountUnit,
                $resolution->temporalType,
                [$trace],
                [$resolution->hasFactor() ? 'emission_factor_not_calculable' : 'emission_factor_unavailable'],
            );
        }

        $emission = $this->multiply($amount, $effectiveFactor);
        $trace = AccommodationFactorTrace::fromResolution(
            $resolution,
            $accommodationType,
            $amount,
            $amountUnit,
            $effectiveFactor,
            $effectiveFactorUnit,
            $emission,
            $averageOccupancy,
            $hostelReductionFactor,
            $proxyReason,
        );

        return new AccommodationEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            $emission,
            $amount,
            $amountUnit,
            $resolution->activityYear,
            $resolution->temporalType,
            [$trace],
        );
    }

    private function validateDates(AccommodationEmissionInput $input): ?AccommodationEmissionResult
    {
        if (null === $input->startDate || null === $input->endDate) {
            return $this->pending(null, ['dates_required']);
        }

        $activityYear = (int) $input->startDate->format('Y');
        if ($input->endDate < $input->startDate) {
            return $this->pending($activityYear, ['invalid_date_range']);
        }
        if ($activityYear !== (int) $input->endDate->format('Y')) {
            return $this->pending($activityYear, ['split_by_year']);
        }

        return null;
    }

    private function positiveDecimal(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) || bccomp($value, '0', self::SCALE) <= 0) {
            return null;
        }

        return $this->trimDecimal($value);
    }

    private function multiply(string $left, string $right): string
    {
        return $this->trimDecimal(bcmul($left, $right, self::SCALE));
    }

    private function divide(string $left, string $right): string
    {
        return $this->trimDecimal(bcdiv($left, $right, self::SCALE));
    }

    private function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return '' === $value || '-0' === $value ? '0' : $value;
    }

    /** @param list<string> $messages */
    private function pending(?int $activityYear, array $messages): AccommodationEmissionResult
    {
        return new AccommodationEmissionResult(
            EmissionRecord::STATUS_PENDING_DATA,
            null,
            null,
            null,
            $activityYear,
            null,
            messages: $messages,
        );
    }

    /** @param list<AccommodationFactorTrace> $traces
     *  @param list<string> $messages
     */
    private function notAutomaticallyCalculable(
        int $activityYear,
        ?string $amount,
        ?string $amountUnit,
        ?string $temporalType,
        array $traces,
        array $messages,
    ): AccommodationEmissionResult {
        return new AccommodationEmissionResult(
            EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE,
            null,
            $amount,
            $amountUnit,
            $activityYear,
            $temporalType,
            $traces,
            $messages,
        );
    }
}
