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
        $manager->expects(self::exactly(508))->method('persist')->willReturnCallback(function (object $factor): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');

        (new MaterialEmissionFactorFixtures(new EmissionFactorKeyGenerator()))->load($manager);
    }

    public function testLoadsCanonicalRowsWithNullableMethodologicalYearsAndUniqueIdentities(): void
    {
        self::assertCount(508, $this->factors);
        self::assertCount(135, $this->byTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL));
        self::assertCount(187, $this->byTemporalType(EmissionFactor::TEMPORAL_TYPE_VERSIONED));
        self::assertCount(186, $this->byTemporalType(EmissionFactor::TEMPORAL_TYPE_RULE));

        $logicalVersionedKeys = [];
        $identities = [];
        $keyGenerator = new EmissionFactorKeyGenerator();
        foreach ($this->factors as $factor) {
            self::assertSame('material', $factor->getCategoryKey());
            self::assertSame($keyGenerator->generate($factor->getCriteria()), $factor->getFunctionalKey());
            if (EmissionFactor::TEMPORAL_TYPE_ANNUAL !== $factor->getTemporalType()) {
                self::assertNull($factor->getYear());
            }
            if (EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType()) {
                self::assertArrayNotHasKey($factor->getFunctionalKey(), $logicalVersionedKeys);
                $logicalVersionedKeys[$factor->getFunctionalKey()] = true;
            }
            $identity = $factor->getFunctionalKey().'|'.$factor->getTemporalType().'|'.($factor->getYear() ?? 'NULL');
            self::assertArrayNotHasKey($identity, $identities);
            $identities[$identity] = true;
        }
        self::assertCount(187, $logicalVersionedKeys);
    }

    public function testPreservesCorrectedAnnual2026VersionedAndRuleTraceability(): void
    {
        $annual = $this->find('Madera', null, 'Producción de materia prima', 'kg', 2026);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_ANNUAL, $annual->getTemporalType());
        self::assertSame(2026, $annual->getYear());
        self::assertSame('0.26950416', $annual->getValue());
        self::assertSame('DEFRA 2026', $annual->getMetadata()['factorVersion']);

        $versioned = $this->find('Ropa y accesorios', 'Top tirantes', 'Reutilizado', 'ud', null);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $versioned->getTemporalType());
        self::assertNull($versioned->getYear());
        self::assertSame('0.15225', $versioned->getValue());

        $rule = $this->find('Papel', null, 'Reutilizado', 'kg', null);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $rule->getTemporalType());
        self::assertNull($rule->getYear());
        self::assertSame('0', $rule->getValue());
        self::assertSame('Regla explícita: reutilizado = 0 de cuna a puerta', $rule->getMetadata()['factorBasis']);
    }

    /** @return list<EmissionFactor> */
    private function byTemporalType(string $temporalType): array
    {
        return array_values(array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool => $temporalType === $factor->getTemporalType(),
        ));
    }

    private function find(string $activity, ?string $subproduct, string $origin, string $unit, ?int $year): EmissionFactor
    {
        foreach ($this->factors as $factor) {
            $criteria = $factor->getCriteria();
            if (
                $activity === ($criteria['activity'] ?? null)
                && ($subproduct ?? '') === ($criteria['subproduct'] ?? null)
                && $origin === ($criteria['origin'] ?? null)
                && $unit === ($criteria['unit'] ?? null)
                && $year === $factor->getYear()
            ) {
                return $factor;
            }
        }

        self::fail('Expected material emission factor was not loaded.');
    }
}
