<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Accommodation;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Accommodation\AccommodationEmissionCalculator;
use App\Service\Emission\Accommodation\AccommodationEmissionInput;
use App\Service\Emission\Accommodation\AccommodationFactorResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use PHPUnit\Framework\TestCase;

final class AccommodationEmissionCalculatorTest extends TestCase
{
    private AccommodationEmissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = $this->calculator();
    }

    public function testHotelUsesOccupiedRoomsTimesNightsAndPreservesGeographicProxy(): void
    {
        $spain = $this->calculator->calculate($this->input(
            occupiedRooms: '2',
            nights: '3',
        ));
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $spain->status);
        self::assertSame('6', $spain->normalizedAmount);
        self::assertSame('occupied room-night', $spain->normalizedUnit);
        self::assertSame('57.303', $spain->emissionKgCo2e);

        $proxy = $this->calculator->calculate($this->input(
            year: 2022,
            iso3: 'AFG',
            occupiedRooms: '1',
            nights: '2',
        ));
        self::assertSame('75.406436', $proxy->emissionKgCo2e);
        self::assertTrue($proxy->factorTraces[0]->isGeographicProxy);
        self::assertSame('GLOBAL_STAR_FALLBACK', $proxy->factorTraces[0]->metadata['method']);
    }

    public function testHostelDerivesTravelAndClimateProxyFromHotelFourStarsAndInheritsFallback(): void
    {
        $result = $this->calculator->calculate($this->input(
            year: 2025,
            type: AccommodationEmissionInput::TYPE_HOSTEL,
            occupiedRooms: null,
            people: '2',
            nights: '3',
        ));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('6', $result->normalizedAmount);
        self::assertSame('guest-night', $result->normalizedUnit);
        self::assertSame('9.5505', $result->emissionKgCo2e);
        $trace = $result->factorTraces[0];
        self::assertSame('9.5505', $trace->baseFactorValue);
        self::assertSame('1.59175', $trace->effectiveFactorValue);
        self::assertSame('1.5', $trace->averageOccupancy);
        self::assertSame('0.25', $trace->hostelReductionFactor);
        self::assertSame(2024, $trace->factorYear);
        self::assertTrue($trace->isFallback);
        self::assertSame('exact_year_missing', $trace->fallbackReason);
        self::assertStringContainsString('Travel & Climate v5.1', $trace->proxyReason);
        self::assertStringContainsString('Greenview', $trace->source);
    }

    public function testApartmentUsesSingleVersionedLandFactorAndOtherHasNoAutomaticEmission(): void
    {
        $apartment = $this->calculator->calculate($this->input(
            type: AccommodationEmissionInput::TYPE_APARTMENT,
            stars: null,
            occupiedRooms: null,
            people: '3',
            nights: '2',
        ));
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $apartment->status);
        self::assertSame('24.522', $apartment->emissionKgCo2e);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $apartment->temporalType);
        self::assertNull($apartment->factorTraces[0]->factorYear);
        self::assertSame('Land 2025 · factor contextual de apartamento turístico', $apartment->factorTraces[0]->metadata['factorVersion']);
        self::assertTrue($apartment->factorTraces[0]->isGeographicProxy);

        $other = $this->calculator->calculate($this->input(
            type: AccommodationEmissionInput::TYPE_OTHER,
            stars: null,
            occupiedRooms: null,
            nights: null,
        ));
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $other->status);
        self::assertNull($other->emissionKgCo2e);
        self::assertNull($other->normalizedAmount);
        self::assertSame([], $other->factorTraces);
    }

    public function testInvalidDateRangesBecomePending(): void
    {
        $missing = $this->calculator->calculate(new AccommodationEmissionInput(
            null,
            new \DateTimeImmutable('2024-12-31'),
            'ESP',
            AccommodationEmissionInput::TYPE_HOTEL,
            '4',
            '1',
            '1',
            null,
        ));
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $missing->status);
        self::assertContains('dates_required', $missing->messages);

        $inverted = $this->calculator->calculate($this->input(
            startDate: new \DateTimeImmutable('2024-02-01'),
            endDate: new \DateTimeImmutable('2024-01-31'),
        ));
        self::assertContains('invalid_date_range', $inverted->messages);

        $crossYear = $this->calculator->calculate($this->input(
            startDate: new \DateTimeImmutable('2024-12-31'),
            endDate: new \DateTimeImmutable('2025-01-01'),
        ));
        self::assertContains('split_by_year', $crossYear->messages);
    }

    public function testZeroAndNegativeRequiredQuantitiesBecomePending(): void
    {
        foreach ([
            [$this->input(stars: null), 'stars_required'],
            [$this->input(stars: '1'), 'stars_unknown'],
            [$this->input(occupiedRooms: '0'), 'occupied_rooms_invalid'],
            [$this->input(occupiedRooms: '-1'), 'occupied_rooms_invalid'],
            [$this->input(nights: '0'), 'nights_invalid'],
            [$this->input(type: AccommodationEmissionInput::TYPE_HOSTEL, occupiedRooms: null, people: '-1'), 'people_invalid'],
            [$this->input(type: AccommodationEmissionInput::TYPE_HOSTEL, occupiedRooms: null, people: '1', nights: '0'), 'nights_invalid'],
            [$this->input(type: AccommodationEmissionInput::TYPE_APARTMENT, stars: null, occupiedRooms: null, people: '0'), 'people_invalid'],
            [$this->input(type: AccommodationEmissionInput::TYPE_APARTMENT, stars: null, occupiedRooms: null, people: '1', nights: '-2'), 'nights_invalid'],
        ] as [$input, $message]) {
            $result = $this->calculator->calculate($input);
            self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
            self::assertContains($message, $result->messages);
        }
    }

    private function input(
        int $year = 2024,
        string $iso3 = 'ESP',
        string $type = AccommodationEmissionInput::TYPE_HOTEL,
        ?string $stars = '4',
        ?string $occupiedRooms = '1',
        ?string $nights = '1',
        ?string $people = null,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null,
    ): AccommodationEmissionInput {
        return new AccommodationEmissionInput(
            $startDate ?? new \DateTimeImmutable($year.'-01-01'),
            $endDate ?? new \DateTimeImmutable($year.'-12-31'),
            $iso3,
            $type,
            $stars,
            $occupiedRooms,
            $nights,
            $people,
        );
    }

    private function calculator(): AccommodationEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [
            $this->annualFactor($keyGenerator, 'ESP', '4', 2024, '9.5505', false, 'HFT_PUBLISHED_COEFFICIENT'),
            $this->annualFactor($keyGenerator, 'AFG', '4', 2022, '37.703218', true, 'GLOBAL_STAR_FALLBACK'),
            $this->apartmentFactor($keyGenerator),
        ];
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use ($factors): ?EmissionFactor {
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    'accommodation' === $categoryKey
                    && EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                    && $factor->getFunctionalKey() === $functionalKey
                    && $factor->getYear() <= $activityYear
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findVersioned')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey) use ($factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('accommodation' === $categoryKey
                        && EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType()
                        && $factor->getFunctionalKey() === $functionalKey
                    ) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new AccommodationEmissionCalculator(
            new AccommodationFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)),
        );
    }

    private function annualFactor(
        EmissionFactorKeyGenerator $keyGenerator,
        string $iso3,
        string $stars,
        int $year,
        string $value,
        bool $geographicProxy,
        string $method,
    ): EmissionFactor {
        $criteria = ['accommodationType' => 'hotel', 'iso3' => $iso3, 'stars' => $stars, 'unit' => 'occupied room-night'];

        return (new EmissionFactor())
            ->setCategoryKey('accommodation')
            ->setFunctionalKey($keyGenerator->generate($criteria))
            ->setCriteria($criteria)
            ->setYear($year)
            ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL)
            ->setValue($value)
            ->setUnit('kgCO2e/occupied room-night')
            ->setSource('Greenview Hotel Footprinting Tool')
            ->setMetadata(['isGeographicProxy' => $geographicProxy, 'method' => $method]);
    }

    private function apartmentFactor(EmissionFactorKeyGenerator $keyGenerator): EmissionFactor
    {
        $criteria = ['accommodationType' => 'apartment', 'countryScope' => 'TODOS', 'unit' => 'persona-noche'];

        return (new EmissionFactor())
            ->setCategoryKey('accommodation')
            ->setFunctionalKey($keyGenerator->generate($criteria))
            ->setCriteria($criteria)
            ->setYear(2025)
            ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_VERSIONED)
            ->setValue('4.087')
            ->setUnit('kgCO2e/persona-noche')
            ->setSource('Calculating the Carbon Footprint of Urban Tourism Destinations (Land, 2025)')
            ->setMetadata([
                'activityYearScope' => '2022-2026',
                'factorVersion' => 'Land 2025 · factor contextual de apartamento turístico',
                'isGeographicProxy' => true,
            ]);
    }
}
