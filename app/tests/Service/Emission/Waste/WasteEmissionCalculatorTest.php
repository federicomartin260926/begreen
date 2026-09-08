<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Waste;

use App\DataFixtures\WasteEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Waste\WasteEmissionCalculator;
use App\Service\Emission\Waste\WasteEmissionInput;
use App\Service\Emission\Waste\WasteFactorResolver;
use App\Service\Emission\Waste\WasteUiCatalog;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class WasteEmissionCalculatorTest extends TestCase
{
    private WasteEmissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = $this->calculator();
    }

    public function testTonnesAreNormalizedBeforeApplyingFactor(): void
    {
        $result = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2025-05-01'),
            new \DateTimeImmutable('2025-05-02'),
            'ESP',
            'Orgánico (residuos de jardín)',
            null,
            'Compostaje',
            '1.5',
            't',
        ));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('1500', $result->normalizedAmount);
        self::assertSame('kg', $result->normalizedUnit);
        self::assertSame('368.13', $result->emissionKgCo2e);
        self::assertSame('Orgánico (residuos de jardín)', $result->resolvedWasteActivity);
    }

    public function testUnknownAndNonWasteZeroKeepDifferentMethodologicalSemantics(): void
    {
        $unknown = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2025-05-01'),
            new \DateTimeImmutable('2025-05-02'),
            'ESP',
            'Orgánico (residuos de jardín)',
            null,
            'Desconocido',
            '10',
            'kg',
        ));
        self::assertSame('2.4542', $unknown->emissionKgCo2e);
        self::assertSame('Compostaje', $unknown->resolvedTreatment);
        self::assertSame('DERIVED_MAX_VALID_TREATMENTS', $unknown->factorTraces[0]->resolution->ruleType);

        $zero = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2025-05-01'),
            new \DateTimeImmutable('2025-05-02'),
            'ESP',
            'Construcción / Set promedio',
            null,
            'Reutilización / Donación',
            '100',
            'kg',
        ));
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $zero->status);
        self::assertSame('0', $zero->emissionKgCo2e);
        self::assertSame('NON_WASTE_ROUTE_ZERO', $zero->factorTraces[0]->resolution->ruleType);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $zero->factorTraces[0]->resolution->temporalType);
    }

    public function testCrossYearInvalidCombinationAndMissingRequiredSubactivityRemainPending(): void
    {
        $crossYear = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2025-12-31'),
            new \DateTimeImmutable('2026-01-01'),
            'ESP',
            'Orgánico (residuos de jardín)',
            null,
            'Compostaje',
            '10',
            'kg',
        ));
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $crossYear->status);
        self::assertSame(['activity_crosses_year'], $crossYear->messages);

        $wrongBranch = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-01-02'),
            'FRA',
            'Residuos químicos (no peligrosos)',
            'Pintura al agua',
            'Compostaje',
            '10',
            'kg',
        ));
        self::assertSame(['waste_type_invalid'], $wrongBranch->messages);

        $missingActivity = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-01-02'),
            'ESP',
            'Residuos químicos (no peligrosos)',
            null,
            'Compostaje',
            '10',
            'kg',
        ));
        self::assertSame(['waste_activity_required'], $missingActivity->messages);
    }

    public function testSingleValidDestinationCanBeResolvedWithoutBrowserAuthority(): void
    {
        $result = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-01-02'),
            'ESP',
            'Residuo general (no recogida selectiva)',
            null,
            null,
            '2',
            'kg',
        ));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('Gestión municipal (mezcla real)', $result->resolvedTreatment);
        self::assertSame('1.43294', $result->emissionKgCo2e);
    }

    public function testMissingAnnualFactorBeforeFirstSourceYearIsNotAutomaticallyCalculable(): void
    {
        $result = $this->calculator->calculate(new WasteEmissionInput(
            new \DateTimeImmutable('2021-01-01'),
            new \DateTimeImmutable('2021-01-02'),
            'FRA',
            'Residuos domésticos residuales',
            null,
            'Vertedero',
            '100',
            'kg',
        ));

        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $result->status);
        self::assertNull($result->emissionKgCo2e);
        self::assertSame('100', $result->normalizedAmount);
        self::assertNull($result->factorTraces[0]->resolution->factorValue);
    }

    private function calculator(): WasteEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $factors[] = $factor;
        });
        (new WasteEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    'waste' === $categoryKey
                    && EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                    && $factor->getFunctionalKey() === $functionalKey
                    && $factor->getYear() <= $activityYear
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('waste' === $categoryKey && $temporalType === $factor->getTemporalType() && $functionalKey === $factor->getFunctionalKey()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        $catalog = new WasteUiCatalog();
        $factorResolver = new WasteFactorResolver(new EmissionFactorResolver($repository, $keyGenerator), $catalog);

        return new WasteEmissionCalculator($catalog, $factorResolver);
    }
}
