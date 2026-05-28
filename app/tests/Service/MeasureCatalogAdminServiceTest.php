<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Department;
use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\MeasureVerificationSource;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use App\Service\MeasureCatalogAdminService;
use PHPUnit\Framework\TestCase;

final class MeasureCatalogAdminServiceTest extends TestCase
{
    public function testSummarizeCatalogMatchesExpectedV23Counts(): void
    {
        $service = new MeasureCatalogAdminService();
        $measures = [];

        $distribution = [
            5 => 28,
            4 => 22,
            3 => 50,
            2 => 87,
            1 => 13,
        ];

        foreach ($distribution as $score => $count) {
            for ($i = 0; $i < $count; $i++) {
                $measures[] = $this->createCanonicalMeasure($score);
            }
        }

        $summary = $service->summarizeCatalog($measures);

        self::assertSame(200, $summary['totalMeasures']);
        self::assertSame(565, $summary['totalPoints']);
        self::assertSame(28, $summary['scoreDistribution'][5]);
        self::assertSame(22, $summary['scoreDistribution'][4]);
        self::assertSame(50, $summary['scoreDistribution'][3]);
        self::assertSame(87, $summary['scoreDistribution'][2]);
        self::assertSame(13, $summary['scoreDistribution'][1]);
        self::assertSame(0, $summary['missingDepartments']);
        self::assertSame(0, $summary['missingOds']);
        self::assertSame(0, $summary['missingVerificationSources']);
        self::assertSame(0, $summary['missingImpactAreas']);
        self::assertSame(0, $summary['missingTripleBalanceAxes']);
        self::assertTrue($summary['isExpected']);
    }

    public function testSummarizeCatalogCountsNonCanonicalMeasuresToo(): void
    {
        $service = new MeasureCatalogAdminService();

        $measure = (new Measure())
            ->setProtocol((new Protocol())->setCode('peach')->setName('Peach'))
            ->setScore(3);

        $summary = $service->summarizeCatalog([$measure]);

        self::assertSame(1, $summary['totalMeasures']);
        self::assertSame(3, $summary['totalPoints']);
        self::assertSame(1, $summary['scoreDistribution'][3]);
        self::assertFalse($summary['isExpected']);
    }

    public function testSyncVerificationSourcesKeepsPriorityOrderAndLegacyString(): void
    {
        $service = new MeasureCatalogAdminService();
        $measure = $this->createCanonicalMeasure(5);

        $source1 = $this->createVerificationSource(11, 'foto', 'Foto');
        $source2 = $this->createVerificationSource(12, 'factura', 'Factura / Albarán');
        $source3 = $this->createVerificationSource(13, 'certificado', 'Certificado');

        $service->syncVerificationSources($measure, [
            1 => $source1,
            2 => $source2,
            3 => $source3,
        ]);

        $links = $measure->getResolvedVerificationSourceLinks();
        self::assertCount(3, $links);
        self::assertSame([1, 2, 3], array_map(static fn (MeasureVerificationSource $link): int => $link->getPriority(), $links));
        self::assertSame('1. Foto | 2. Factura / Albarán | 3. Certificado', $measure->getVerificationSources());
        self::assertSame('1. Foto | 2. Factura / Albarán | 3. Certificado', $measure->getVerificationSourcesSummary());
    }

    public function testSyncVerificationSourcesStripsNumericPrefixesFromLegacyNames(): void
    {
        $service = new MeasureCatalogAdminService();
        $measure = $this->createCanonicalMeasure(5);

        $source1 = $this->createVerificationSource(11, 'foto', '1. Foto');
        $source2 = $this->createVerificationSource(12, 'factura', '2. Factura / Albarán');

        $service->syncVerificationSources($measure, [
            1 => $source1,
            2 => $source2,
        ]);

        self::assertSame('1. Foto | 2. Factura / Albarán', $measure->getVerificationSources());
        self::assertSame('1. Foto | 2. Factura / Albarán', $measure->getVerificationSourcesSummary());
    }

    public function testSyncVerificationSourcesRejectsDuplicateSourceInDifferentPriorities(): void
    {
        $service = new MeasureCatalogAdminService();
        $measure = $this->createCanonicalMeasure(5);
        $source = $this->createVerificationSource(21, 'foto', 'Foto');

        $this->expectException(\InvalidArgumentException::class);

        $service->syncVerificationSources($measure, [
            1 => $source,
            2 => $source,
        ]);
    }

    public function testValidateV23MeasureReportsMissingRequiredFields(): void
    {
        $service = new MeasureCatalogAdminService();
        $measure = $this->createCanonicalMeasure(0, false);

        $errors = $service->validateV23Measure($measure, [
            1 => null,
            2 => null,
            3 => null,
        ]);

        $fields = array_column($errors, 'field');
        self::assertContains('score', $fields);
        self::assertContains('measureBlock', $fields);
        self::assertContains('departments', $fields);
        self::assertContains('odsItems', $fields);
        self::assertContains('impactAreas', $fields);
        self::assertContains('tripleBalanceAxes', $fields);
        self::assertContains('verificationSourcePriority1', $fields);
    }

    private function createCanonicalMeasure(int $score, bool $withTaxonomies = true): Measure
    {
        $measure = new Measure();

        $protocol = (new Protocol())
            ->setCode('be-green-my-film')
            ->setName('Be Green My Film');
        $this->setEntityId($protocol, 101);

        $measureBlock = (new MeasureBlock())
            ->setCode('block-1')
            ->setName('Bloque');
        $this->setEntityId($measureBlock, 201);

        $department = (new Department())
            ->setCode('prod')
            ->setName('Producción');
        $this->setEntityId($department, 301);

        $ods = (new Ods())
            ->setCode('12')
            ->setName('ODS 12');
        $this->setEntityId($ods, 401);

        $impactArea = (new ImpactArea())
            ->setCode('clima')
            ->setName('Cambio Climático');
        $this->setEntityId($impactArea, 501);

        $axis = (new TripleBalanceAxis())
            ->setCode('ambiental')
            ->setName('Ambiental');
        $this->setEntityId($axis, 601);

        $source = $this->createVerificationSource(701, 'factura', 'Factura / Albarán');

        $measure
            ->setProtocol($protocol)
            ->setImportVersion('v23')
            ->setScore($score)
            ->setCategory((new Category())->setName('Categoría'));

        if ($withTaxonomies) {
            $measure->setMeasureBlock($measureBlock);
            $measure->addDepartment($department);
            $measure->addOdsItem($ods);
            $measure->addImpactArea($impactArea);
            $measure->addTripleBalanceAxis($axis);

            $link = (new MeasureVerificationSource())
                ->setVerificationSource($source)
                ->setPriority(1);
            $measure->addVerificationSourceLink($link);
        }

        return $measure;
    }

    private function createVerificationSource(int $id, string $code, string $name): VerificationSource
    {
        $source = (new VerificationSource())
            ->setCode($code)
            ->setName($name)
            ->setSortOrder(1);
        $this->setEntityId($source, $id);

        return $source;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
