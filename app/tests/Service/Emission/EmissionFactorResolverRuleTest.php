<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission;

use App\Entity\EmissionFactor;
use App\Repository\EmissionFactorRepository;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use PHPUnit\Framework\TestCase;

final class EmissionFactorResolverRuleTest extends TestCase
{
    public function testResolvesRuleAsMethodologicalFactorWithoutFactorYear(): void
    {
        $criteria = [
            'regionScope' => 'spain',
            'wasteType' => 'Construcción / Set promedio',
            'wasteActivity' => 'Construcción / Set promedio',
            'treatment' => 'Reutilización / Donación',
            'unit' => 'kg',
            'ruleType' => 'NON_WASTE_ROUTE_ZERO',
        ];
        $keyGenerator = new EmissionFactorKeyGenerator();
        $functionalKey = $keyGenerator->generate($criteria);
        $factor = (new EmissionFactor())
            ->setCategoryKey('waste')
            ->setFunctionalKey($functionalKey)
            ->setCriteria($criteria)
            ->setYear(null)
            ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_RULE)
            ->setValue('0')
            ->setUnit('kgCO2e/kg')
            ->setSource('BGMF Residuos specification');

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository
            ->expects(self::once())
            ->method('findMethodological')
            ->with('waste', $functionalKey, EmissionFactor::TEMPORAL_TYPE_RULE)
            ->willReturn($factor);

        $resolution = (new EmissionFactorResolver($repository, $keyGenerator))->resolveMethodological(
            'waste',
            $criteria,
            2026,
            EmissionFactor::TEMPORAL_TYPE_RULE,
        );

        self::assertTrue($resolution->hasFactor());
        self::assertTrue($resolution->isCalculable());
        self::assertSame(2026, $resolution->activityYear);
        self::assertNull($resolution->factorYear);
        self::assertFalse($resolution->isFallback);
        self::assertNull($resolution->fallbackReason);
        self::assertSame(EmissionFactor::TEMPORAL_TYPE_RULE, $resolution->temporalType);
        self::assertSame('0', $resolution->factor?->getValue());
    }
}
