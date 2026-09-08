<?php

namespace App\Tests\Service\Emission\Transport;

use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Transport\TransportEmissionCalculator;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionResult;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use PHPUnit\Framework\TestCase;

final class TransportEmissionCalculatorTest extends TestCase
{
    private TransportEmissionCalculator $calculator;

    protected function setUp(): void
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            fn (string $category, string $key, int $year): ?EmissionFactor => $this->factor($category, $key, $year, $keyGenerator),
        );
        $this->calculator = new TransportEmissionCalculator(
            new TransportFactorCriteriaMapper(),
            new EmissionFactorResolver($repository, $keyGenerator),
            $keyGenerator,
        );
    }

    public function testMetroUsesRealV20FactorsAndPassengerDistanceFormula(): void
    {
        $spain = $this->calculate('metro', 'route', 'ES', '10', 'km', passengers: '2', repetitions: '3');
        self::assertSame('0.03828', $spain->factorValue);
        self::assertNotSame('0.07956', $spain->factorValue);
        self::assertSame('60', $spain->normalizedActivityValue);
        self::assertSame('2.2968', $spain->generatedKgCo2e);

        $outside = $this->calculate('metro', 'route', 'FR', '10', 'km', passengers: '2');
        self::assertSame('0.01549', $outside->factorValue);
        self::assertNotSame('0.12552', $outside->factorValue);
    }

    public function testAggregatedPassengerKilometresAreNotMultipliedByPassengersAgain(): void
    {
        $result = $this->calculate('metro', 'passenger_distance', 'ES', '100', 'passenger-km', passengers: '40', repetitions: '2');
        self::assertSame('200', $result->normalizedActivityValue);
        self::assertSame('7.656', $result->generatedKgCo2e);
    }

    public function testVehicleKilometresDoNotMultiplyPassengers(): void
    {
        $result = $this->calculate('car', 'distance', 'ES', '10', 'km', passengers: '4', repetitions: '2', vehicleType: 'petrol');
        self::assertSame('20', $result->normalizedActivityValue);
    }

    public function testTonneKilometresAndWeightDistanceAreNormalized(): void
    {
        $tonneKm = $this->calculate('freight_train', 'tonne_km', 'ES', '10', 't-mi', repetitions: '2');
        self::assertSame('32.18688', $tonneKm->normalizedActivityValue);

        $weightDistance = $this->calculate(
            'freight_train', 'weight_distance', 'ES', '10', 'mi', repetitions: '2', weightValue: '1000', weightUnit: 'kg',
        );
        self::assertSame('32.18688', $weightDistance->normalizedActivityValue);
    }

    public function testFuelUsGallonsUseDecimalArithmeticAndRepetitions(): void
    {
        $result = $this->calculate('car', 'fuel', 'ES', '2', 'us_gal', repetitions: '3', vehicleType: 'petrol', fuel: 'petrol');
        self::assertSame('22.712470704', $result->normalizedActivityValue);
        self::assertSame('51.080346613296', $result->generatedKgCo2e);
        self::assertSame(TransportEmissionCalculator::DECIMAL_SCALE, 18);
        self::assertSame(TransportEmissionResult::STATUS_CALCULATED, $result->status);
    }

    public function testDirectAndUnsupportedPathsHaveExplicitStatuses(): void
    {
        $operator = $this->calculate('plane', 'operator', 'ES', '1.25', 't_co2e', repetitions: '2');
        self::assertSame(TransportEmissionResult::STATUS_DIRECT_OPERATOR_EMISSION, $operator->status);
        self::assertSame('2500', $operator->generatedKgCo2e);
        self::assertSame('operator', $operator->source);

        self::assertSame(
            TransportEmissionResult::STATUS_EXTERNAL_FACTOR_REQUIRED,
            $this->calculate('car', 'electricity', 'ES', '10', 'kWh', vehicleType: 'bev')->status,
        );
        self::assertSame(
            TransportEmissionResult::STATUS_EXTERNAL_FACTOR_REQUIRED,
            $this->calculate('car', 'fuel_and_electricity', 'ES', '10', 'l', vehicleType: 'phev')->status,
        );
        self::assertSame(
            TransportEmissionResult::STATUS_UNSUPPORTED,
            $this->calculate('car', 'fuel', 'ES', '10', 'm³', vehicleType: 'petrol', fuel: 'petrol')->status,
        );
        self::assertSame(
            TransportEmissionResult::STATUS_DIRECT_ZERO,
            $this->calculate('walk', 'distance', 'ES', '5', 'km')->status,
        );
        self::assertSame(
            TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE,
            $this->calculate('minibus', 'distance', 'ES', '5', 'km')->status,
        );
    }

    public function testCarAveragePhevAndBevZeroRemainCalculable(): void
    {
        $phev = $this->calculate('car', 'distance', 'FR', '10', 'km', vehicleType: 'phev', carSize: 'average');
        self::assertSame('0.08959', $phev->factorValue);

        $bev = $this->calculate('car', 'distance', 'FR', '10', 'km', vehicleType: 'bev', carSize: 'small');
        self::assertSame(TransportEmissionResult::STATUS_CALCULATED, $bev->status);
        self::assertSame('0', $bev->factorValue);
        self::assertSame('0', $bev->generatedKgCo2e);

        self::assertSame(
            TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE,
            $this->calculate('car', 'distance', 'FR', '10', 'km', vehicleType: 'lpg', carSize: 'small')->status,
        );
    }

    public function testTemporalResolutionFallsBackButNeverUsesTheFuture(): void
    {
        $fallback = $this->calculate('metro', 'route', 'ES', '10', 'km', passengers: '1', date: '2026-01-01');
        self::assertSame(2025, $fallback->factorYear);
        self::assertTrue($fallback->isFallback);
        self::assertSame('exact_year_missing', $fallback->fallbackReason);

        $futureOnly = $this->calculate('metro', 'route', 'ES', '10', 'km', passengers: '1', date: '2021-01-01');
        self::assertSame(TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE, $futureOnly->status);
        self::assertNull($futureOnly->factorYear);
    }

    public function testCrossYearRangeUsesStartDateActivityYear(): void
    {
        $result = $this->calculate(
            'taxi',
            'route',
            'FR',
            '10',
            'km',
            date: '2026-12-30',
            endDate: '2027-01-02',
        );

        self::assertSame(2026, $result->activityYear);
        self::assertSame(2026, $result->factorYear);
        self::assertFalse($result->isFallback);
    }

    public function testUiContractSeparatesUnsupportedFromUnavailableAndExternalPaths(): void
    {
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('plane', 'route', 'ES', '10', 'km', category: 'local')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'route_stops', 'ES', '10', 'km')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('passenger_van', 'route', 'ES', '10', 'km')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'operator', 'ES', '10', 'kg_co2e')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('metro', 'electricity', 'ES', '10', 'kWh')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'distance_consumption', 'ES', '10', 'km', vehicleType: 'petrol')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'fuel_and_electricity', 'ES', '10', 'l', vehicleType: 'petrol')->status);
        self::assertSame(TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE, $this->calculate('minibus', 'distance', 'ES', '10', 'km')->status);
        self::assertSame(TransportEmissionResult::STATUS_EXTERNAL_FACTOR_REQUIRED, $this->calculate('minibus', 'electricity', 'ES', '10', 'kWh')->status);
    }

    public function testPlaneRouteRequiresClassificationButAggregatedPassengerDistanceDoesNot(): void
    {
        self::assertSame(
            TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE,
            $this->calculate('plane', 'route', 'ES', '100', 'km', passengers: '1')->status,
        );

        $aggregated = $this->calculate('plane', 'passenger_distance', 'ES', '100', 'passenger-km');
        self::assertSame(TransportEmissionResult::STATUS_CALCULATED, $aggregated->status);
        self::assertSame('Vuelo (Nacional pasajero promedio)', $aggregated->criteria['activity']);

        $domestic = $this->calculate('plane', 'route', 'ES', '100', 'km', passengers: '1', routeClassification: 'domestic');
        self::assertSame(TransportEmissionResult::STATUS_CALCULATED, $domestic->status);
    }

    public function testMotorcycleFuelUsesOnlyAnUnambiguousCatalogMapping(): void
    {
        $outside = $this->calculate('motorcycle', 'fuel', 'FR', '2', 'l', fuel: 'petrol');
        self::assertSame(TransportEmissionResult::STATUS_CALCULATED, $outside->status);
        self::assertSame('Moto promedio', $outside->criteria['activity']);

        self::assertSame(
            TransportEmissionResult::STATUS_FACTOR_NOT_AVAILABLE,
            $this->calculate('motorcycle', 'fuel', 'ES', '2', 'l', fuel: 'petrol')->status,
        );
    }

    public function testRepetitionsMustBeAPositiveInteger(): void
    {
        foreach (['0', '1.5'] as $repetitions) {
            try {
                $this->calculate('car', 'distance', 'ES', '10', 'km', repetitions: $repetitions, vehicleType: 'petrol');
                self::fail('Invalid repetitions must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('repetitions must be a positive integer.', $exception->getMessage());
            }
        }
    }

    public function testPassengersMustBeAPositiveInteger(): void
    {
        foreach (['0', '2.5'] as $passengers) {
            try {
                $this->calculate('metro', 'route', 'ES', '10', 'km', passengers: $passengers);
                self::fail('Invalid passengers must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('passengers must be a positive integer.', $exception->getMessage());
            }
        }
    }

    public function testCarMethodsRespectTheV20MotorizationMatrix(): void
    {
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'electricity', 'ES', '10', 'kWh', vehicleType: 'petrol')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'fuel', 'ES', '10', 'l', vehicleType: 'bev', fuel: 'petrol')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'fuel', 'ES', '10', 'l', vehicleType: 'unknown', fuel: 'petrol')->status);
        self::assertSame(TransportEmissionResult::STATUS_UNSUPPORTED, $this->calculate('car', 'fuel', 'ES', '10', 'l', fuel: 'petrol')->status);
        self::assertSame(TransportEmissionResult::STATUS_EXTERNAL_FACTOR_REQUIRED, $this->calculate('car', 'fuel_and_electricity', 'ES', '10', 'l', vehicleType: 'phev')->status);
        self::assertSame(TransportEmissionResult::STATUS_EXTERNAL_FACTOR_REQUIRED, $this->calculate('car', 'electricity', 'ES', '10', 'kWh', vehicleType: 'bev')->status);
    }

    public function testDistanceUnitsAreRestrictedByMethod(): void
    {
        $invalidInputs = [
            ['car', 'distance', 'passenger-km', ['vehicleType' => 'petrol']],
            ['metro', 'route', 'passenger-km', ['passengers' => '1']],
            ['metro', 'passenger_distance', 'km', []],
            ['freight_van', 'weight_distance', 'passenger-mi', ['weightValue' => '1', 'weightUnit' => 't']],
            ['walk', 'distance', 'passenger-km', []],
        ];

        foreach ($invalidInputs as [$mode, $method, $unit, $arguments]) {
            try {
                $this->calculate($mode, $method, 'ES', '10', $unit, ...$arguments);
                self::fail(sprintf('%s/%s must reject %s.', $mode, $method, $unit));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $passengerMiles = $this->calculate('metro', 'passenger_distance', 'ES', '10', 'passenger-mi');
        self::assertSame('16.09344', $passengerMiles->normalizedActivityValue);
    }

    private function calculate(
        string $mode,
        string $method,
        string $country,
        string $value,
        string $unit,
        string $repetitions = '1',
        ?string $passengers = null,
        ?string $weightValue = null,
        ?string $weightUnit = null,
        ?string $vehicleType = null,
        ?string $carSize = null,
        ?string $fuel = null,
        string $date = '2025-06-01',
        ?string $category = null,
        ?string $routeClassification = null,
        ?string $endDate = null,
    ): TransportEmissionResult {
        return $this->calculator->calculate(new TransportEmissionInput(
            $category ?? $this->categoryForMode($mode), $mode, $method, $country,
            new \DateTimeImmutable($date), new \DateTimeImmutable($endDate ?? $date), $value, $unit,
            $repetitions, $passengers, $weightValue, $weightUnit, $vehicleType, $carSize, $fuel,
            routeClassification: $routeClassification,
        ));
    }

    private function categoryForMode(string $mode): string
    {
        if (in_array($mode, ['plane', 'long_distance_train', 'coach', 'passenger_ferry'], true)) {
            return 'travel';
        }

        if (in_array($mode, ['freight_van', 'rigid_truck', 'articulated_truck', 'freight_train', 'air_freight', 'freight_ship', 'courier', 'cargo_bike'], true)) {
            return 'freight';
        }

        return 'local';
    }

    private function factor(string $category, string $key, int $activityYear, EmissionFactorKeyGenerator $keyGenerator): ?EmissionFactor
    {
        self::assertSame('transport', $category);
        $file = new \SplFileObject(__DIR__.'/../../../../src/DataFixtures/data/emission/transport_factors_v20.csv', 'rb');
        $file->setCsvControl(',', '"', '');
        $headers = $file->fgetcsv();
        $selected = null;
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            $row = array_combine($headers, $values);
            $criteria = array_intersect_key($row, array_flip(['area', 'subcategory', 'activity', 'fuel', 'unit', 'method']));
            $year = (int) $row['factor_year'];
            if ($year <= $activityYear && $keyGenerator->generate($criteria) === $key && (null === $selected || $year > $selected->getYear())) {
                $selected = (new EmissionFactor())
                    ->setCategoryKey('transport')->setFunctionalKey($key)->setCriteria($criteria)->setYear($year)
                    ->setValue('' === $row['factor_value'] ? null : $row['factor_value'])
                    ->setUnit($row['unit'])->setSource($row['source'])->setSourceDetail($row['source_detail'] ?: null);
            }
        }

        return $selected;
    }
}
