<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\TransportEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolution;
use App\Service\Emission\EmissionFactorResolver;
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
        $manager->expects(self::exactly(1193))
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
        self::assertCount(1193, $this->factors);

        $functionalKeys = [];
        $exactKeys = [];
        $factorIds = [];
        $years = [];
        $geographicProxyCount = 0;
        $zeroCount = 0;

        foreach ($this->factors as $factor) {
            self::assertSame('transport', $factor->getCategoryKey());
            self::assertSame(
                $this->keyGenerator->generate($factor->getCriteria()),
                $factor->getFunctionalKey(),
            );
            self::assertNotNull($factor->getFactorId());
            self::assertArrayNotHasKey($factor->getFactorId(), $factorIds);
            $factorIds[$factor->getFactorId()] = true;
            self::assertSame($factor->getYear(), $factor->getActivityYear());
            self::assertSame(EmissionFactor::TEMPORAL_TYPE_ANNUAL, $factor->getTemporalType());
            self::assertSame('Transporte_Base_Maestra_y_Contrato_V2_CORREGIDA.xlsx', $factor->getMetadata()['sourceWorkbook']);
            self::assertSame('Factores', $factor->getMetadata()['sourceSheet']);

            $functionalKeys[$factor->getFunctionalKey()] = true;
            $exactKey = implode('|', [
                $factor->getCategoryKey(),
                $factor->getFunctionalKey(),
                $factor->getYear(),
            ]);
            self::assertArrayNotHasKey($exactKey, $exactKeys);
            $exactKeys[$exactKey] = true;
            $years[$factor->getYear()] = ($years[$factor->getYear()] ?? 0) + 1;
            $geographicProxyCount += true === $factor->getMetadata()['isGeographicProxy'] ? 1 : 0;
            $zeroCount += '0' === $factor->getValue() ? 1 : 0;
        }

        self::assertCount(259, $functionalKeys);
        self::assertCount(1193, $exactKeys);
        self::assertCount(1193, $factorIds);
        self::assertSame([2022 => 256, 2023 => 258, 2024 => 259, 2025 => 259, 2026 => 161], $years);
        self::assertSame(676, $geographicProxyCount);
        self::assertSame(15, $zeroCount);
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

    public function testDefra2026CombinationResolvesExactFactor(): void
    {
        $criteria = [
            'area' => 'FUERA DE ESPAÑA',
            'subcategory' => 'PÚBLICO',
            'activity' => 'Taxi regular',
            'fuel' => 'Desconocido',
            'unit' => 'km',
            'method' => 'distancia',
        ];
        $resolution = $this->resolve($criteria, 2026, $this->factor($criteria, 2026));

        self::assertSame(2026, $resolution->activityYear);
        self::assertSame(2026, $resolution->factorYear);
        self::assertFalse($resolution->isFallback);
        self::assertNull($resolution->fallbackReason);
        self::assertSame('0.20806', $resolution->factor?->getValue());
        self::assertSame('DEFRA', $resolution->factor?->getSource());
        self::assertSame(
            'DEFRA - "Business Travel - Land" "Taxis" "Regular Taxi" | 2026 exacto: columna final DEFRA de la referencia, corregida por deriva de cabecera; contrastada con publicación DESNZ 2026.',
            $resolution->factor?->getSourceDetail(),
        );
    }

    public function testMitecoCombinationWithout2026FallsBackTo2025(): void
    {
        $criteria = [
            'area' => 'ESPAÑA',
            'subcategory' => 'MERCANCÍAS',
            'activity' => 'Vehículos pesados (> 3,5 tn ) (sin carga)',
            'fuel' => 'CNG',
            'unit' => 'km',
            'method' => 'distancia',
        ];
        $resolution = $this->resolve($criteria, 2026, $this->factor($criteria, 2025));

        self::assertSame(2025, $resolution->activityYear);
        self::assertSame(2025, $resolution->factorYear);
        self::assertTrue($resolution->isFallback);
        self::assertSame(EmissionFactorResolution::FALLBACK_REASON_EXACT_YEAR_MISSING, $resolution->fallbackReason);
        self::assertSame('MITECO', $resolution->factor?->getSource());
    }

    /** @param array<string, string> $criteria */
    private function resolve(array $criteria, int $activityYear, EmissionFactor $factor): EmissionFactorResolution
    {
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->expects(self::once())
            ->method('findForActivityYear')
            ->with('transport', $this->keyGenerator->generate($criteria), $activityYear)
            ->willReturn($factor);

        return (new EmissionFactorResolver($repository, $this->keyGenerator))
            ->resolve('transport', $criteria, $activityYear);
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
