<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Service\PlanMeasureCatalogResolver;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\CommercialPlanTestHelpers;

final class PlanMeasureCatalogResolverTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testCanonicalProtocolUsesV23ImportVersion(): void
    {
        $resolver = $this->createResolver();
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE);

        self::assertSame(
            PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION,
            $resolver->getImportVersionForProtocol($protocol)
        );
    }

    public function testCatalogMeasureDetectionSkipsLegacyBeGreenMyFilmRows(): void
    {
        $resolver = $this->createResolver();

        $basicProject = $this->createProjectWithTier(ProjectSubscription::TIER_BASIC);
        $standardProject = $this->createProjectWithTier(ProjectSubscription::TIER_STANDARD);
        $proProject = $this->createProjectWithTier(ProjectSubscription::TIER_PRO);

        $legacyMeasure = (new Measure())
            ->setProtocol((new Protocol())->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE))
            ->setImportVersion(null);

        $v23Measure = (new Measure())
            ->setProtocol((new Protocol())->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE))
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);

        $otherProtocol = (new Protocol())
            ->setCode(null);

        $otherMeasure = (new Measure())
            ->setProtocol($otherProtocol)
            ->setImportVersion(null);

        self::assertFalse($resolver->isCatalogMeasure($legacyMeasure, $basicProject));
        self::assertTrue($resolver->isCatalogMeasure($v23Measure, $basicProject));
        self::assertTrue($resolver->isCatalogMeasure($otherMeasure, $basicProject));

        self::assertSame(50, $this->countVisibleMeasures($resolver, $basicProject));
        self::assertSame(100, $this->countVisibleMeasures($resolver, $standardProject));
        self::assertSame(200, $this->countVisibleMeasures($resolver, $proProject));
    }

    private function createResolver(): PlanMeasureCatalogResolver
    {
        $featureGate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());

        return new PlanMeasureCatalogResolver($featureGate);
    }

    private function createProjectWithTier(string $tier): Project
    {
        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setTier($tier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);

        $project->setSubscription($subscription);

        return $project;
    }

    private function countVisibleMeasures(PlanMeasureCatalogResolver $resolver, Project $project): int
    {
        $scores = array_merge(
            array_fill(0, 28, 5),
            array_fill(0, 22, 4),
            array_fill(0, 50, 3),
            array_fill(0, 87, 2),
            array_fill(0, 13, 1),
        );

        $count = 0;
        foreach ($scores as $score) {
            $measure = (new Measure())
                ->setProtocol((new Protocol())->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE))
                ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
                ->setScore($score);

            if ($resolver->isCatalogMeasure($measure, $project)) {
                $count++;
            }
        }

        return $count;
    }
}
