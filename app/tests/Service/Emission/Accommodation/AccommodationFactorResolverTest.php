<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Accommodation;

use App\DataFixtures\AccommodationEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Accommodation\AccommodationFactorResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class AccommodationFactorResolverTest extends TestCase
{
    public function testHotelResolutionUsesCountryStarsAndOnlyExactOrPriorYears(): void
    {
        $resolver = $this->resolver();
        $exact = $resolver->resolveHotel('ESP', '4', 2024);
        $fallback2025 = $resolver->resolveHotel('ESP', '4', 2025);
        $fallback2026 = $resolver->resolveHotel('ESP', '4', 2026);
        $differentStars = $resolver->resolveHotel('ESP', '3', 2024);
        $differentCountry = $resolver->resolveHotel('FRA', '4', 2024);
        $beforeFirstSourceYear = $resolver->resolveHotel('ESP', '4', 2021);

        self::assertSame('9.5505', $exact->factorValue);
        self::assertSame(2024, $exact->factorYear);
        self::assertFalse($exact->isFallback);
        foreach ([$fallback2025, $fallback2026] as $fallback) {
            self::assertSame('9.5505', $fallback->factorValue);
            self::assertSame(2024, $fallback->factorYear);
            self::assertTrue($fallback->isFallback);
            self::assertSame('exact_year_missing', $fallback->fallbackReason);
        }
        self::assertSame('9.3611', $differentStars->factorValue);
        self::assertNotSame($exact->criteria, $differentStars->criteria);
        self::assertNotSame($exact->criteria, $differentCountry->criteria);
        self::assertFalse($beforeFirstSourceYear->hasFactor());
        self::assertNull($beforeFirstSourceYear->factorYear);
    }

    public function testApartmentResolutionIsVersionedAcrossItsWholeScopeWithNullableFactorYear(): void
    {
        $resolver = $this->resolver();

        foreach ([2022, 2026] as $activityYear) {
            $result = $resolver->resolveApartment($activityYear);

            self::assertSame($activityYear, $result->activityYear);
            self::assertSame('4.087', $result->factorValue);
            self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $result->temporalType);
            self::assertNull($result->factorYear);
            self::assertTrue($result->isGeographicProxy);
            self::assertSame('Land 2025 · factor contextual de apartamento turístico', $result->metadata['factorVersion']);
        }
    }

    private function resolver(): AccommodationFactorResolver
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $factors[] = $factor;
        });
        (new AccommodationEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('accommodation', $categoryKey);
                $candidates = array_filter($factors, static fn (EmissionFactor $factor): bool =>
                    EmissionFactor::TEMPORAL_TYPE_ANNUAL === $factor->getTemporalType()
                    && $factor->getFunctionalKey() === $functionalKey
                    && $factor->getYear() <= $activityYear
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );
        $repository->method('findVersioned')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey) use (&$factors): ?EmissionFactor {
                self::assertSame('accommodation', $categoryKey);
                foreach ($factors as $factor) {
                    if (EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType()
                        && $factor->getFunctionalKey() === $functionalKey
                    ) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new AccommodationFactorResolver(new EmissionFactorResolver($repository, $keyGenerator));
    }
}
