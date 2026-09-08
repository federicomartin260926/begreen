<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\WasteEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class WasteEmissionFactorFixturesTest extends TestCase
{
    /** @var list<EmissionFactor> */
    private array $factors = [];

    protected function setUp(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(551))->method('persist')->willReturnCallback(function (object $factor): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');

        (new WasteEmissionFactorFixtures(new EmissionFactorKeyGenerator()))->load($manager);
    }

    public function testLoadsNormalizedOfficialFactorsAndMethodologicalZeroRules(): void
    {
        self::assertCount(551, $this->factors);
        self::assertCount(340, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool => EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType(),
        ));
        self::assertCount(186, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool => EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType(),
        ));
        self::assertCount(25, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool => EmissionFactor::TEMPORAL_TYPE_RULE === $factor->getTemporalType(),
        ));

        self::assertCount(0, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool => 'Desconocido' === ($factor->getCriteria()['treatment'] ?? null),
        ));

        $keyGenerator = new EmissionFactorKeyGenerator();
        $identities = [];
        foreach ($this->factors as $factor) {
            self::assertSame('waste', $factor->getCategoryKey());
            self::assertSame($keyGenerator->generate($factor->getCriteria()), $factor->getFunctionalKey());
            $identity = $factor->getFunctionalKey().'|'.$factor->getTemporalType().'|'.($factor->getYear() ?? 'NULL');
            self::assertArrayNotHasKey($identity, $identities);
            $identities[$identity] = true;
        }
    }

    public function testPreservesRepresentativeOcccDefraAndRuleTraceability(): void
    {
        $occc = $this->findFactor(
            'spain',
            'Residuo general (no recogida selectiva)',
            'Residuo general (no recogida selectiva)',
            'Gestión municipal (mezcla real)',
            'OCCC',
            null,
        );
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $occc->getTemporalType());
        self::assertNull($occc->getYear());
        self::assertSame('0.71647', $occc->getValue());
        self::assertSame('OCCC', $occc->getSource());
        self::assertSame('OCCC v2025', $occc->getMetadata()['factorVersion']);
        self::assertSame('2022-2026', $occc->getMetadata()['activityYearScope']);

        $defra = $this->findFactor(
            'outside_spain',
            'Residuos domésticos residuales',
            'Residuos domésticos residuales',
            'Vertedero',
            'DEFRA',
            2022,
        );
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_ANNUAL, $defra->getTemporalType());
        self::assertSame(2022, $defra->getYear());
        self::assertSame('0.4462041084', $defra->getValue());
        self::assertSame('VALIDATED_DEFRA_2022_RECONSTRUCTED', $defra->getMetadata()['status']);
        self::assertSame('GBR', $defra->getMetadata()['sourceGeography']);

        $rule = $this->findRule(
            'spain',
            'Construcción / Set promedio',
            'Construcción / Set promedio',
            'Reutilización / Donación',
        );
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $rule->getTemporalType());
        self::assertNull($rule->getYear());
        self::assertSame('0', $rule->getValue());
        self::assertSame('NON_WASTE_ROUTE_ZERO', $rule->getMetadata()['ruleType']);
        self::assertSame('BGMF Residuos specification', $rule->getSource());
    }

    private function findFactor(
        string $regionScope,
        string $wasteType,
        string $wasteActivity,
        string $treatment,
        string $sourceFamily,
        ?int $year,
    ): EmissionFactor {
        foreach ($this->factors as $factor) {
            $criteria = $factor->getCriteria();
            if (
                ($criteria['regionScope'] ?? null) === $regionScope
                && ($criteria['wasteType'] ?? null) === $wasteType
                && ($criteria['wasteActivity'] ?? null) === $wasteActivity
                && ($criteria['treatment'] ?? null) === $treatment
                && ($criteria['sourceFamily'] ?? null) === $sourceFamily
                && $factor->getYear() === $year
            ) {
                return $factor;
            }
        }

        self::fail('Expected waste emission factor was not loaded.');
    }

    private function findRule(
        string $regionScope,
        string $wasteType,
        string $wasteActivity,
        string $treatment,
    ): EmissionFactor {
        foreach ($this->factors as $factor) {
            $criteria = $factor->getCriteria();
            if (
                EmissionFactor::TEMPORAL_TYPE_RULE === $factor->getTemporalType()
                && ($criteria['regionScope'] ?? null) === $regionScope
                && ($criteria['wasteType'] ?? null) === $wasteType
                && ($criteria['wasteActivity'] ?? null) === $wasteActivity
                && ($criteria['treatment'] ?? null) === $treatment
            ) {
                return $factor;
            }
        }

        self::fail('Expected waste methodological rule was not loaded.');
    }
}
