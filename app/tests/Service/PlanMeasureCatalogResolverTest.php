<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Protocol;
use App\Service\PlanMeasureCatalogResolver;
use PHPUnit\Framework\TestCase;

final class PlanMeasureCatalogResolverTest extends TestCase
{
    public function testCanonicalProtocolUsesV23ImportVersion(): void
    {
        $resolver = new PlanMeasureCatalogResolver();
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE);

        self::assertSame(
            PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION,
            $resolver->getImportVersionForProtocol($protocol)
        );
    }

    public function testCatalogMeasureDetectionSkipsLegacyBeGreenMyFilmRows(): void
    {
        $resolver = new PlanMeasureCatalogResolver();

        $canonicalProtocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE);

        $legacyMeasure = (new Measure())
            ->setProtocol($canonicalProtocol)
            ->setImportVersion(null);

        $v23Measure = (new Measure())
            ->setProtocol($canonicalProtocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION);

        $otherProtocol = (new Protocol())
            ->setCode(null);

        $otherMeasure = (new Measure())
            ->setProtocol($otherProtocol)
            ->setImportVersion(null);

        self::assertFalse($resolver->isCatalogMeasure($legacyMeasure));
        self::assertTrue($resolver->isCatalogMeasure($v23Measure));
        self::assertTrue($resolver->isCatalogMeasure($otherMeasure));
    }
}
