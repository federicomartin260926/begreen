<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Catering;

use App\DataFixtures\CateringEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\Catering\CateringFactorResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class CateringFactorResolverTest extends TestCase
{
    public function testResolvesFoodAndSupportedTablewareWithoutInventingFactorYear(): void
    {
        $resolver = $this->resolver();
        $food = $resolver->resolveFood('vegan', 2022);
        $compostable = $resolver->resolveTableware('compostable', 2026);
        $reusable = $resolver->resolveTableware('reusable', 2024);

        self::assertSame('0.519728395', $food->factorValue);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_VERSIONED, $food->temporalType);
        self::assertNull($food->activityYear);
        self::assertNull($food->factorYear);
        self::assertSame('MENU_VEGAN', $food->factorId);
        self::assertSame('0.11403', $compostable->factorValue);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_COMPOSITE, $compostable->temporalType);
        self::assertNull($compostable->factorYear);
        self::assertSame('0.0218', $reusable->factorValue);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_PROXY_LCA, $reusable->temporalType);
        self::assertNull($reusable->factorYear);
    }

    public function testUnknownTablewareHasNoFactorResolution(): void
    {
        self::assertNull($this->resolver()->resolveTableware('unknown', 2025));
    }

    private function resolver(): CateringFactorResolver
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $factors[] = $factor;
        });
        (new CateringEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findMethodological')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, string $temporalType) use (&$factors): ?EmissionFactor {
                foreach ($factors as $factor) {
                    if ('catering' === $categoryKey && $functionalKey === $factor->getFunctionalKey() && $temporalType === $factor->getTemporalType()) {
                        return $factor;
                    }
                }

                return null;
            },
        );

        return new CateringFactorResolver(new EmissionFactorResolver($repository, $keyGenerator));
    }
}
