<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\TransportEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class TransportEmissionFactorFixturesTest extends TestCase
{
    /** @var list<EmissionFactor> */
    private array $factors;
    private EmissionFactorKeyGenerator $keyGenerator;

    protected function setUp(): void
    {
        $this->factors = [];
        $this->keyGenerator = new EmissionFactorKeyGenerator();

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(1032))
            ->method('persist')
            ->with(self::callback(function (object $factor): bool {
                self::assertInstanceOf(EmissionFactor::class, $factor);
                $this->factors[] = $factor;

                return true;
            }));
        $manager->expects(self::once())->method('flush');

        (new TransportEmissionFactorFixtures($this->keyGenerator))->load($manager);
    }

    public function testFinalV20CatalogContract(): void
    {
        self::assertCount(1032, $this->factors);

        $functionalKeys = [];
        $exactKeys = [];
        $years = [];
        $zeroCount = 0;

        foreach ($this->factors as $factor) {
            self::assertSame('transport', $factor->getCategoryKey());
            self::assertSame(
                $this->keyGenerator->generate($factor->getCriteria()),
                $factor->getFunctionalKey(),
            );

            $functionalKeys[$factor->getFunctionalKey()] = true;
            $exactKey = implode('|', [
                $factor->getCategoryKey(),
                $factor->getFunctionalKey(),
                $factor->getYear(),
            ]);
            self::assertArrayNotHasKey($exactKey, $exactKeys);
            $exactKeys[$exactKey] = true;
            $years[$factor->getYear()] = ($years[$factor->getYear()] ?? 0) + 1;
            $zeroCount += '0' === $factor->getValue() ? 1 : 0;
        }

        self::assertCount(259, $functionalKeys);
        self::assertCount(1032, $exactKeys);
        self::assertSame([2022 => 256, 2023 => 258, 2024 => 259, 2025 => 259], $years);
        self::assertSame(9, $zeroCount);
    }

    public function testKeepsRawPrecisionAndCanonicalSourceValues(): void
    {
        $defra = $this->factor([
            'area' => 'FUERA DE ESPAÑA',
            'subcategory' => 'MERCANCÍAS',
            'activity' => 'Furgonetas y furgones Promedio (< 3,5 tn)',
            'fuel' => 'Eléctrica Híbrida Enchufable',
            'unit' => 'km',
            'method' => 'distancia',
        ], 2023);
        self::assertSame('0.13292', $defra->getValue());
        self::assertSame('DEFRA', $defra->getSource());

        $miteco = $this->factor([
            'area' => 'ESPAÑA',
            'subcategory' => 'MERCANCÍAS',
            'activity' => 'Vehículos pesados (> 3,5 tn ) (sin carga)',
            'fuel' => 'CNG',
            'unit' => 'km',
            'method' => 'distancia',
        ], 2025);
        self::assertSame('0.745', $miteco->getValue());
        self::assertSame('MITECO', $miteco->getSource());

        $occc = $this->factor([
            'area' => 'ESPAÑA',
            'subcategory' => 'MERCANCÍAS',
            'activity' => 'Tren',
            'fuel' => 'Híbrido (electricidad + gasoil)',
            'unit' => 'km*tonelada',
            'method' => 'distancia',
        ], 2024);
        self::assertSame('0.02004', $occc->getValue());
        self::assertSame('OCCC', $occc->getSource());

        $highPrecision = $this->factor([
            'area' => 'ESPAÑA',
            'subcategory' => 'VIAJES',
            'activity' => 'Autocar (larga distancia)',
            'fuel' => 'CNG',
            'unit' => 'km*pasajero',
            'method' => 'distancia',
        ], 2024);
        self::assertSame('0.03997155049786629', $highPrecision->getValue());
    }

    public function testKeepsZeroAsARealFactor(): void
    {
        $factor = $this->factor([
            'area' => 'FUERA DE ESPAÑA',
            'subcategory' => 'PRIVADO',
            'activity' => 'Coche pequeño (< 1.700 cc)',
            'fuel' => 'Eléctrico de batería',
            'unit' => 'km',
            'method' => 'distancia',
        ], 2025);

        self::assertSame('0', $factor->getValue());
        self::assertNotNull($factor->getValue());
    }

    /** @param array<string, string> $criteria */
    private function factor(array $criteria, int $year): EmissionFactor
    {
        $normalizedCriteria = $this->keyGenerator->normalize($criteria);
        $matches = array_values(array_filter(
            $this->factors,
            static fn (EmissionFactor $factor): bool => $factor->getYear() === $year
                && $factor->getCriteria() === $normalizedCriteria,
        ));

        self::assertCount(1, $matches);

        return $matches[0];
    }
}
