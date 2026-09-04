<?php

namespace App\Tests\Service;

use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolution;
use App\Service\Emission\EmissionFactorResolver;
use PHPUnit\Framework\TestCase;

final class EmissionFactorResolverTest extends TestCase
{
    private EmissionFactorKeyGenerator $keyGenerator;

    protected function setUp(): void
    {
        $this->keyGenerator = new EmissionFactorKeyGenerator();
    }

    public function testFunctionalKeyIsStableWithoutChangingScalarSemantics(): void
    {
        $first = [
            'fuel' => 'Bioetanol 100%  (E100)',
            'area' => 'ESPAÑA',
            'nested' => ['z' => 1, 'a' => 'Value'],
        ];
        $reordered = [
            'nested' => ['a' => 'Value', 'z' => 1],
            'area' => 'ESPAÑA',
            'fuel' => 'Bioetanol 100%  (E100)',
        ];

        self::assertSame($this->keyGenerator->generate($first), $this->keyGenerator->generate($reordered));
        self::assertNotSame(
            $this->keyGenerator->generate($first),
            $this->keyGenerator->generate(array_replace($first, ['fuel' => 'Bioetanol 100% (E100)'])),
        );
        self::assertSame('Bioetanol 100%  (E100)', $this->keyGenerator->normalize($first)['fuel']);
    }

    public function testReturnsExactFactorFromRequestedYear(): void
    {
        $factor = $this->factor(2024, '0.02004', 'OCCC');
        $resolver = $this->resolverReturning($factor, 2024);

        $result = $resolver->resolve('transport', [
            'area' => 'ESPAÑA',
            'subcategory' => 'MERCANCÍAS',
            'activity' => 'Tren',
            'fuel' => 'Híbrido (electricidad + gasoil)',
            'unit' => 'km*tonelada',
            'method' => 'distancia',
        ], 2024);

        self::assertTrue($result->hasFactor());
        self::assertTrue($result->isCalculable());
        self::assertSame(2024, $result->activityYear);
        self::assertSame(2024, $result->factorYear);
        self::assertSame('0.02004', $result->factor?->getValue());
        self::assertSame('OCCC', $result->factor?->getSource());
        self::assertFalse($result->isFallback);
        self::assertNull($result->fallbackReason);
    }

    public function testExactFactorWithExplicitNullDoesNotFallBack(): void
    {
        $factor = $this->factor(2024, null, 'OCCC');
        $resolver = $this->resolverReturning($factor, 2024);

        $result = $resolver->resolve('transport', [
            'unit' => 'km*tonelada',
            'activity' => 'Tren',
        ], 2024);

        self::assertTrue($result->hasFactor());
        self::assertFalse($result->isCalculable());
        self::assertSame($factor, $result->factor);
        self::assertSame(2024, $result->activityYear);
        self::assertSame(2024, $result->factorYear);
        self::assertFalse($result->isFallback);
        self::assertNull($result->fallbackReason);
        self::assertNull($result->factor?->getValue());
    }

    public function testFallsBackToLatestPriorYearFor2026(): void
    {
        $factor = $this->factor(2025, '0.15154', 'DEFRA');
        $resolver = $this->resolverReturning($factor, 2026);

        $result = $resolver->resolveByFunctionalKey('transport', 'phev-key', 2026);

        self::assertTrue($result->hasFactor());
        self::assertTrue($result->isCalculable());
        self::assertSame(2026, $result->activityYear);
        self::assertSame(2025, $result->factorYear);
        self::assertTrue($result->isFallback);
        self::assertSame(
            EmissionFactorResolution::FALLBACK_REASON_EXACT_YEAR_MISSING,
            $result->fallbackReason,
        );
    }

    public function testNeverUsesFutureFactor(): void
    {
        $resolver = $this->resolverReturning(null, 2023);

        $result = $resolver->resolveByFunctionalKey('transport', 'train-key', 2023);

        self::assertFalse($result->hasFactor());
        self::assertFalse($result->isCalculable());
        self::assertNull($result->factor);
        self::assertNull($result->factorYear);
        self::assertFalse($result->isFallback);
        self::assertNull($result->fallbackReason);
    }

    public function testCombinationStartingIn2023IsUnavailableIn2022AndExactIn2023(): void
    {
        $factor2023 = $this->factor(2023, '0.13292', 'DEFRA');
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findForActivityYear')
            ->willReturnCallback(static function (string $category, string $key, int $year) use ($factor2023): ?EmissionFactor {
                self::assertSame('transport', $category);
                self::assertSame('phev-key', $key);

                return 2023 === $year ? $factor2023 : null;
            });
        $resolver = new EmissionFactorResolver($repository, $this->keyGenerator);

        $unavailable = $resolver->resolveByFunctionalKey('transport', 'phev-key', 2022);
        self::assertFalse($unavailable->hasFactor());
        self::assertFalse($unavailable->isCalculable());

        $exact = $resolver->resolveByFunctionalKey('transport', 'phev-key', 2023);
        self::assertTrue($exact->hasFactor());
        self::assertTrue($exact->isCalculable());
        self::assertSame(2023, $exact->factorYear);
        self::assertFalse($exact->isFallback);
    }

    public function testZeroHasFactorAndIsCalculable(): void
    {
        $factor = $this->factor(2025, '0', 'DEFRA');
        $resolver = $this->resolverReturning($factor, 2025);

        $result = $resolver->resolveByFunctionalKey('transport', 'bev-key', 2025);

        self::assertTrue($result->hasFactor());
        self::assertTrue($result->isCalculable());
        self::assertSame('0', $result->factor?->getValue());
        self::assertFalse($result->isFallback);
    }

    private function resolverReturning(?EmissionFactor $factor, int $activityYear): EmissionFactorResolver
    {
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->expects(self::once())
            ->method('findForActivityYear')
            ->with('transport', self::anything(), $activityYear)
            ->willReturn($factor);

        return new EmissionFactorResolver($repository, $this->keyGenerator);
    }

    private function factor(int $year, ?string $value, string $source): EmissionFactor
    {
        return (new EmissionFactor())
            ->setCategoryKey('transport')
            ->setFunctionalKey('unused-in-test')
            ->setCriteria([])
            ->setYear($year)
            ->setValue($value)
            ->setUnit('km')
            ->setSource($source);
    }
}
