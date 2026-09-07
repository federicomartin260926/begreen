<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Water;

use App\DataFixtures\WaterEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterFactorResolution;
use App\Service\Emission\Water\WaterFactorResolver;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class WaterFactorResolverTest extends TestCase
{
    public function testUnitedKingdomSewerUsesSupplyAndTreatmentWithoutProxy(): void
    {
        $result = $this->resolver()->resolve('GB', 2024, WaterEmissionInput::DESTINATION_SEWER);

        self::assertCount(2, $result);
        self::assertSame(['0.15311', '0.18574'], array_column($result, 'factorValue'));
        self::assertSame(['water_supply', 'water_treatment'], array_column($result, 'factorType'));
        foreach ($result as $component) {
            self::assertFalse($component->isGeographicProxy);
            self::assertSame(WaterFactorResolution::QUALITY_HIGH, $component->dataQuality);
            self::assertSame('Reino Unido', $component->sourceGeography);
            self::assertSame('Reino Unido/GB', $component->targetGeography);
        }
    }

    public function testUnitedKingdomIrrigationUsesSupplyOnly(): void
    {
        $result = $this->resolver()->resolve('GB', 2025, WaterEmissionInput::DESTINATION_IRRIGATION);

        self::assertCount(1, $result);
        self::assertSame('water_supply', $result[0]->factorType);
        self::assertSame('0.1913', $result[0]->factorValue);
    }

    public function testSpain2023UsesUnitedKingdomBoundaryAsLowQualityProxy(): void
    {
        $result = $this->resolver()->resolve('ES', 2023, WaterEmissionInput::DESTINATION_SEWER);

        self::assertSame(['0.1767', '0.2013'], array_column($result, 'factorValue'));
        foreach ($result as $component) {
            self::assertTrue($component->isGeographicProxy);
            self::assertSame(WaterFactorResolution::QUALITY_LOW, $component->dataQuality);
            self::assertSame('Reino Unido', $component->sourceGeography);
            self::assertSame('España/ES', $component->targetGeography);
        }
    }

    public function testSpain2024UsesOcccUrbanCycleAtMediumQuality(): void
    {
        $result = $this->resolver()->resolve('ES', 2024, WaterEmissionInput::DESTINATION_UNKNOWN);

        self::assertCount(1, $result);
        self::assertSame('urban_water_cycle', $result[0]->factorType);
        self::assertSame('0.517', $result[0]->factorValue);
        self::assertSame(2024, $result[0]->factorYear);
        self::assertSame('Cataluña', $result[0]->sourceGeography);
        self::assertTrue($result[0]->isGeographicProxy);
        self::assertFalse($result[0]->isFallback);
        self::assertSame(WaterFactorResolution::QUALITY_MEDIUM, $result[0]->dataQuality);
    }

    public function testSpain2026FallsBackToOccc2025AtLowQuality(): void
    {
        $result = $this->resolver()->resolve('ES', 2026, WaterEmissionInput::DESTINATION_SEWER);

        self::assertCount(1, $result);
        self::assertSame('0.517', $result[0]->factorValue);
        self::assertSame(2025, $result[0]->factorYear);
        self::assertTrue($result[0]->isFallback);
        self::assertSame('exact_year_missing', $result[0]->fallbackReason);
        self::assertTrue($result[0]->isGeographicProxy);
        self::assertSame(WaterFactorResolution::QUALITY_LOW, $result[0]->dataQuality);
    }

    public function testSpainIrrigationUsesUnitedKingdomSupplyProxy(): void
    {
        $result = $this->resolver()->resolve('ES', 2025, WaterEmissionInput::DESTINATION_IRRIGATION);

        self::assertCount(1, $result);
        self::assertSame('water_supply', $result[0]->factorType);
        self::assertSame('0.1913', $result[0]->factorValue);
        self::assertTrue($result[0]->isGeographicProxy);
        self::assertSame(WaterFactorResolution::QUALITY_LOW, $result[0]->dataQuality);
    }

    public function testAnotherCountryUsesUnitedKingdomSupplyAndTreatmentProxy(): void
    {
        $result = $this->resolver()->resolve('FR', 2024, WaterEmissionInput::DESTINATION_UNKNOWN);

        self::assertSame(['0.15311', '0.18574'], array_column($result, 'factorValue'));
        foreach ($result as $component) {
            self::assertTrue($component->isGeographicProxy);
            self::assertSame('FR', $component->targetGeography);
            self::assertSame(WaterFactorResolution::QUALITY_LOW, $component->dataQuality);
        }
    }

    public function testNeverUsesFutureFactorBeforeFirstAvailableYear(): void
    {
        $result = $this->resolver()->resolve('FR', 2021, WaterEmissionInput::DESTINATION_SEWER);

        self::assertCount(2, $result);
        foreach ($result as $component) {
            self::assertFalse($component->hasFactor());
            self::assertNull($component->factorYear);
            self::assertNull($component->factorValue);
        }
    }

    private function resolver(): WaterFactorResolver
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $factors[] = $factor;
        });
        (new WaterEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('water', $categoryKey);
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool => $factor->getFunctionalKey() === $functionalKey
                        && $factor->getYear() <= $activityYear,
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );

        return new WaterFactorResolver(new EmissionFactorResolver($repository, $keyGenerator));
    }
}
