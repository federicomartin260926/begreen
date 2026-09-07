<?php

namespace App\Tests\Service\Emission\Energy;

use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Energy\ElectricityFactorInput;
use App\Service\Emission\Energy\ElectricityFactorResolver;
use PHPUnit\Framework\TestCase;

final class ElectricityFactorResolverTest extends TestCase
{
    public function testRecognizesSpainNameWithUnicodeCaseNormalization(): void
    {
        $result = $this->resolver(
            [$this->factor(2025, '0.258', 'MITECO')],
            $this->spainCriteria('SIN GDO'),
        )->resolve(new ElectricityFactorInput('España', 2025));

        self::assertTrue($result->hasFactor());
        self::assertSame('0.258', $result->value);
        self::assertFalse($result->isGeographicProxy);
    }

    public function testResolvesSpainNationalMix(): void
    {
        $result = $this->resolver([$this->factor(2025, '0.258', 'MITECO')], $this->spainCriteria('SIN GDO'))
            ->resolve(new ElectricityFactorInput('ES', 2025));

        self::assertTrue($result->hasFactor());
        self::assertTrue($result->isCalculable());
        self::assertSame('0.258', $result->value);
        self::assertSame(2025, $result->factorYear);
        self::assertFalse($result->isFallback);
        self::assertFalse($result->isGeographicProxy);
    }

    public function testResolvesSpainRenewableGuaranteeAsValidZero(): void
    {
        $result = $this->resolver([$this->factor(2025, '0', 'MITECO')], $this->spainCriteria('GDO RENOVABLE'))
            ->resolve(new ElectricityFactorInput('ES', 2025, labeling: 'GDO RENOVABLE'));

        self::assertTrue($result->hasFactor());
        self::assertTrue($result->isCalculable());
        self::assertSame('0', $result->value);
    }

    public function testUsesCommonAnnualFallback(): void
    {
        $result = $this->resolver([$this->factor(2025, '0.258', 'MITECO')], $this->spainCriteria('SIN GDO'))
            ->resolve(new ElectricityFactorInput('ES', 2026));

        self::assertSame(2026, $result->activityYear);
        self::assertSame(2025, $result->factorYear);
        self::assertTrue($result->isFallback);
        self::assertSame('exact_year_missing', $result->fallbackReason);
    }

    public function testUsesDefraForUnitedKingdomWithoutGeographicProxy(): void
    {
        $result = $this->resolver([$this->factor(2026, '0.13096', 'DEFRA')], $this->outsideCriteria())
            ->resolve(new ElectricityFactorInput('GB', 2026));

        self::assertSame('DEFRA', $result->source);
        self::assertFalse($result->isGeographicProxy);
        self::assertNull($result->proxyGeography);
    }

    public function testUsesUkDefraAsGeographicProxyForAnotherCountry(): void
    {
        $result = $this->resolver([$this->factor(2026, '0.13096', 'DEFRA')], $this->outsideCriteria())
            ->resolve(new ElectricityFactorInput('FR', 2026));

        self::assertTrue($result->hasFactor());
        self::assertTrue($result->isGeographicProxy);
        self::assertSame('Reino Unido', $result->proxyGeography);
        self::assertSame('FR', $result->metadata['country']);
    }

    public function testOutsideSpain2022IsNotResolvableWithoutPriorFactor(): void
    {
        $result = $this->resolver([], $this->outsideCriteria())->resolve(new ElectricityFactorInput('FR', 2022));

        self::assertFalse($result->hasFactor());
        self::assertFalse($result->isCalculable());
        self::assertNull($result->value);
        self::assertFalse($result->isGeographicProxy);
    }

    public function testSolarIsExplicitOperationalRuleZero(): void
    {
        $result = $this->resolver([], [], expectedRepositoryCalls: 0)
            ->resolve(new ElectricityFactorInput('ES', 2026, ElectricityFactorInput::ORIGIN_SOLAR));

        self::assertTrue($result->hasFactor());
        self::assertTrue($result->isCalculable());
        self::assertSame('0', $result->value);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $result->temporalType);
        self::assertNull($result->factorYear);
        self::assertStringContainsString('lifecycle emissions are not zero', $result->metadata['boundary']);
    }

    /** @param list<EmissionFactor> $factors
     *  @param array<string, string> $expectedCriteria
     */
    private function resolver(array $factors, array $expectedCriteria, int $expectedRepositoryCalls = 1): ElectricityFactorResolver
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $expectedFunctionalKey = $keyGenerator->generate($expectedCriteria);
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->expects(self::exactly($expectedRepositoryCalls))
            ->method('findForActivityYear')
            ->willReturnCallback(static function (string $category, string $functionalKey, int $activityYear) use ($factors, $expectedFunctionalKey): ?EmissionFactor {
                self::assertSame('energy', $category);
                self::assertSame($expectedFunctionalKey, $functionalKey);
                foreach ($factors as $factor) {
                    if ($factor->getYear() <= $activityYear) {
                        return $factor;
                    }
                }

                return null;
            });
        $common = new EmissionFactorResolver($repository, $keyGenerator);

        return new ElectricityFactorResolver($common);
    }

    /** @return array<string, string> */
    private function spainCriteria(string $labeling): array
    {
        return [
            'geography' => 'ESPAÑA', 'category' => 'ELECTRICIDAD', 'activity' => 'PROMEDIO NACIONAL',
            'labeling' => $labeling, 'supplier' => '', 'unit' => 'kWh',
        ];
    }

    /** @return array<string, string> */
    private function outsideCriteria(): array
    {
        return [
            'geography' => 'FUERA DE ESPAÑA', 'category' => 'ELECTRICIDAD', 'activity' => 'PROMEDIO NACIONAL',
            'labeling' => '', 'supplier' => '', 'unit' => 'kWh',
        ];
    }

    private function factor(int $year, ?string $value, string $source): EmissionFactor
    {
        return (new EmissionFactor())
            ->setCategoryKey('energy')
            ->setFunctionalKey('test')
            ->setCriteria([])
            ->setYear($year)
            ->setValue($value)
            ->setUnit('kgCO2e/kWh')
            ->setSource($source)
            ->setSourceDetail($source.' electricity');
    }
}
