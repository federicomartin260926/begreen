<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Repository\MeasureRepository;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\SustainabilityCommitmentLevelService;
use App\Service\SustainabilityPlanClosureSummaryService;
use App\Service\SustainabilityPlanCustomMeasureParser;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanClosureSummaryServiceTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testBuildsClosureCountsWithBlockSkipAndCustomMeasuresSeparated(): void
    {
        $project = $this->makeProjectWithTier(ProjectSubscription::TIER_PRO);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film');
        $this->setEntityId($protocol, 10);

        $selected = $this->createMeasure(101, 'Seleccionada', 5, $protocol);
        $discarded = $this->createMeasure(102, 'Descartada', 4, $protocol);
        $blockSkipped = $this->createMeasure(103, 'No aplica por bloque', 3, $protocol);

        $plan = (new Plan())
            ->setProject($project)
            ->setProtocol($protocol)
            ->setCustomMeasures("Medida propia 1 | Descripción\nMedida propia 2");
        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($selected)
                ->setIsApplicable(true)
                ->setWillImplement(true)
                ->setIsCritical(true)
        );
        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($discarded)
                ->setIsApplicable(true)
                ->setWillImplement(false)
                ->setIsCritical(true)
        );
        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($blockSkipped)
                ->setIsApplicable(false)
                ->setWillImplement(null)
                ->setApplicabilitySource('block_skip')
        );

        $measureRepository = $this->createMock(MeasureRepository::class);
        $measureRepository->method('getCatalogMeasuresForProtocol')
            ->with($project, $protocol)
            ->willReturn([$selected, $discarded, $blockSkipped]);

        $catalogResolver = new PlanMeasureCatalogResolver(
            $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())
        );
        $commitmentService = new SustainabilityCommitmentLevelService($measureRepository, $catalogResolver);
        $service = new SustainabilityPlanClosureSummaryService(
            $measureRepository,
            $catalogResolver,
            $commitmentService,
            new SustainabilityPlanCustomMeasureParser()
        );

        $summary = $service->buildSummary($plan, $project);

        self::assertSame(3, $summary['measures']['official']);
        self::assertSame(1, $summary['measures']['selected']);
        self::assertSame(1, $summary['measures']['discarded']);
        self::assertSame(1, $summary['measures']['notApplicable']);
        self::assertSame(1, $summary['measures']['critical']);
        self::assertSame(2, $summary['measures']['custom']);
        self::assertSame(5, $summary['commitment']['planned']['points']);
        self::assertSame(12, $summary['commitment']['totalOfficialPoints']);
    }

    private function createMeasure(int $id, string $name, int $score, Protocol $protocol): Measure
    {
        $measure = (new Measure())
            ->setName($name)
            ->setScore($score)
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION);
        $this->setEntityId($measure, $id);

        return $measure;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
