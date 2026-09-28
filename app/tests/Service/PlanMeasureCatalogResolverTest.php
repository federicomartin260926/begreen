<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Service\PlanMeasureCatalogResolver;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\CommercialPlanTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final class PlanMeasureCatalogResolverTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testFilmAndEventCatalogProtocolsUseV23ImportVersion(): void
    {
        $resolver = $this->createResolver();
        $filmProtocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE);
        $eventProtocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_EVENT_CODE);

        self::assertSame(
            PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION,
            $resolver->getImportVersionForProtocol($filmProtocol)
        );
        self::assertSame(
            PlanMeasureCatalogResolver::BE_GREEN_MY_EVENT_IMPORT_VERSION,
            $resolver->getImportVersionForProtocol($eventProtocol)
        );

        $project = $this->createProjectWithTier(ProjectSubscription::TIER_BASIC);
        foreach ([$filmProtocol, $eventProtocol] as $protocol) {
            $measure = (new Measure())
                ->setProtocol($protocol)
                ->setImportVersion(PlanMeasureCatalogResolver::CATALOG_IMPORT_VERSION)
                ->setScore(5);
            self::assertTrue($resolver->isCatalogMeasure($measure, $project));
        }
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

    public function testQueryFilterUsesOnlySelectorIdsForAnimationAndKeepsV23ForFilm(): void
    {
        $resolver = $this->createResolver();
        $animationProject = $this->createProjectWithTier(ProjectSubscription::TIER_BASIC)
            ->setType(Protocol::TYPE_RODAJE)
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION);
        $animationQb = $this->queryBuilder();

        $resolver->applyCatalogFilter($animationQb, 'm', 'p', $animationProject, [101]);

        self::assertStringContainsString('m.id IN (:animationEligibleMeasureIds)', $animationQb->getDQL());
        self::assertSame([101], $animationQb->getParameter('animationEligibleMeasureIds')?->getValue());
        self::assertStringNotContainsString('m.score', $animationQb->getDQL());

        $emptyQb = $this->queryBuilder();
        $resolver->applyCatalogFilter($emptyQb, 'm', 'p', $animationProject, []);
        self::assertStringContainsString('1 = 0', $emptyQb->getDQL());

        $filmQb = $this->queryBuilder();
        $filmProject = $this->createProjectWithTier(ProjectSubscription::TIER_BASIC)
            ->setType(Protocol::TYPE_RODAJE)
            ->setFilmingGenre('ficcion');
        $resolver->applyCatalogFilter($filmQb, 'm', 'p', $filmProject);
        self::assertStringContainsString('m.importVersion = :catalogImportVersion', $filmQb->getDQL());
        self::assertStringContainsString('m.score IN (:catalogAllowedScores)', $filmQb->getDQL());
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
            ->setPhase(CommercialPhase::ELABORATION)
            ->setTier($tier)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);

        $project->addSubscription($subscription);

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

    private function queryBuilder(): QueryBuilder
    {
        return (new QueryBuilder($this->createMock(EntityManagerInterface::class)))
            ->select('m')
            ->from(Measure::class, 'm')
            ->leftJoin('m.protocol', 'p');
    }
}
