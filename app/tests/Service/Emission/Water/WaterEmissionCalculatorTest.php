<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Water;

use App\DataFixtures\WaterEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Water\WaterEmissionCalculator;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterFactorResolution;
use App\Service\Emission\Water\WaterFactorResolver;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class WaterEmissionCalculatorTest extends TestCase
{
    private WaterEmissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = $this->createCalculator();
    }

    public function testLitresAreNormalizedToCubicMetres(): void
    {
        $result = $this->calculator->calculate($this->input(volume: '1000', unit: 'L'));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('1', $result->normalizedAmount);
        self::assertSame('m3', $result->normalizedUnit);
        self::assertSame('0.517', $result->emissionKgCo2e);
    }

    public function testCubicMetresRemainUnchangedAndUkIrrigationUsesSupplyOnly(): void
    {
        $result = $this->calculator->calculate($this->input(
            year: 2025,
            country: 'GB',
            volume: '2',
            unit: 'm3',
            destination: WaterEmissionInput::DESTINATION_IRRIGATION,
        ));

        self::assertSame('2', $result->normalizedAmount);
        self::assertSame('0.3826', $result->emissionKgCo2e);
        self::assertCount(1, $result->factorTraces);
        self::assertSame('water_supply', $result->factorTraces[0]->factorType);
    }

    public function testUkSewerAddsSupplyAndTreatmentWithTwoIndependentTraces(): void
    {
        $result = $this->calculator->calculate($this->input(year: 2024, country: 'GB'));

        self::assertSame('0.33885', $result->emissionKgCo2e);
        self::assertCount(2, $result->factorTraces);
        self::assertSame(['0.15311', '0.18574'], array_column($result->factorTraces, 'componentEmissionKgCo2e'));
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_ANNUAL, $result->temporalType);
    }

    public function testSpainUsesUkProxyBefore2024AndOcccFrom2024(): void
    {
        $ukProxy = $this->calculator->calculate($this->input(year: 2023));
        self::assertSame('0.378', $ukProxy->emissionKgCo2e);
        self::assertCount(2, $ukProxy->factorTraces);
        self::assertTrue($ukProxy->factorTraces[0]->isGeographicProxy);
        self::assertSame(WaterFactorResolution::QUALITY_LOW, $ukProxy->factorTraces[0]->dataQuality);

        $occc = $this->calculator->calculate($this->input(year: 2024));
        self::assertSame('0.517', $occc->emissionKgCo2e);
        self::assertCount(1, $occc->factorTraces);
        self::assertSame('urban_water_cycle', $occc->factorTraces[0]->factorType);
        self::assertSame('Cataluña', $occc->factorTraces[0]->sourceGeography);
    }

    public function testSpain2026UsesOccc2025TemporalFallback(): void
    {
        $result = $this->calculator->calculate($this->input(year: 2026));

        self::assertSame('0.517', $result->emissionKgCo2e);
        self::assertSame(2025, $result->factorTraces[0]->factorYear);
        self::assertTrue($result->factorTraces[0]->isFallback);
        self::assertSame('exact_year_missing', $result->factorTraces[0]->fallbackReason);
        self::assertSame(WaterFactorResolution::QUALITY_LOW, $result->factorTraces[0]->dataQuality);
    }

    public function testAnotherCountryUsesUkAsGeographicProxy(): void
    {
        $result = $this->calculator->calculate($this->input(year: 2024, country: 'FR'));

        self::assertSame('0.33885', $result->emissionKgCo2e);
        self::assertCount(2, $result->factorTraces);
        foreach ($result->factorTraces as $trace) {
            self::assertTrue($trace->isGeographicProxy);
            self::assertSame('FR', $trace->targetGeography);
            self::assertSame(WaterFactorResolution::QUALITY_LOW, $trace->dataQuality);
        }
    }

    public function testUnknownDestinationUsesConservativeSupplyAndTreatmentBoundary(): void
    {
        $result = $this->calculator->calculate($this->input(
            year: 2024,
            country: 'GB',
            destination: WaterEmissionInput::DESTINATION_UNKNOWN,
        ));

        self::assertSame('0.33885', $result->emissionKgCo2e);
        self::assertSame(['water_supply', 'water_treatment'], array_column($result->factorTraces, 'factorType'));
    }

    public function testRequiredClassificationAndCountryInputsBecomePending(): void
    {
        foreach ([
            [$this->input(country: null), 'country_required'],
            [$this->input(country: 'Spain'), 'country_invalid'],
            [$this->input(waterUseType: null), 'water_use_type_required'],
            [$this->input(waterUseType: 'bebida'), 'water_use_type_unknown'],
            [$this->input(destination: null), 'destination_required'],
            [$this->input(destination: 'river'), 'destination_unknown'],
        ] as [$input, $message]) {
            $result = $this->calculator->calculate($input);
            self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
            self::assertContains($message, $result->messages);
        }
    }

    public function testInvalidVolumeOrUnitBecomesPendingAndNegativeValuesAreRejected(): void
    {
        foreach ([
            [$this->input(volume: null), 'volume_invalid'],
            [$this->input(volume: '-1'), 'volume_invalid'],
            [$this->input(volume: 'abc'), 'volume_invalid'],
            [$this->input(unit: 'litres'), 'volume_unit_unknown'],
        ] as [$input, $message]) {
            $result = $this->calculator->calculate($input);
            self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
            self::assertContains($message, $result->messages);
        }
    }

    public function testDateValidationMatchesTheModernContract(): void
    {
        $missing = $this->calculator->calculate(new WaterEmissionInput(
            null,
            new \DateTimeImmutable('2024-12-31'),
            'ES',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            WaterEmissionInput::DESTINATION_SEWER,
        ));
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $missing->status);
        self::assertContains('dates_required', $missing->messages);

        $inverted = $this->calculator->calculate($this->input(
            startDate: new \DateTimeImmutable('2024-02-01'),
            endDate: new \DateTimeImmutable('2024-01-01'),
        ));
        self::assertContains('invalid_date_range', $inverted->messages);

        $crossYear = $this->calculator->calculate($this->input(
            startDate: new \DateTimeImmutable('2024-12-31'),
            endDate: new \DateTimeImmutable('2025-01-01'),
        ));
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $crossYear->status);
        self::assertContains('split_by_year', $crossYear->messages);
    }

    public function testZeroVolumeIsAValidCalculatedValue(): void
    {
        $result = $this->calculator->calculate($this->input(volume: '0'));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('0', $result->normalizedAmount);
        self::assertSame('0', $result->emissionKgCo2e);
    }

    public function testValidInputWithoutPriorCompatibleFactorIsNotAutomaticallyCalculable(): void
    {
        $result = $this->calculator->calculate($this->input(year: 2021, country: 'FR'));

        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $result->status);
        self::assertNull($result->emissionKgCo2e);
        self::assertSame('1', $result->normalizedAmount);
        self::assertContains('emission_factor_unavailable', $result->messages);
        self::assertCount(2, $result->factorTraces);
    }

    private function input(
        int $year = 2024,
        ?string $country = 'ES',
        ?string $waterUseType = WaterEmissionInput::USE_CLEANING,
        ?string $volume = '1',
        ?string $unit = 'm3',
        ?string $destination = WaterEmissionInput::DESTINATION_SEWER,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null,
    ): WaterEmissionInput {
        return new WaterEmissionInput(
            startDate: $startDate ?? new \DateTimeImmutable($year.'-01-01'),
            endDate: $endDate ?? new \DateTimeImmutable($year.'-12-31'),
            country: $country,
            waterUseType: $waterUseType,
            volumeInput: $volume,
            volumeInputUnit: $unit,
            destination: $destination,
        );
    }

    private function createCalculator(): WaterEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $factors[] = $factor;
        });
        (new WaterEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('water', $categoryKey);
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool => $factor->getFunctionalKey() === $functionalKey
                        && $factor->getYear() <= $activityYear,
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );

        return new WaterEmissionCalculator(
            new WaterFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)),
        );
    }
}
