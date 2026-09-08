<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Catering;

use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Catering\CateringEmissionCalculator;
use App\Service\Emission\Catering\CateringEmissionInput;
use App\Service\Emission\Catering\CateringFactorResolver;
use App\Service\Emission\Catering\CateringMenuLine;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use PHPUnit\Framework\TestCase;

final class CateringEmissionCalculatorTest extends TestCase
{
    private CateringEmissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = $this->calculator();
    }

    public function testOfficialVeganMenuCasesUsePreparedFoodAndConsumedTableware(): void
    {
        $compostable = $this->calculator->calculate($this->meal('compostable'));
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $compostable->status);
        self::assertSame('62.2355395', $compostable->emissionKgCo2e);
        self::assertSame('51.9728395', $compostable->factorTraces[0]->emissionKgCo2e);
        self::assertSame('100', $compostable->factorTraces[0]->normalizedAmount);
        self::assertSame('10.2627', $compostable->factorTraces[1]->emissionKgCo2e);
        self::assertSame('90', $compostable->factorTraces[1]->normalizedAmount);

        $reusable = $this->calculator->calculate($this->meal('reusable'));
        self::assertSame('53.9348395', $reusable->emissionKgCo2e);
        self::assertSame('1.962', $reusable->factorTraces[1]->emissionKgCo2e);
    }

    public function testMultipleMenuVariantsAreAddedAndUnknownTablewareKeepsFoodCalculation(): void
    {
        $result = $this->calculator->calculate($this->meal('unknown', [
            new CateringMenuLine('vegan', '10', '8'),
            new CateringMenuLine('beef', '2', '2'),
            new CateringMenuLine(null, '0', '0'),
        ]));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame('12', $result->normalizedAmount);
        self::assertSame('14.64506242', $result->emissionKgCo2e);
        self::assertCount(2, $result->factorTraces);
        self::assertContains('tableware_automatic_factor_unavailable', $result->messages);
    }

    public function testInvalidMenuCountsAndInvalidCommonInputStayPendingWithoutEmission(): void
    {
        $invalidCounts = $this->calculator->calculate($this->meal('compostable', [new CateringMenuLine('vegan', '80', '81')]));
        self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $invalidCounts->status);
        self::assertNull($invalidCounts->emissionKgCo2e);
        self::assertContains('consumed_exceeds_prepared', $invalidCounts->messages);

        foreach ([
            new CateringEmissionInput(null, new \DateTimeImmutable('2025-01-02'), 'ESP', CateringEmissionInput::TYPE_MEAL),
            new CateringEmissionInput(new \DateTimeImmutable('2025-01-02'), new \DateTimeImmutable('2025-01-01'), 'ESP', CateringEmissionInput::TYPE_MEAL),
            new CateringEmissionInput(new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-01-02'), null, CateringEmissionInput::TYPE_MEAL),
        ] as $input) {
            $result = $this->calculator->calculate($input);
            self::assertSame(EmissionRecord::STATUS_PENDING_DATA, $result->status);
            self::assertNull($result->emissionKgCo2e);
        }
    }

    public function testMealWithoutAnyResolvableFoodFactorIsNotAutomaticallyCalculable(): void
    {
        $result = $this->calculator->calculate($this->meal('unknown', [new CateringMenuLine('fish', '3', '2')]));

        self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $result->status);
        self::assertNull($result->emissionKgCo2e);
        self::assertSame(['automatic_factor_unavailable'], $result->messages);
        self::assertNull($result->factorTraces[0]->factorValue);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $result->factorTraces[0]->temporalType);
    }

    public function testDateRangeMayCrossYearAndUsesStartYear(): void
    {
        $result = $this->calculator->calculate($this->meal(
            'compostable',
            startDate: new \DateTimeImmutable('2025-12-31'),
            endDate: new \DateTimeImmutable('2026-01-01'),
        ));

        self::assertSame(EmissionRecord::STATUS_CALCULATED, $result->status);
        self::assertSame(2025, $result->activityYear);
        self::assertSame(2025, $result->factorTraces[0]->activityYear);
        self::assertNull($result->factorTraces[0]->factorYear);
    }

    public function testActivitiesWithoutOfficialFactorsAreNormalizedButNotCalculated(): void
    {
        $cases = [
            [new CateringEmissionInput(...$this->common(CateringEmissionInput::TYPE_BREAKFAST), people: '12'), '12', 'people'],
            [new CateringEmissionInput(...$this->common(CateringEmissionInput::TYPE_WATER), containerVolumeLiters: '0.5', containerMaterial: 'glass', containerCount: '6'), '3', 'L'],
            [new CateringEmissionInput(...$this->common(CateringEmissionInput::TYPE_DRINK), description: 'Juice', unitCount: '4', litersPerUnit: '0.75'), '3', 'L'],
            [new CateringEmissionInput(...$this->common(CateringEmissionInput::TYPE_COFFEE), serviceCount: '15'), '15', 'service'],
            [new CateringEmissionInput(...$this->common(CateringEmissionInput::TYPE_GAS), gasType: 'propane', cylinderCount: '2', kgPerCylinder: '11'), '22', 'kg'],
            [new CateringEmissionInput(...$this->common(CateringEmissionInput::TYPE_SANDWICH), preparedCount: '9', consumedCount: '8'), '9', 'prepared sandwich'],
        ];

        foreach ($cases as [$input, $amount, $unit]) {
            $result = $this->calculator->calculate($input);
            self::assertSame(EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE, $result->status);
            self::assertNull($result->emissionKgCo2e);
            self::assertSame($amount, $result->normalizedAmount);
            self::assertSame($unit, $result->normalizedUnit);
            self::assertSame(['automatic_factor_unavailable'], $result->messages);
        }
    }

    /** @param list<CateringMenuLine>|null $lines */
    private function meal(
        string $tablewareType,
        ?array $lines = null,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null,
    ): CateringEmissionInput {
        return new CateringEmissionInput(
            $startDate ?? new \DateTimeImmutable('2025-01-01'),
            $endDate ?? new \DateTimeImmutable('2025-01-02'),
            'ESP',
            CateringEmissionInput::TYPE_MEAL,
            menuLines: $lines ?? [new CateringMenuLine('vegan', '100', '90')],
            tablewareType: $tablewareType,
        );
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable, string, string} */
    private function common(string $activityType): array
    {
        return [new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-01-02'), 'ESP', $activityType];
    }

    private function calculator(): CateringEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [
            $this->factor($keyGenerator, ['component' => 'food', 'menuVariant' => 'vegan', 'unit' => 'prepared_menu'], EmissionFactor::TEMPORAL_TYPE_VERSIONED, '0.519728395'),
            $this->factor($keyGenerator, ['component' => 'food', 'menuVariant' => 'beef', 'unit' => 'prepared_menu'], EmissionFactor::TEMPORAL_TYPE_VERSIONED, '4.723889235'),
            $this->factor($keyGenerator, ['component' => 'tableware', 'tablewareType' => 'compostable', 'unit' => 'consumed_menu'], EmissionFactor::TEMPORAL_TYPE_COMPOSITE, '0.11403'),
            $this->factor($keyGenerator, ['component' => 'tableware', 'tablewareType' => 'reusable', 'unit' => 'consumed_menu'], EmissionFactor::TEMPORAL_TYPE_PROXY_LCA, '0.0218'),
        ];
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use ($factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('catering' === $categoryKey && $functionalKey === $factor->getFunctionalKey() && $temporalType === $factor->getTemporalType()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new CateringEmissionCalculator(new CateringFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)));
    }

    /** @param array<string, string> $criteria */
    private function factor(EmissionFactorKeyGenerator $keyGenerator, array $criteria, string $temporalType, string $value): EmissionFactor
    {
        return (new EmissionFactor())
            ->setCategoryKey('catering')
            ->setFunctionalKey($keyGenerator->generate($criteria))
            ->setCriteria($criteria)
            ->setYear(null)
            ->setTemporalType($temporalType)
            ->setValue($value)
            ->setUnit('kgCO2e/menu')
            ->setSource('Official source')
            ->setMetadata(['factorVersion' => 'v1']);
    }
}
