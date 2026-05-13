<?php

namespace App\Tests\Service;

use App\Entity\Department;
use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\MeasureVerificationSource;
use App\Entity\Ods;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use App\Service\MeasureTaxonomyPresenter;
use PHPUnit\Framework\TestCase;

final class MeasureTaxonomyPresenterTest extends TestCase
{
    public function testPresenterFallsBackToLegacyFieldsWhenMultirelationsAreEmpty(): void
    {
        $presenter = new MeasureTaxonomyPresenter();
        $measure = new Measure();

        $department = (new Department())
            ->setCode('prod')
            ->setName('Producción');
        $ods = (new Ods())
            ->setCode('12')
            ->setName('Producción y consumo responsables');

        $this->setEntityId($department, 11);
        $this->setEntityId($ods, 12);

        $measure->setDepartment($department);
        $measure->setOds($ods);
        $measure->setVerificationSources('Factura / Albarán');

        self::assertCount(1, $presenter->departments($measure));
        self::assertSame('Producción', $presenter->departments($measure)[0]['displayName']);
        self::assertCount(1, $presenter->odsItems($measure));
        self::assertSame('12', $presenter->odsItems($measure)[0]['label']);
        self::assertSame([], $presenter->verificationSourcesWithPriority($measure));
        self::assertTrue($presenter->matchesDepartment($measure, 11));
        self::assertTrue($presenter->matchesOds($measure, 12));
        self::assertFalse($presenter->matchesImpactArea($measure, 99));
    }

    public function testPresenterReturnsMultipleTaxonomiesAndOrderedVerificationSources(): void
    {
        $presenter = new MeasureTaxonomyPresenter();
        $measure = new Measure();

        $departmentA = (new Department())->setCode('prod')->setName('Producción');
        $departmentB = (new Department())->setCode('arte')->setName('Arte');
        $odsA = (new Ods())->setCode('12')->setName('Producción y consumo responsables');
        $odsB = (new Ods())->setCode('13')->setName('Acción por el clima');
        $impactArea = (new ImpactArea())->setCode('clima')->setName('Cambio Climático');
        $axis = (new TripleBalanceAxis())->setCode('ambiental')->setName('Ambiental');
        $sourceA = (new VerificationSource())->setCode('foto')->setName('Foto');
        $sourceB = (new VerificationSource())->setCode('factura')->setName('Factura / Albarán');

        $this->setEntityId($departmentA, 21);
        $this->setEntityId($departmentB, 22);
        $this->setEntityId($odsA, 31);
        $this->setEntityId($odsB, 32);
        $this->setEntityId($impactArea, 41);
        $this->setEntityId($axis, 51);
        $this->setEntityId($sourceA, 61);
        $this->setEntityId($sourceB, 62);

        $measure->addDepartment($departmentA);
        $measure->addDepartment($departmentB);
        $measure->addOdsItem($odsA);
        $measure->addOdsItem($odsB);
        $measure->addImpactArea($impactArea);
        $measure->addTripleBalanceAxis($axis);
        $measure->setVerificationSources('Legacy');

        $linkLate = (new MeasureVerificationSource())
            ->setVerificationSource($sourceA)
            ->setPriority(2);
        $linkEarly = (new MeasureVerificationSource())
            ->setVerificationSource($sourceB)
            ->setPriority(1);
        $measure->addVerificationSourceLink($linkLate);
        $measure->addVerificationSourceLink($linkEarly);

        $departments = $presenter->departments($measure);
        $odsItems = $presenter->odsItems($measure);
        $sources = $presenter->verificationSourcesWithPriority($measure);
        $impactAreas = $presenter->impactAreas($measure);
        $axes = $presenter->tripleBalanceAxes($measure);

        self::assertCount(2, $departments);
        self::assertSame('Producción', $departments[0]['displayName']);
        self::assertSame('Arte', $departments[1]['displayName']);
        self::assertCount(2, $odsItems);
        self::assertSame('12', $odsItems[0]['label']);
        self::assertSame('13', $odsItems[1]['label']);
        self::assertSame([1, 2], array_map(static fn (array $link): int => $link['priority'], $sources));
        self::assertSame('Factura / Albarán', $sources[0]['name']);
        self::assertSame('Foto', $sources[1]['name']);
        self::assertSame('Cambio Climático', $impactAreas[0]['name']);
        self::assertSame('Ambiental', $axes[0]['name']);
        self::assertTrue($presenter->matchesDepartment($measure, 21));
        self::assertTrue($presenter->matchesOds($measure, 32));
        self::assertTrue($presenter->matchesImpactArea($measure, 41));
        self::assertTrue($presenter->matchesTripleBalanceAxis($measure, 51));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
