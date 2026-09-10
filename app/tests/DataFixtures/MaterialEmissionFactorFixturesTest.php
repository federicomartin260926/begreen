<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\MaterialEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class MaterialEmissionFactorFixturesTest extends TestCase
{
    /** @var list<EmissionFactor> */
    private array $factors = [];

    protected function setUp(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(2000))->method('persist')->willReturnCallback(function (object $factor): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');

        (new MaterialEmissionFactorFixtures(new EmissionFactorKeyGenerator()))->load($manager);
    }

    public function testLoadsCanonicalRowsWithNullableMethodologicalYearsAndUniqueIdentities(): void
    {
        self::assertCount(2000, $this->factors);
        self::assertCount(883, $this->byTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL));
        self::assertCount(931, $this->byTemporalType(EmissionFactor::TEMPORAL_TYPE_VERSIONED));
        self::assertCount(186, $this->byTemporalType(EmissionFactor::TEMPORAL_TYPE_RULE));

        $factorIds = [];
        $identities = [];
        $activityYears = [];
        $nullFactorYears = 0;
        $nullFactorVersions = 0;
        $zeroValues = 0;
        $keyGenerator = new EmissionFactorKeyGenerator();
        foreach ($this->factors as $factor) {
            self::assertSame('material', $factor->getCategoryKey());
            self::assertSame($keyGenerator->generate($factor->getCriteria()), $factor->getFunctionalKey());
            self::assertNotNull($factor->getFactorId());
            self::assertArrayNotHasKey($factor->getFactorId(), $factorIds);
            $factorIds[$factor->getFactorId()] = true;
            self::assertContains($factor->getActivityYear(), [2022, 2023, 2024, 2025, 2026]);
            $activityYears[$factor->getActivityYear()] = ($activityYears[$factor->getActivityYear()] ?? 0) + 1;
            $nullFactorYears += null === $factor->getYear() ? 1 : 0;
            $nullFactorVersions += null === $factor->getMetadata()['factorVersion'] ? 1 : 0;
            $zeroValues += '0' === $factor->getValue() ? 1 : 0;
            $identity = $factor->getFunctionalKey().'|'.$factor->getActivityYear();
            self::assertArrayNotHasKey($identity, $identities);
            $identities[$identity] = true;
            self::assertSame('TODOS', $factor->getMetadata()['geography']);
            self::assertFalse($factor->getMetadata()['isGeographicProxy']);
        }
        self::assertCount(2000, $factorIds);
        self::assertSame([2022 => 400, 2023 => 400, 2024 => 400, 2025 => 400, 2026 => 400], $activityYears);
        self::assertSame(1117, $nullFactorYears);
        self::assertSame(1786, $nullFactorVersions);
        self::assertSame(934, $zeroValues);
    }

    public function testPreservesCorrectedAnnual2026VersionedAndRuleTraceability(): void
    {
        $annual = $this->find('Madera', null, 'Producción de materia prima', 'kg', 2026);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_ANNUAL, $annual->getTemporalType());
        self::assertSame(2026, $annual->getYear());
        self::assertSame('0.26950416', $annual->getValue());
        self::assertSame('DEFRA 2026', $annual->getMetadata()['factorVersion']);

        $versioned = $this->find('Ropa y accesorios', 'Top tirantes', 'Reutilizado', 'ud', 2026);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $versioned->getTemporalType());
        self::assertNull($versioned->getYear());
        self::assertSame('0.15225', $versioned->getValue());

        $rule = $this->find('Papel', null, 'Reutilizado', 'kg', 2026);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $rule->getTemporalType());
        self::assertNull($rule->getYear());
        self::assertSame('0', $rule->getValue());
        self::assertStringContainsString('Regla explícita: reutilizado = 0', (string) $rule->getSourceDetail());

        $recycledWood2022 = $this->find('Madera', null, 'Reciclado (Circuito cerrado)', 'kg', 2022);
        self::assertSame('0.112969683723424', $recycledWood2022->getValue());
        self::assertSame('MAT_071091A9C47616', $recycledWood2022->getFactorId());
    }

    /** @return list<EmissionFactor> */
    private function byTemporalType(string $temporalType): array
    {
        return array_values(array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool => $temporalType === $factor->getTemporalType(),
        ));
    }

    private function find(string $activity, ?string $subproduct, string $origin, string $unit, int $activityYear): EmissionFactor
    {
        foreach ($this->factors as $factor) {
            $criteria = $factor->getCriteria();
            if (
                $activity === ($criteria['activity'] ?? null)
                && ($subproduct ?? '') === ($criteria['subproduct'] ?? null)
                && $origin === ($criteria['origin'] ?? null)
                && $unit === ($criteria['unit'] ?? null)
                && $activityYear === $factor->getActivityYear()
            ) {
                return $factor;
            }
        }

        self::fail('Expected material emission factor was not loaded.');
    }
}
