<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Waste;

use App\DataFixtures\WasteEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Waste\WasteFactorResolver;
use App\Service\Emission\Waste\WasteUiCatalog;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class WasteFactorResolverTest extends TestCase
{
    private WasteFactorResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = $this->resolver();
    }

    public function testOcccVersionedAndDefraAnnualProxyTraceAreDistinct(): void
    {
        $occc = $this->resolver->resolve(
            'ESP',
            'Orgánico (residuos de jardín)',
            'Orgánico (residuos de jardín)',
            'Compostaje',
            2025,
        );
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $occc->temporalType);
        self::assertSame('0.24542', $occc->factorValue);
        self::assertNull($occc->factorYear);
        self::assertSame('ESP', $occc->sourceGeography);
        self::assertFalse($occc->isGeographicProxy);

        $spainDefra = $this->resolver->resolve(
            'ESP',
            'Construcción / Set promedio',
            'Construcción / Set promedio',
            'Reciclaje (Bucle abierto)',
            2025,
        );
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_ANNUAL, $spainDefra->temporalType);
        self::assertSame(2025, $spainDefra->factorYear);
        self::assertSame('GBR', $spainDefra->sourceGeography);
        self::assertTrue($spainDefra->isGeographicProxy);
        self::assertSame('spain_factor_unavailable_defra_uk_proxy', $spainDefra->geographicProxyReason);

        $uk = $this->resolver->resolve(
            'GBR',
            'Residuos domésticos residuales',
            'Residuos domésticos residuales',
            'Vertedero',
            2025,
        );
        self::assertFalse($uk->isGeographicProxy);
        self::assertSame('GBR', $uk->sourceGeography);

        $france = $this->resolver->resolve(
            'FRA',
            'Residuos domésticos residuales',
            'Residuos domésticos residuales',
            'Vertedero',
            2025,
        );
        self::assertTrue($france->isGeographicProxy);
        self::assertSame('defra_uk_geographic_proxy', $france->geographicProxyReason);
    }

    public function testUnknownUsesHighestCalculableConventionalFactorAndKeepsCandidates(): void
    {
        $resolution = $this->resolver->resolve(
            'ESP',
            'Orgánico (residuos de jardín)',
            'Orgánico (residuos de jardín)',
            'Desconocido',
            2025,
        );

        self::assertTrue($resolution->isCalculable());
        self::assertSame('DERIVED_MAX_VALID_TREATMENTS', $resolution->ruleType);
        self::assertSame('Desconocido', $resolution->requestedTreatment);
        self::assertSame('Compostaje', $resolution->resolvedTreatment);
        self::assertSame('0.24542', $resolution->factorValue);
        self::assertCount(2, $resolution->candidateEvaluations);
        self::assertSame(
            ['Compostaje', 'Digestión anaeróbica (recuperación de energía)'],
            array_column($resolution->candidateEvaluations, 'resolvedTreatment'),
        );
    }

    public function testNonWasteRouteZeroIsRuleAndAnnualFallbackNeverUsesFutureFactor(): void
    {
        $zero = $this->resolver->resolve(
            'ESP',
            'Construcción / Set promedio',
            'Construcción / Set promedio',
            'Reutilización / Donación',
            2025,
        );
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $zero->temporalType);
        self::assertSame('NON_WASTE_ROUTE_ZERO', $zero->ruleType);
        self::assertSame('0', $zero->factorValue);
        self::assertNull($zero->factorYear);

        $fallback = $this->resolver->resolve(
            'FRA',
            'Residuos domésticos residuales',
            'Residuos domésticos residuales',
            'Vertedero',
            2027,
        );
        self::assertSame(2026, $fallback->factorYear);
        self::assertTrue($fallback->isFallback);
        self::assertSame('exact_year_missing', $fallback->fallbackReason);

        $before = $this->resolver->resolve(
            'FRA',
            'Residuos domésticos residuales',
            'Residuos domésticos residuales',
            'Vertedero',
            2021,
        );
        self::assertFalse($before->hasFactor());
        self::assertNull($before->factorYear);
    }

    public function testOcccVersionedFallsBackToLatestPriorApplicabilityYear(): void
    {
        $resolution = $this->resolver->resolve(
            'ESP',
            'Orgánico (residuos de jardín)',
            'Orgánico (residuos de jardín)',
            'Compostaje',
            2027,
        );

        self::assertTrue($resolution->hasFactor());
        self::assertSame('0.24542', $resolution->factorValue);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $resolution->temporalType);
        self::assertNull($resolution->factorYear);
        self::assertTrue($resolution->isFallback);
        self::assertSame('exact_year_missing', $resolution->fallbackReason);
    }

    private function resolver(): WasteFactorResolver
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
        $repository->method('findForApplicabilityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('waste', $categoryKey);
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool =>
                        $factor->getFunctionalKey() === $functionalKey
                        && null !== $factor->getActivityYear()
                        && $factor->getActivityYear() <= $activityYear
                        && (null === $factor->getYear() || $factor->getYear() <= $activityYear),
                );
                usort(
                    $candidates,
                    static fn (EmissionFactor $left, EmissionFactor $right): int =>
                        $right->getActivityYear() <=> $left->getActivityYear()
                        ?: strcmp((string) $left->getFactorId(), (string) $right->getFactorId()),
                );

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                self::assertSame('waste', $categoryKey);
                foreach ($factors as $factor) {
                    if ($temporalType === $factor->getTemporalType() && $functionalKey === $factor->getFunctionalKey()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new WasteFactorResolver(
            new EmissionFactorResolver($repository, $keyGenerator),
            new WasteUiCatalog(),
        );
    }
}
