<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\CateringEmissionFactorFixtures;
use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class CateringEmissionFactorFixturesTest extends TestCase
{
    public function testLoadsNineCanonicalMethodologicalFactorsWithTraceability(): void
    {
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(9))->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            self::assertInstanceOf(EmissionFactor::class, $factor);
            $factors[] = $factor;
        });
        $manager->expects(self::once())->method('flush');

        (new CateringEmissionFactorFixtures(new EmissionFactorKeyGenerator()))->load($manager);

        self::assertCount(9, $factors);
        self::assertCount(7, array_filter($factors, static fn (EmissionFactor $factor): bool => EmissionFactor::TEMPORAL_TYPE_VERSIONED === $factor->getTemporalType()));
        self::assertCount(1, array_filter($factors, static fn (EmissionFactor $factor): bool => EmissionFactor::TEMPORAL_TYPE_COMPOSITE === $factor->getTemporalType()));
        self::assertCount(1, array_filter($factors, static fn (EmissionFactor $factor): bool => EmissionFactor::TEMPORAL_TYPE_PROXY_LCA === $factor->getTemporalType()));

        $keyGenerator = new EmissionFactorKeyGenerator();
        $factorIds = [];
        foreach ($factors as $factor) {
            self::assertSame('catering', $factor->getCategoryKey());
            self::assertNull($factor->getActivityYear());
            self::assertNull($factor->getYear());
            self::assertSame($keyGenerator->generate($factor->getCriteria()), $factor->getFunctionalKey());
            self::assertNotNull($factor->getFactorId());
            self::assertArrayNotHasKey($factor->getFactorId(), $factorIds);
            $factorIds[$factor->getFactorId()] = true;
            self::assertNotEmpty($factor->getMetadata()['factorVersion']);
            self::assertNotEmpty($factor->getMetadata()['sourceUrl']);
            self::assertSame('Catering_Base_Maestra_y_Contrato_v11_4.xlsx', $factor->getMetadata()['sourceWorkbook']);
            self::assertSame('Factores_Catering', $factor->getMetadata()['sourceSheet']);
        }
        self::assertCount(9, $factorIds);

        $byId = [];
        foreach ($factors as $factor) {
            $byId[$factor->getFactorId()] = $factor;
        }
        self::assertSame('0.519728395', $byId['MENU_VEGAN']->getValue());
        self::assertSame('kgCO2e/menú preparado', $byId['MENU_VEGAN']->getUnit());
        self::assertSame('ADEME AGRIBALYSE', $byId['MENU_VEGAN']->getSource());
        self::assertSame('AGRIBALYSE 3.2', $byId['MENU_VEGAN']->getMetadata()['factorVersion']);
        self::assertSame('0.11403', $byId['TABLEWARE_COMPOSTABLE_MENU_PACK']->getValue());
        self::assertSame('BGMF COMPOSTABLE MENU PACK v1', $byId['TABLEWARE_COMPOSTABLE_MENU_PACK']->getMetadata()['factorVersion']);
        self::assertSame('0.0218', $byId['TABLEWARE_REUSABLE_MENU_SERVICE']->getValue());
        self::assertSame('kgCO2e/menú consumido', $byId['TABLEWARE_REUSABLE_MENU_SERVICE']->getUnit());
        self::assertSame('Reusable catering service LCA proxy v1', $byId['TABLEWARE_REUSABLE_MENU_SERVICE']->getMetadata()['factorVersion']);
    }
}
