<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\EnergyEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class EnergyEmissionFactorFixturesTest extends TestCase
{
    /** @var list<EmissionFactor> */
    private array $factors = [];
    private EmissionFactorKeyGenerator $keyGenerator;

    protected function setUp(): void
    {
        $this->keyGenerator = new EmissionFactorKeyGenerator();
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(1036))->method('persist')->willReturnCallback(function (object $factor): void {
            if (!$factor instanceof EmissionFactor) {
                self::fail('Energy fixture persisted an unexpected entity type.');
            }
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');
        (new EnergyEmissionFactorFixtures($this->keyGenerator))->load($manager);
    }

    public function testLoadsCanonicalSamplesWithoutMaterializedFallbackOrIdentityCollisions(): void
    {
        self::assertCount(1036, $this->factors);
        $identities = [];
        $temporalTypes = [];
        foreach ($this->factors as $factor) {
            $identity = $factor->getCategoryKey().'|'.$factor->getFunctionalKey().'|'.$factor->getYear();
            $identities[$identity] = true;
            $temporalTypes[$factor->getTemporalType()] = ($temporalTypes[$factor->getTemporalType()] ?? 0) + 1;
        }
        self::assertCount(1036, $identities);
        self::assertSame([EmissionFactor::TEMPORAL_TYPE_ANNUAL => 1036], $temporalTypes);

        $spain = $this->factor($this->electricityCriteria('ESPAÑA', 'SIN GDO'), 2025);
        self::assertSame('0.258', $spain->getValue());
        self::assertSame('MITECO', $spain->getSource());
        self::assertSame('kgCO2e/kWh', $spain->getUnit());

        $defra = $this->factor($this->electricityCriteria('FUERA DE ESPAÑA', ''), 2023);
        self::assertSame('0.207074288590604', $defra->getValue());
        self::assertSame('DEFRA', $defra->getSource());

        $renewable = $this->factor($this->electricityCriteria('ESPAÑA', 'GDO RENOVABLE'), 2025);
        self::assertSame('0', $renewable->getValue());
        self::assertNotNull($renewable->getValue());

        self::assertNull($this->findFactor($this->electricityCriteria('ESPAÑA', 'SIN GDO'), 2026));
    }

    /** @return array<string, string> */
    private function electricityCriteria(string $geography, string $labeling): array
    {
        return [
            'geography' => $geography,
            'category' => 'ELECTRICIDAD',
            'activity' => 'PROMEDIO NACIONAL',
            'labeling' => $labeling,
            'supplier' => '',
            'unit' => 'kWh',
        ];
    }

    /** @param array<string, string> $criteria */
    private function factor(array $criteria, int $year): EmissionFactor
    {
        $factor = $this->findFactor($criteria, $year);
        self::assertNotNull($factor);

        return $factor;
    }

    /** @param array<string, string> $criteria */
    private function findFactor(array $criteria, int $year): ?EmissionFactor
    {
        $functionalKey = $this->keyGenerator->generate($criteria);
        foreach ($this->factors as $factor) {
            if ($factor->getFunctionalKey() === $functionalKey && $factor->getYear() === $year) {
                return $factor;
            }
        }

        return null;
    }
}
