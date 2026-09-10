<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\WaterEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class WaterEmissionFactorFixturesTest extends TestCase
{
    /** @var list<EmissionFactor> */
    private array $factors = [];
    private EmissionFactorKeyGenerator $keyGenerator;

    protected function setUp(): void
    {
        $this->keyGenerator = new EmissionFactorKeyGenerator();
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(12))->method('persist')->willReturnCallback(function (object $factor): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');

        (new WaterEmissionFactorFixtures($this->keyGenerator))->load($manager);
    }

    public function testLoadsTheTwelveCanonicalFactorsWithoutIdentityCollisions(): void
    {
        self::assertCount(12, $this->factors);
        $identities = [];
        $factorIds = [];
        $geographies = [];
        $years = [];
        $temporalTypes = [];

        foreach ($this->factors as $factor) {
            self::assertSame('water', $factor->getCategoryKey());
            self::assertSame($this->keyGenerator->generate($factor->getCriteria()), $factor->getFunctionalKey());
            self::assertSame('kgCO2e/m3', $factor->getUnit());
            self::assertNotNull($factor->getFactorId());
            self::assertArrayNotHasKey($factor->getFactorId(), $factorIds);
            $factorIds[$factor->getFactorId()] = true;
            self::assertSame($factor->getYear(), $factor->getActivityYear());
            self::assertSame('Agua_Base_Maestra_y_Contrato_FINAL.xlsx', $factor->getMetadata()['sourceWorkbook']);
            self::assertSame('Factores', $factor->getMetadata()['sourceSheet']);

            $identity = $factor->getFunctionalKey().'|'.$factor->getYear();
            self::assertArrayNotHasKey($identity, $identities);
            $identities[$identity] = true;
            $geography = $factor->getCriteria()['geography'];
            $geographies[$geography] = ($geographies[$geography] ?? 0) + 1;
            $years[] = $factor->getYear();
            $temporalTypes[$factor->getTemporalType()] = ($temporalTypes[$factor->getTemporalType()] ?? 0) + 1;
        }

        self::assertCount(12, $identities);
        self::assertCount(12, $factorIds);
        self::assertSame(['Reino Unido' => 10, 'Cataluña' => 2], $geographies);
        self::assertSame([
            EmissionFactor::TEMPORAL_TYPE_ANNUAL => 10,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED => 2,
        ], $temporalTypes);
        sort($years);
        self::assertSame([2022, 2022, 2023, 2023, 2024, 2024, 2024, 2025, 2025, 2025, 2026, 2026], $years);
    }

    public function testPreservesExactValuesAndSourceMetadata(): void
    {
        self::assertSame('0.149', $this->factor('Reino Unido', 'water_supply', 2022)->getValue());
        self::assertSame('0.272', $this->factor('Reino Unido', 'water_treatment', 2022)->getValue());
        self::assertSame('0.1767', $this->factor('Reino Unido', 'water_supply', 2023)->getValue());
        self::assertSame('0.2013', $this->factor('Reino Unido', 'water_treatment', 2023)->getValue());
        self::assertSame('0.15311', $this->factor('Reino Unido', 'water_supply', 2024)->getValue());
        self::assertSame('0.18574', $this->factor('Reino Unido', 'water_treatment', 2024)->getValue());
        self::assertSame('0.1913', $this->factor('Reino Unido', 'water_supply', 2025)->getValue());
        self::assertSame('0.17088', $this->factor('Reino Unido', 'water_treatment', 2025)->getValue());
        self::assertSame('0.1913', $this->factor('Reino Unido', 'water_supply', 2026)->getValue());
        self::assertSame('0.17088', $this->factor('Reino Unido', 'water_treatment', 2026)->getValue());

        $occc2024 = $this->factor('Cataluña', 'urban_water_cycle', 2024);
        $occc2025 = $this->factor('Cataluña', 'urban_water_cycle', 2025);
        self::assertSame('0.517', $occc2024->getValue());
        self::assertSame('0.517', $occc2025->getValue());
        self::assertSame('OCCC', $occc2024->getSource());
        self::assertSame('2025', $occc2024->getMetadata()['sourceEdition']);
        self::assertSame('2025', $occc2024->getMetadata()['factorVersion']);
        self::assertSame('2026', $occc2025->getMetadata()['factorVersion']);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $occc2024->getTemporalType());
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $occc2025->getTemporalType());
        self::assertSame('Cataluña', $occc2024->getMetadata()['sourceGeography']);
        self::assertSame('urban_water_cycle', $occc2024->getMetadata()['factorType']);
        self::assertSame('m3', $occc2024->getMetadata()['activityUnit']);
        self::assertStringStartsWith('https://canviclimatic.gencat.cat/', $occc2024->getMetadata()['sourceUrl']);

        self::assertNull($this->findFactor('Cataluña', 'urban_water_cycle', 2022));
        self::assertNull($this->findFactor('Cataluña', 'urban_water_cycle', 2023));
    }

    private function factor(string $geography, string $factorType, int $year): EmissionFactor
    {
        $factor = $this->findFactor($geography, $factorType, $year);
        self::assertNotNull($factor);

        return $factor;
    }

    private function findFactor(string $geography, string $factorType, int $year): ?EmissionFactor
    {
        $functionalKey = $this->keyGenerator->generate([
            'geography' => $geography,
            'factorType' => $factorType,
            'unit' => 'm3',
        ]);
        foreach ($this->factors as $factor) {
            if ($factor->getFunctionalKey() === $functionalKey && $factor->getYear() === $year) {
                return $factor;
            }
        }

        return null;
    }
}
