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
        $manager->expects(self::exactly(3256))->method('persist')->willReturnCallback(function (object $factor): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $this->factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');

        (new AccommodationEmissionFactorFixtures(new EmissionFactorKeyGenerator()))->load($manager);
    }

    public function testLoadsOnlyCanonicalHotelAndApartmentFactorsWithoutDuplicateTemporalIdentities(): void
    {
        self::assertCount(3256, $this->factors);
        $hotel = array_values(array_filter($this->factors, static fn (EmissionFactor $factor): bool => 'hotel' === $factor->getCriteria()['accommodationType']));
        $apartments = array_values(array_filter($this->factors, static fn (EmissionFactor $factor): bool => 'apartment' === $factor->getCriteria()['accommodationType']));

        self::assertCount(3255, $hotel);
        self::assertCount(1, $apartments);
        self::assertCount(0, array_filter($hotel, static fn (EmissionFactor $factor): bool => in_array($factor->getYear(), [2025, 2026], true)));
        self::assertSame([2022, 2023, 2024], array_values(array_unique(array_map(static fn (EmissionFactor $factor): int => $factor->getYear(), $hotel))));

        $keyGenerator = new EmissionFactorKeyGenerator();
        $identities = [];
        foreach ($this->factors as $factor) {
            self::assertSame('accommodation', $factor->getCategoryKey());
            self::assertSame($keyGenerator->generate($factor->getCriteria()), $factor->getFunctionalKey());
            $identity = $factor->getFunctionalKey().'|'.$factor->getTemporalType().'|'.$factor->getYear();
            self::assertArrayNotHasKey($identity, $identities);
            $identities[$identity] = true;
        }
        self::assertCount(3256, $identities);

        $apartment = $apartments[0];
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $apartment->getTemporalType());
        self::assertNull($apartment->getYear());
        self::assertSame('4.087', $apartment->getValue());
        self::assertSame('Land 2025 · factor contextual de apartamento turístico', $apartment->getMetadata()['factorVersion']);
        self::assertTrue($apartment->getMetadata()['isGeographicProxy']);
    }

    public function testHotelMetadataPreservesSourceTraceabilityAndFunctionalIdentityExcludesYear(): void
    {
        $spain2024 = $this->hotel('ESP', '4', 2024);
        $spain2023 = $this->hotel('ESP', '4', 2023);

        self::assertSame($spain2023->getFunctionalKey(), $spain2024->getFunctionalKey());
        self::assertSame([
            'accommodationType' => 'hotel',
            'iso3' => 'ESP',
            'stars' => '4',
            'unit' => 'occupied room-night',
        ], $spain2024->getCriteria());
        self::assertSame('España', $spain2024->getMetadata()['country']);
        self::assertSame('CHSB 2026', $spain2024->getMetadata()['dataset']);
        self::assertSame(2024, $spain2024->getMetadata()['datasetCalendarYear']);
        self::assertSame('Greenview HFT 2026v1.1', $spain2024->getMetadata()['toolVersion']);
        self::assertSame('HFT_PUBLISHED_COEFFICIENT', $spain2024->getMetadata()['method']);
        self::assertNull($spain2024->getMetadata()['sampleCount']);
        self::assertFalse($spain2024->getMetadata()['sourceTemporalFallback']);
        self::assertFalse($spain2024->getMetadata()['isGeographicProxy']);
        self::assertSame('https://greenview.sg/resources/hotel-footprinting-tool/', $spain2024->getMetadata()['sourceUrl']);
    }

    private function hotel(string $iso3, string $stars, int $year): EmissionFactor
    {
        foreach ($this->factors as $factor) {
            $criteria = $factor->getCriteria();
            if (($criteria['iso3'] ?? null) === $iso3 && ($criteria['stars'] ?? null) === $stars && $factor->getYear() === $year) {
                return $factor;
            }
        }

        self::fail('Expected hotel factor was not loaded.');
    }
}
