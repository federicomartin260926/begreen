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
        $manager
            ->expects(self::exactly(1270))
            ->method('persist')
            ->willReturnCallback(function (object $factor): void {
                self::assertInstanceOf(EmissionFactor::class, $factor);
                $this->factors[] = $factor;
            });
        $manager->expects(self::once())->method('flush');

        (new WasteEmissionFactorFixtures(new EmissionFactorKeyGenerator()))->load($manager);
    }

    public function testLoadsLiteralMasterFactorsWithUniqueApplicabilityIdentity(): void
    {
        self::assertCount(1270, $this->factors);
        self::assertCount(340, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool =>
                EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType(),
        ));
        self::assertCount(930, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool =>
                EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType(),
        ));
        self::assertCount(0, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool =>
                EmissionFactor::TEMPORAL_TYPE_RULE === $factor->getTemporalType(),
        ));

        self::assertCount(0, array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool =>
                'Desconocido' === ($factor->getCriteria()['treatment'] ?? null),
        ));

        $keyGenerator = new EmissionFactorKeyGenerator();
        $factorIds = [];
        $applicabilityIdentities = [];

        foreach ($this->factors as $factor) {
            self::assertSame('waste', $factor->getCategoryKey());
            self::assertSame(
                $keyGenerator->generate($factor->getCriteria()),
                $factor->getFunctionalKey(),
            );
            self::assertNotNull($factor->getFactorId());
            self::assertNotNull($factor->getActivityYear());

            self::assertArrayNotHasKey($factor->getFactorId(), $factorIds);
            $factorIds[$factor->getFactorId()] = true;

            $identity = $factor->getFunctionalKey().'|'.$factor->getActivityYear();
            self::assertArrayNotHasKey($identity, $applicabilityIdentities);
            $applicabilityIdentities[$identity] = true;
        }

        self::assertCount(1270, $factorIds);
        self::assertCount(1270, $applicabilityIdentities);
    }

    public function testPreservesRepresentativeOcccAndDefraTraceability(): void
    {
        $occc = $this->findFactor(
            'spain',
            'Residuo general (no recogida selectiva)',
            'Residuo general (no recogida selectiva)',
            'Gestión municipal (mezcla real)',
            'OCCC',
            2022,
            null,
        );

        self::assertSame('RES_581D4FAA49C5E6', $occc->getFactorId());
        self::assertSame(2022, $occc->getActivityYear());
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $occc->getTemporalType());
        self::assertNull($occc->getYear());
        self::assertSame('0.71647', $occc->getValue());
        self::assertSame('OCCC', $occc->getSource());
        self::assertSame('OCCC v2025', $occc->getMetadata()['factorVersion']);
        self::assertSame('VALIDATED_FROM_REFERENCE', $occc->getMetadata()['qualityStatus']);
        self::assertSame('ESP', $occc->getMetadata()['sourceGeography']);
        self::assertFalse($occc->getMetadata()['isTemporalFallback']);
        self::assertFalse($occc->getMetadata()['isGeographicProxy']);

        $defra = $this->findFactor(
            'outside_spain',
            'Residuos domésticos residuales',
            'Residuos domésticos residuales',
            'Vertedero',
            'DEFRA',
            2022,
            2022,
        );

        self::assertSame(2022, $defra->getActivityYear());
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_ANNUAL, $defra->getTemporalType());
        self::assertSame(2022, $defra->getYear());
        self::assertSame('0.4462041084', $defra->getValue());
        self::assertSame('DEFRA', $defra->getSource());
        self::assertSame('GBR', $defra->getMetadata()['sourceGeography']);
        self::assertFalse($defra->getMetadata()['isTemporalFallback']);
        self::assertFalse($defra->getMetadata()['isGeographicProxy']);
    }

    private function findFactor(
        string $regionScope,
        string $wasteType,
        string $wasteActivity,
        string $treatment,
        string $sourceFamily,
        int $activityYear,
        ?int $factorYear,
    ): EmissionFactor {
        foreach ($this->factors as $factor) {
            $criteria = $factor->getCriteria();

            if (
                ($criteria['regionScope'] ?? null) === $regionScope
                && ($criteria['wasteType'] ?? null) === $wasteType
                && ($criteria['wasteActivity'] ?? null) === $wasteActivity
                && ($criteria['treatment'] ?? null) === $treatment
                && ($criteria['sourceFamily'] ?? null) === $sourceFamily
                && $factor->getActivityYear() === $activityYear
                && $factor->getYear() === $factorYear
            ) {
                return $factor;
            }
        }

        self::fail('Expected waste emission factor was not loaded.');
    }
}
