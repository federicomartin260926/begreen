<?php

namespace App\Tests\Service\Emission\Energy;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Energy\ElectricityFactorResolver;
use App\Service\Emission\Energy\EnergyEmissionCalculator;
use App\Service\Emission\Energy\EnergyEmissionInput;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Energy\StationaryCombustionFactorResolver;
use PHPUnit\Framework\TestCase;

final class EnergyEmissionCalculatorTest extends TestCase
{
    public function testSameYearMeterReadingsAreCalculated(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            origin: EnergyEmissionInput::ORIGIN_GRID,
            unit: 'kWh',
            initialReading: '100',
            finalReading: '110',
        ));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('10', $result->normalizedAmount);
    }

    public function testCrossYearRangeRequiresSeparateRecords(): void
    {
        $result = $this->calculator()->calculate(new EnergyEmissionInput(
            family: EnergyEmissionInput::FAMILY_ELECTRICITY,
            startDate: new \DateTimeImmutable('2025-12-31'),
            endDate: new \DateTimeImmutable('2026-01-01'),
            country: 'ES',
        ));

        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
        self::assertNull($result->emissionKgCo2e);
        self::assertContains('split_by_year', $result->messages);
    }

    public function testSpainGridElectricity2025(): void
    {
        $result = $this->calculator()->calculate($this->electricity('10'));

        self::assertSame('2.58', $result->emissionKgCo2e);
        self::assertSame(2025, $result->factorYear);
        self::assertFalse($result->isFallback);
    }

    public function testSpainGridElectricity2026FallsBackTo2025(): void
    {
        $result = $this->calculator()->calculate($this->electricity(
            '10',
            start: '2026-01-01',
            end: '2026-12-31',
        ));

        self::assertSame('2.58', $result->emissionKgCo2e);
        self::assertSame(2025, $result->factorYear);
        self::assertTrue($result->isFallback);
        self::assertSame('exact_year_missing', $result->fallbackReason);
    }

    public function testRenewableGuaranteeZeroIsCalculated(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            origin: EnergyEmissionInput::ORIGIN_GRID,
            amount: '10',
            unit: 'kWh',
            labeling: 'GDO RENOVABLE',
        ));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('0', $result->emissionKgCo2e);
        self::assertSame('0', $result->factorValue);
    }

    public function testMixedElectricityIsCompositeAndSnapshotKeepsBothTraces(): void
    {
        $input = $this->input(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            origin: EnergyEmissionInput::ORIGIN_MIXED,
            amount: '10',
            unit: 'kWh',
            gridKwh: '6',
            solarKwh: '4',
        );
        $result = $this->calculator()->calculate($input);

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_COMPOSITE, $result->temporalType);
        self::assertSame('1.548', $result->emissionKgCo2e);
        self::assertCount(2, $result->factorTraces);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $result->factorTraces[1]->temporalType);
        self::assertStringContainsString('lifecycle emissions are not zero', $result->factorTraces[1]->metadata['boundary']);

        $snapshot = json_decode((new EnergyEmissionSnapshot())->encode($input, $result, ['label' => 'Mixed']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(EnergyEmissionSnapshot::VERSION, $snapshot['version']);
        self::assertSame('Mixed', $snapshot['presentation']['label']);
        self::assertCount(2, $snapshot['calculation']['factorTraces']);
    }

    public function testInconsistentMixedElectricityIsPending(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            origin: EnergyEmissionInput::ORIGIN_MIXED,
            amount: '10',
            unit: 'kWh',
            gridKwh: '8',
            solarKwh: '3',
        ));

        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
        self::assertNull($result->emissionKgCo2e);
    }

    public function testMwhIsNormalizedToKwh(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            origin: EnergyEmissionInput::ORIGIN_GRID,
            amount: '1.5',
            unit: 'MWh',
        ));

        self::assertSame('1500', $result->normalizedAmount);
        self::assertSame('387', $result->emissionKgCo2e);
    }

    public function testSpainDieselEquipment(): void
    {
        $result = $this->calculator()->calculate($this->equipment('Diésel', '10', 'litros'));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('25.17', $result->emissionKgCo2e);
    }

    public function testNaturalGasWithValidUnit(): void
    {
        $result = $this->calculator()->calculate($this->equipment('Gas natural', '10', 'm3'));

        self::assertSame('20.6672', $result->emissionKgCo2e);
        self::assertSame('m3', $result->normalizedUnit);
    }

    public function testPropaneLitresArePendingInsteadOfInventingConversion(): void
    {
        $result = $this->calculator()->calculate($this->equipment('Gas propano', '10', 'litros'));

        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
        self::assertNull($result->emissionKgCo2e);
        self::assertContains('incompatible_fuel_unit', $result->messages);
    }

    public function testEquipmentOutsideSpainUsesUkProxy(): void
    {
        $result = $this->calculator()->calculate($this->equipment('Diésel', '10', 'litros', 'FR', 2026));

        self::assertSame('25.8354', $result->emissionKgCo2e);
        self::assertTrue($result->isGeographicProxy);
        self::assertSame('Reino Unido', $result->proxyGeography);
    }

    public function testEquipmentInUnitedKingdomIsNotProxy(): void
    {
        $result = $this->calculator()->calculate($this->equipment('Diésel', '10', 'litros', 'GB', 2026));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertFalse($result->isGeographicProxy);
        self::assertNull($result->proxyGeography);
    }

    public function testButaneCylindersAreNormalizedToKg(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_EQUIPMENT,
            equipmentType: 'cooking',
            fuel: 'Gas butano',
            mode: EnergyEmissionInput::EQUIPMENT_MODE_CYLINDERS,
            bottleSizeKg: '12.5',
            bottleCount: '2',
        ));

        self::assertSame('25', $result->normalizedAmount);
        self::assertSame('kg', $result->normalizedUnit);
        self::assertSame('74.9', $result->emissionKgCo2e);
    }

    public function testBatteryChargedFromGrid(): void
    {
        $result = $this->calculator()->calculate($this->battery(EnergyEmissionInput::ORIGIN_GRID));

        self::assertSame('2.58', $result->emissionKgCo2e);
        self::assertSame('battery_charge', $result->factorTraces[0]->component);
    }

    public function testBatteryChargedFromSolarIsOperationalZero(): void
    {
        $result = $this->calculator()->calculate($this->battery(EnergyEmissionInput::ORIGIN_SOLAR));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('0', $result->emissionKgCo2e);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $result->temporalType);
    }

    public function testBatteryMixedChargeIsComposite(): void
    {
        $result = $this->calculator()->calculate($this->battery(
            EnergyEmissionInput::ORIGIN_MIXED,
            gridKwh: '7',
            solarKwh: '3',
        ));

        self::assertSame('1.806', $result->emissionKgCo2e);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_COMPOSITE, $result->temporalType);
        self::assertCount(2, $result->factorTraces);
    }

    public function testBatteryWithUnknownChargeSourceIsPending(): void
    {
        $result = $this->calculator()->calculate($this->battery(EnergyEmissionInput::ORIGIN_UNKNOWN));

        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
        self::assertNull($result->emissionKgCo2e);
    }

    public function testDigitalKnownKwhUsesElectricityAndSnapshotKeepsContext(): void
    {
        $input = $this->input(
            EnergyEmissionInput::FAMILY_DIGITAL,
            digitalType: 'ai',
            digitalLocation: 'Madrid',
            knownKwh: '10',
            hours: '3',
            gpu: 'A100',
            service: 'render',
            model: 'model-x',
            provider: 'provider-x',
            ownership: 'external_cloud',
        );
        $result = $this->calculator()->calculate($input);

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('2.58', $result->emissionKgCo2e);
        $snapshot = json_decode((new EnergyEmissionSnapshot())->encode($input, $result), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('provider-x', $snapshot['input']['provider']);
        self::assertSame('external_cloud', $snapshot['input']['ownership']);
    }

    public function testDigitalSignalsWithoutKnownKwhAreNotAutomaticallyCalculable(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_DIGITAL,
            digitalType: 'ai',
            hours: '3',
            gpu: 'A100',
        ));

        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $result->status);
        self::assertNull($result->normalizedAmount);
        self::assertNull($result->emissionKgCo2e);
    }

    public function testDigitalKnownKwhUsesExplicitElectricityCountryBeforeRecordCountry(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_DIGITAL,
            country: 'ES',
            digitalType: 'cloud',
            digitalLocation: 'London datacenter',
            digitalCountry: 'GB',
            knownKwh: '10',
        ));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('1.77', $result->emissionKgCo2e);
        self::assertFalse($result->isGeographicProxy);
        self::assertSame('GB', $result->factorTraces[0]->metadata['country']);
    }

    public function testUnmappedDigitalRegionDoesNotInventFactorGeography(): void
    {
        $result = $this->calculator()->calculate($this->input(
            EnergyEmissionInput::FAMILY_DIGITAL,
            country: 'ES',
            digitalType: 'cloud',
            digitalLocation: 'eu-west-1',
            knownKwh: '10',
        ));

        self::assertSame('2.58', $result->emissionKgCo2e);
        self::assertSame('ES', $result->factorTraces[0]->metadata['country']);
        self::assertFalse($result->isGeographicProxy);
    }

    private function calculator(): EnergyEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $register = function (array $criteria, int $year, string $value, string $unit, string $source) use (&$factors, $keyGenerator): void {
            $factor = (new EmissionFactor())
                ->setCategoryKey('energy')
                ->setFunctionalKey($keyGenerator->generate($criteria))
                ->setCriteria($criteria)
                ->setYear($year)
                ->setValue($value)
                ->setUnit($unit)
                ->setSource($source)
                ->setSourceDetail($source.' test factor')
                ->setMetadata(['factorVersion' => 'test-v1']);
            $factors[$factor->getFunctionalKey()][] = $factor;
        };

        $register($this->electricityCriteria('ESPAÑA', 'SIN GDO'), 2025, '0.258', 'kgCO2e/kWh', 'MITECO');
        $register($this->electricityCriteria('ESPAÑA', 'GDO RENOVABLE'), 2025, '0', 'kgCO2e/kWh', 'MITECO');
        $register($this->electricityCriteria('FUERA DE ESPAÑA', ''), 2026, '0.13096', 'kgCO2e/kWh', 'DEFRA');
        $register($this->electricityCriteria('FUERA DE ESPAÑA', ''), 2025, '0.177', 'kgCO2e/kWh', 'DEFRA');
        $register($this->combustionCriteria('ESPAÑA', 'Diésel', 'litros'), 2025, '2.517', 'kgCO2e/litros', 'MITECO');
        $register($this->combustionCriteria('ESPAÑA', 'Gas natural', 'm3'), 2025, '2.06672', 'kgCO2e/m3', 'DEFRA');
        $register($this->combustionCriteria('ESPAÑA', 'Gas butano', 'kg'), 2025, '2.996', 'kgCO2e/kg', 'MITECO');
        $register($this->combustionCriteria('FUERA DE ESPAÑA', 'Diésel', 'litros'), 2026, '2.58354', 'kgCO2e/litros', 'DEFRA');

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')
            ->willReturnCallback(static function (string $category, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('energy', $category);
                $candidates = array_filter($factors[$functionalKey] ?? [], static fn (EmissionFactor $factor): bool => $factor->getYear() <= $activityYear);
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            });
        $common = new EmissionFactorResolver($repository, $keyGenerator);

        return new EnergyEmissionCalculator(
            new ElectricityFactorResolver($common),
            new StationaryCombustionFactorResolver($common),
        );
    }

    private function electricity(string $amount, string $start = '2025-01-01', string $end = '2025-12-31'): EnergyEmissionInput
    {
        return $this->input(
            EnergyEmissionInput::FAMILY_ELECTRICITY,
            start: $start,
            end: $end,
            origin: EnergyEmissionInput::ORIGIN_GRID,
            amount: $amount,
            unit: 'kWh',
        );
    }

    private function equipment(string $fuel, string $amount, string $unit, string $country = 'ES', int $year = 2025): EnergyEmissionInput
    {
        return $this->input(
            EnergyEmissionInput::FAMILY_EQUIPMENT,
            start: $year.'-01-01',
            end: $year.'-12-31',
            country: $country,
            equipmentType: 'generator',
            fuel: $fuel,
            amount: $amount,
            unit: $unit,
        );
    }

    private function battery(string $chargeSource, ?string $gridKwh = null, ?string $solarKwh = null): EnergyEmissionInput
    {
        return $this->input(
            EnergyEmissionInput::FAMILY_BATTERY,
            batteryType: 'portable_station',
            chargeSource: $chargeSource,
            chargedKwh: '10',
            gridKwh: $gridKwh,
            solarKwh: $solarKwh,
        );
    }

    private function input(
        string $family,
        string $start = '2025-01-01',
        string $end = '2025-12-31',
        string $country = 'ES',
        ?string $origin = null,
        ?string $amount = null,
        ?string $unit = null,
        ?string $initialReading = null,
        ?string $finalReading = null,
        ?string $gridKwh = null,
        ?string $solarKwh = null,
        ?string $labeling = null,
        ?string $equipmentType = null,
        ?string $fuel = null,
        string $mode = EnergyEmissionInput::EQUIPMENT_MODE_DIRECT,
        ?string $bottleSizeKg = null,
        ?string $bottleCount = null,
        ?string $batteryType = null,
        ?string $chargeSource = null,
        ?string $chargedKwh = null,
        ?string $digitalType = null,
        ?string $digitalLocation = null,
        ?string $digitalCountry = null,
        ?string $knownKwh = null,
        ?string $hours = null,
        ?string $gpu = null,
        ?string $service = null,
        ?string $model = null,
        ?string $provider = null,
        ?string $ownership = null,
    ): EnergyEmissionInput {
        return new EnergyEmissionInput(
            family: $family,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            country: $country,
            origin: $origin,
            amount: $amount,
            unit: $unit,
            initialReading: $initialReading,
            finalReading: $finalReading,
            gridKwh: $gridKwh,
            solarKwh: $solarKwh,
            labeling: $labeling,
            equipmentType: $equipmentType,
            fuel: $fuel,
            mode: $mode,
            bottleSizeKg: $bottleSizeKg,
            bottleCount: $bottleCount,
            batteryType: $batteryType,
            chargeSource: $chargeSource,
            chargedKwh: $chargedKwh,
            digitalType: $digitalType,
            digitalLocation: $digitalLocation,
            digitalCountry: $digitalCountry,
            knownKwh: $knownKwh,
            hours: $hours,
            gpu: $gpu,
            service: $service,
            model: $model,
            provider: $provider,
            ownership: $ownership,
        );
    }

    /** @return array<string, string> */
    private function electricityCriteria(string $geography, string $labeling): array
    {
        return [
            'geography' => $geography,
            'category' => 'ELECTRICIDAD',
            'activity' => 'PROMEDIO NACIONAL',
            'labeling' => $labeling,
            'supplier' => '',
            'unit' => 'kWh',
        ];
    }

    /** @return array<string, string> */
    private function combustionCriteria(string $geography, string $fuel, string $unit): array
    {
        return [
            'geography' => $geography,
            'category' => 'COMBUSTIÓN ESTACIONARIA',
            'activity' => $fuel,
            'labeling' => '',
            'supplier' => '',
            'unit' => $unit,
        ];
    }
}
