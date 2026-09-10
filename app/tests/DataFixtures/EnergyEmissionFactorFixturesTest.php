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
        $manager->expects(self::exactly(1414))->method('persist')->willReturnCallback(function (object $factor): void {
            if (!$factor instanceof EmissionFactor) {
                self::fail('Energy fixture persisted an unexpected entity type.');
            }
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');
        (new EnergyEmissionFactorFixtures($this->keyGenerator))->load($manager);
    }

    public function testLoadsLiteralAnnualRowsIncludingFallbacksAndLNgReviews(): void
    {
        self::assertCount(1414, $this->factors);
        $identities = [];
        $factorIds = [];
        $temporalTypes = [];
        $categories = [];
        $geographies = [];
        $activityYears = [];
        $factorYears = [];
        foreach ($this->factors as $factor) {
            $identity = $factor->getCategoryKey().'|'.$factor->getFunctionalKey().'|'.$factor->getActivityYear().'|'.$factor->getYear();
            $identities[$identity] = true;
            self::assertNotNull($factor->getFactorId());
            self::assertArrayNotHasKey($factor->getFactorId(), $factorIds);
            $factorIds[$factor->getFactorId()] = true;
            $temporalTypes[$factor->getTemporalType()] = ($temporalTypes[$factor->getTemporalType()] ?? 0) + 1;
            $category = $factor->getCriteria()['category'];
            $geography = $factor->getCriteria()['geography'];
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $geographies[$geography] = ($geographies[$geography] ?? 0) + 1;
            $activityYears[$factor->getActivityYear()] = ($activityYears[$factor->getActivityYear()] ?? 0) + 1;
            $factorYears[$factor->getYear()] = ($factorYears[$factor->getYear()] ?? 0) + 1;
            self::assertNull($factor->getMetadata()['factorVersion']);
            self::assertNotNull($factor->getValue());
        }
        ksort($categories);
        ksort($geographies);
        ksort($activityYears);
        ksort($factorYears);
        self::assertCount(1411, $identities);
        self::assertCount(1414, $factorIds);
        self::assertSame([EmissionFactor::TEMPORAL_TYPE_ANNUAL => 1414], $temporalTypes);
        self::assertSame(['COMBUSTIÓN ESTACIONARIA' => 134, 'ELECTRICIDAD' => 1264, 'ELECTRICIDAD_COMPONENTE' => 16], $categories);
        self::assertSame(['ESPAÑA' => 1320, 'FUERA DE ESPAÑA' => 78, 'TODOS' => 16], $geographies);
        self::assertSame([2022 => 205, 2023 => 247, 2024 => 272, 2025 => 294, 2026 => 396], $activityYears);
        self::assertSame([2022 => 244, 2023 => 278, 2024 => 304, 2025 => 567, 2026 => 21], $factorYears);
        self::assertCount(375, array_filter($this->factors, static fn (EmissionFactor $factor): bool => true === $factor->getMetadata()['isTemporalFallback']));
        self::assertCount(84, array_filter($this->factors, static fn (EmissionFactor $factor): bool => true === $factor->getMetadata()['isGeographicProxy']));
        self::assertCount(551, array_filter($this->factors, static fn (EmissionFactor $factor): bool => '0' === $factor->getValue()));

        $spain = $this->factor($this->electricityCriteria('ESPAÑA', 'SIN GDO'), 2025, 2025);
        self::assertSame('0.258', $spain->getValue());
        self::assertSame('MITECO', $spain->getSource());
        self::assertSame('kgCO2e/kWh', $spain->getUnit());

        $defra = $this->factor($this->electricityCriteria('FUERA DE ESPAÑA', ''), 2023, 2023);
        self::assertSame('0.207074288590604', $defra->getValue());
        self::assertSame('DEFRA', $defra->getSource());

        $renewable = $this->factor($this->electricityCriteria('ESPAÑA', 'GDO RENOVABLE'), 2025, 2025);
        self::assertSame('0', $renewable->getValue());
        self::assertNotNull($renewable->getValue());

        $spain2026 = $this->factor($this->electricityCriteria('ESPAÑA', 'SIN GDO'), 2026, 2025);
        self::assertSame('ENE_BEA7F2F5AED385', $spain2026->getFactorId());
        self::assertTrue($spain2026->getMetadata()['isTemporalFallback']);

        $lngReviews = array_filter($this->factors, static fn (EmissionFactor $factor): bool => str_contains((string) $factor->getFactorId(), '_R0677'));
        self::assertCount(3, $lngReviews);
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
    private function factor(array $criteria, int $activityYear, int $factorYear): EmissionFactor
    {
        $factor = $this->findFactor($criteria, $activityYear, $factorYear);
        self::assertNotNull($factor);

        return $factor;
    }

    /** @param array<string, string> $criteria */
    private function findFactor(array $criteria, int $activityYear, int $factorYear): ?EmissionFactor
    {
        $functionalKey = $this->keyGenerator->generate($criteria);
        foreach ($this->factors as $factor) {
            if ($factor->getFunctionalKey() === $functionalKey && $factor->getActivityYear() === $activityYear && $factor->getYear() === $factorYear) {
                return $factor;
            }
        }

        return null;
    }
}
