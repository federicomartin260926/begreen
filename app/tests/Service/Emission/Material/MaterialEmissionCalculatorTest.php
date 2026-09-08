<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Material;

use App\DataFixtures\MaterialEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Material\MaterialAmountNormalizer;
use App\Service\Emission\Material\MaterialEmissionCalculator;
use App\Service\Emission\Material\MaterialEmissionInput;
use App\Service\Emission\Material\MaterialFactorResolver;
use App\Service\Emission\Material\MaterialUiCatalog;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class MaterialEmissionCalculatorTest extends TestCase
{
    private MaterialEmissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = $this->calculator();
    }

    public function testDirectWeightAndCorrectedExact2026BoardFactor(): void
    {
        $direct = $this->calculate(
            year: 2022,
            activity: MaterialUiCatalog::ACTIVITY_WOOD,
            origin: 'Producción de materia prima',
            method: MaterialEmissionInput::METHOD_WEIGHT,
            quantity: '100',
            unit: 'kg',
        );
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $direct->status);
        self::assertSame('100', $direct->normalizedAmount);
        self::assertSame('31.261', $direct->emissionKgCo2e);

        $board = $this->calculator->calculate(new MaterialEmissionInput(
            startDate: new \DateTimeImmutable('2026-01-01'),
            endDate: new \DateTimeImmutable('2026-12-31'),
            country: 'ESP',
            activity: MaterialUiCatalog::ACTIVITY_WOOD,
            origin: 'Producción de materia prima',
            measurementMethod: MaterialEmissionInput::METHOD_DIMENSIONS,
            boardFamily: 'DM o MDF',
            boardThickness: '12 mm',
            unitCount: '10',
        ));
        self::assertSame('271.48416', $board->normalizedAmount);
        self::assertSame('73.1661104941056', $board->emissionKgCo2e);
        self::assertSame(2026, $board->factorTraces[0]->resolution->factorYear);
        self::assertFalse($board->factorTraces[0]->resolution->isFallback);
    }

    public function testPaperPackageAndPieceConversions(): void
    {
        $paper = $this->calculator->calculate(new MaterialEmissionInput(
            startDate: new \DateTimeImmutable('2026-01-01'),
            endDate: new \DateTimeImmutable('2026-01-02'),
            country: 'ESP',
            activity: MaterialUiCatalog::ACTIVITY_PAPER,
            origin: 'Producción de materia prima',
            measurementMethod: MaterialEmissionInput::METHOD_PACKAGES,
            inputQuantity: '3',
            paperFormat: 'A4 (210 x 297)',
        ));
        self::assertSame('8.887725', $paper->normalizedAmount);
        self::assertSame('11.9414685647565', $paper->emissionKgCo2e);
        self::assertSame(2026, $paper->factorTraces[0]->resolution->factorYear);

        $metal = $this->calculator->calculate(new MaterialEmissionInput(
            startDate: new \DateTimeImmutable('2026-01-01'),
            endDate: new \DateTimeImmutable('2026-01-02'),
            country: 'ESP',
            activity: MaterialUiCatalog::ACTIVITY_METAL,
            origin: 'Reutilizado',
            measurementMethod: MaterialEmissionInput::METHOD_UNITS,
            unitCount: '8',
            pieceWeightKg: '4.5',
        ));
        self::assertSame('36', $metal->normalizedAmount);
        self::assertSame('0', $metal->emissionKgCo2e);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $metal->factorTraces[0]->resolution->temporalType);
        self::assertNull($metal->factorTraces[0]->resolution->factorYear);
    }

    public function testValidatedBatteryUnitsAndAmbiguousBatteryWeight(): void
    {
        $battery = $this->calculator->calculate(new MaterialEmissionInput(
            startDate: new \DateTimeImmutable('2024-01-01'),
            endDate: new \DateTimeImmutable('2024-01-02'),
            country: 'ESP',
            activity: MaterialUiCatalog::ACTIVITY_BATTERIES,
            subproduct: 'Pila/batería de Ion de litio',
            origin: 'Producción de materia prima',
            measurementMethod: MaterialEmissionInput::METHOD_UNITS,
            unitCount: '10',
            batteryChemistry: 'Litio-Ion',
            batterySize: 'AA',
        ));
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $battery->status);
        self::assertSame('0.145', $battery->normalizedAmount);
        self::assertSame('0.91466', $battery->emissionKgCo2e);
        self::assertNull($battery->factorTraces[0]->resolution->factorYear);

        $ambiguous = $this->calculator->calculate(new MaterialEmissionInput(
            startDate: new \DateTimeImmutable('2024-01-01'),
            endDate: new \DateTimeImmutable('2024-01-02'),
            country: 'ESP',
            activity: MaterialUiCatalog::ACTIVITY_BATTERIES,
            subproduct: 'Pila/batería de Ion de litio',
            origin: 'Producción de materia prima',
            measurementMethod: MaterialEmissionInput::METHOD_UNITS,
            unitCount: '10',
            batteryChemistry: 'Litio-Ion',
            batterySize: '9V',
        ));
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $ambiguous->status);
        self::assertSame(['battery_unique_weight_unavailable'], $ambiguous->messages);
    }

    public function testDocumentedLiquidConversions(): void
    {
        $paint = $this->calculate(
            year: 2026,
            activity: MaterialUiCatalog::ACTIVITY_PAINT,
            subproduct: 'Pintura con base al agua',
            origin: '',
            method: MaterialEmissionInput::METHOD_VOLUME,
            quantity: '10',
            unit: 'l',
        );
        self::assertSame('16', $paint->normalizedAmount);
        self::assertSame('34.45879792', $paint->emissionKgCo2e);

        $varnish = $this->calculate(
            year: 2026,
            activity: MaterialUiCatalog::ACTIVITY_VARNISH,
            subproduct: 'Barniz con base al agua',
            origin: '',
            method: MaterialEmissionInput::METHOD_VOLUME,
            quantity: '8',
            unit: 'l',
        );
        self::assertSame('8.16', $varnish->normalizedAmount);
        self::assertSame('12.32900873328', $varnish->emissionKgCo2e);

        $solvent = $this->calculate(
            year: 2026,
            activity: MaterialUiCatalog::ACTIVITY_SOLVENT,
            subproduct: 'Aguarrás mineral',
            origin: '',
            method: MaterialEmissionInput::METHOD_VOLUME,
            quantity: '2500',
            unit: 'ml',
        );
        self::assertSame('2.5', $solvent->normalizedAmount);
        self::assertSame('1.5475', $solvent->emissionKgCo2e);
    }

    public function testExplicitZeroRuleAndUnsupportedConversionsRemainDistinctFromMissingData(): void
    {
        $rule = $this->calculate(
            year: 2026,
            activity: MaterialUiCatalog::ACTIVITY_PAPER,
            origin: 'Reutilizado',
            method: MaterialEmissionInput::METHOD_WEIGHT,
            quantity: '12',
            unit: 'kg',
        );
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $rule->status);
        self::assertSame('0', $rule->emissionKgCo2e);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $rule->factorTraces[0]->resolution->temporalType);

        $plastic = $this->calculate(
            year: 2026,
            activity: 'Plástico promedio',
            origin: 'Reutilizado',
            method: MaterialEmissionInput::METHOD_WEIGHT,
            quantity: '10',
            unit: 'kg',
        );
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $plastic->status);
        self::assertNull($plastic->emissionKgCo2e);

        $carpet = $this->calculate(
            year: 2026,
            activity: MaterialUiCatalog::ACTIVITY_CARPET,
            subproduct: 'Estándar',
            origin: 'Materia prima virgen',
            method: MaterialEmissionInput::METHOD_WEIGHT,
            quantity: '40',
            unit: 'kg',
        );
        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $carpet->status);
        self::assertNull($carpet->emissionKgCo2e);
        self::assertSame(['carpet_weight_conversion_unavailable'], $carpet->messages);
    }

    private function calculate(
        int $year,
        string $activity,
        string $origin,
        string $method,
        ?string $quantity = null,
        ?string $unit = null,
        ?string $subproduct = null,
    ): \App\Service\Emission\Material\MaterialEmissionResult {
        return $this->calculator->calculate(new MaterialEmissionInput(
            startDate: new \DateTimeImmutable($year.'-01-01'),
            endDate: new \DateTimeImmutable($year.'-12-31'),
            country: 'ESP',
            activity: $activity,
            subproduct: $subproduct,
            origin: $origin,
            measurementMethod: $method,
            inputQuantity: $quantity,
            inputUnit: $unit,
        ));
    }

    private function calculator(): MaterialEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $factors[] = $factor;
        });
        (new MaterialEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    'material' === $categoryKey
                    && EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                    && $factor->getFunctionalKey() === $functionalKey
                    && (int) $factor->getYear() <= $activityYear
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => (int) $right->getYear() <=> (int) $left->getYear());

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('material' === $categoryKey && $temporalType === $factor->getTemporalType() && $functionalKey === $factor->getFunctionalKey()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        $catalog = new MaterialUiCatalog();
        $resolver = new MaterialFactorResolver(new EmissionFactorResolver($repository, $keyGenerator), $catalog);

        return new MaterialEmissionCalculator($catalog, new MaterialAmountNormalizer($catalog), $resolver);
    }
}
