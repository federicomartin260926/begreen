<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\AccommodationEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class AccommodationEmissionFactorFixturesTest extends TestCase
{
    /** @var list<EmissionFactor> */
    private array $factors = [];

    protected function setUp(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(5426))->method('persist')->willReturnCallback(function (object $factor): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');

        (new AccommodationEmissionFactorFixtures(new EmissionFactorKeyGenerator()))->load($manager);
    }

    public function testLoadsLiteralHotelApplicabilityRowsAndApartmentWithUniqueFactorIds(): void
    {
        self::assertCount(5426, $this->factors);
        $hotel = array_values(array_filter($this->factors, static fn (EmissionFactor $factor): bool => 'hotel' === $factor->getCriteria()['accommodationType']));
        $apartments = array_values(array_filter($this->factors, static fn (EmissionFactor $factor): bool => 'apartment' === $factor->getCriteria()['accommodationType']));

        self::assertCount(5425, $hotel);
        self::assertCount(1, $apartments);
        self::assertSame([2022, 2023, 2024, 2025, 2026], array_values(array_unique(array_map(static fn (EmissionFactor $factor): int => $factor->getActivityYear(), $hotel))));
        self::assertSame([2022, 2023, 2024], array_values(array_unique(array_map(static fn (EmissionFactor $factor): int => $factor->getYear(), $hotel))));
        self::assertSame([2022 => 1085, 2023 => 1085, 2024 => 1085, 2025 => 1085, 2026 => 1085], array_count_values(array_map(static fn (EmissionFactor $factor): int => $factor->getActivityYear(), $hotel)));
        self::assertSame([2022 => 1085, 2023 => 1085, 2024 => 3255], array_count_values(array_map(static fn (EmissionFactor $factor): int => $factor->getYear(), $hotel)));
        self::assertCount(2170, array_filter($hotel, static fn (EmissionFactor $factor): bool => true === $factor->getMetadata()['isTemporalFallback']));
        self::assertCount(1865, array_filter($this->factors, static fn (EmissionFactor $factor): bool => true === $factor->getMetadata()['isGeographicProxy']));

        $keyGenerator = new EmissionFactorKeyGenerator();
        $factorIds = [];
        foreach ($this->factors as $factor) {
            self::assertSame('accommodation', $factor->getCategoryKey());
            self::assertSame($keyGenerator->generate($factor->getCriteria()), $factor->getFunctionalKey());
            self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $factor->getTemporalType());
            self::assertNotNull($factor->getFactorId());
            self::assertArrayNotHasKey($factor->getFactorId(), $factorIds);
            $factorIds[$factor->getFactorId()] = true;
        }
        self::assertCount(5426, $factorIds);

        $apartment = $apartments[0];
        self::assertSame('ALO_OTH_FDBEE6C6DB3289', $apartment->getFactorId());
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $apartment->getTemporalType());
        self::assertNull($apartment->getActivityYear());
        self::assertNull($apartment->getYear());
        self::assertSame('4.087', $apartment->getValue());
        self::assertSame('Land 2025 · factor contextual de apartamento turístico', $apartment->getMetadata()['factorVersion']);
        self::assertTrue($apartment->getMetadata()['isGeographicProxy']);
    }

    public function testHotelMetadataPreservesSourceTraceabilityAndFunctionalIdentityExcludesYear(): void
    {
        $spain2024 = $this->hotel('ESP', '4', 2024);
        $spain2023 = $this->hotel('ESP', '4', 2023);
        $spain2025 = $this->hotel('ESP', '4', 2025);

        self::assertSame($spain2023->getFunctionalKey(), $spain2024->getFunctionalKey());
        self::assertSame($spain2024->getFunctionalKey(), $spain2025->getFunctionalKey());
        self::assertSame([
            'accommodationType' => 'hotel',
            'iso3' => 'ESP',
            'stars' => '4',
            'unit' => 'occupied room-night',
        ], $spain2024->getCriteria());
        self::assertSame('España', $spain2024->getMetadata()['country']);
        self::assertSame('ALO_HOT_DC051071C99507', $spain2024->getFactorId());
        self::assertSame('CHSB 2026', $spain2024->getMetadata()['factorVersion']);
        self::assertSame('HFT_PUBLISHED_COEFFICIENT', $spain2024->getSourceDetail());
        self::assertFalse($spain2024->getMetadata()['isGeographicProxy']);
        self::assertSame('https://greenview.sg/resources/hotel-footprinting-tool/', $spain2024->getMetadata()['sourceUrl']);
        self::assertSame(2025, $spain2025->getActivityYear());
        self::assertSame(2024, $spain2025->getYear());
        self::assertTrue($spain2025->getMetadata()['isTemporalFallback']);
    }

    private function hotel(string $iso3, string $stars, int $activityYear): EmissionFactor
    {
        foreach ($this->factors as $factor) {
            $criteria = $factor->getCriteria();
            if (($criteria['iso3'] ?? null) === $iso3 && ($criteria['stars'] ?? null) === $stars && $factor->getActivityYear() === $activityYear) {
                return $factor;
            }
        }

        self::fail('Expected hotel factor was not loaded.');
    }
}
