<?php

declare(strict_types=1);

namespace App\Tests\Service\FinalReport;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Measure;
use App\Entity\Ods;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Entity\Protocol;
use App\Repository\EmissionRecordRepository;
use App\Repository\MeasureRepository;
use App\Service\FinalReport\FinalReportDataBuilder;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\PlanMeasureOperationalStateResolver;
use App\Service\SustainabilityCommitmentLevelService;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FinalReportDataBuilderTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testProjectWithoutEmissionsReturnsUnavailableEmptyBreakdown(): void
    {
        [$builder, $project, $plan] = $this->context([]);

        $data = $builder->build($project, $plan);

        self::assertFalse($data['emissions']['available']);
        self::assertSame(0, $data['emissions']['validRecordCount']);
        self::assertNull($data['emissions']['totalKgCo2e']);
        self::assertSame([], $data['emissions']['byCategory']);
        self::assertSame([], $data['emissions']['byPhase']);
    }

    public function testSumsEmissionsAndBuildsCategoryAndPhaseBreakdowns(): void
    {
        $preproduction = $this->phase('preproduccion');
        $activity = $this->phase('actividad');
        $energy = (new Category())->setName('Energía');
        $transport = (new Category())->setName('Transporte');
        $records = [
            $this->record($preproduction, $energy, 10.5),
            $this->record($preproduction, $energy, 2.5),
            $this->record($activity, $transport, 7.0, EmissionRecord::STATUS_CLOSED),
        ];
        [$builder, $project, $plan] = $this->context($records);

        $emissions = $builder->build($project, $plan)['emissions'];

        self::assertTrue($emissions['available']);
        self::assertSame(3, $emissions['validRecordCount']);
        self::assertSame(20.0, $emissions['totalKgCo2e']);
        self::assertSame([
            ['id' => null, 'name' => 'Energía', 'kgCo2e' => 13.0],
            ['id' => null, 'name' => 'Transporte', 'kgCo2e' => 7.0],
        ], $emissions['byCategory']);
        self::assertSame([
            ['id' => null, 'code' => 'preproduccion', 'kgCo2e' => 13.0],
            ['id' => null, 'code' => 'actividad', 'kgCo2e' => 7.0],
        ], $emissions['byPhase']);
    }

    public function testExcludesRecordsWithoutAValidCalculatedEmission(): void
    {
        $phase = $this->phase('actividad');
        $category = (new Category())->setName('Materiales');
        $records = [
            $this->record($phase, $category, 4.0),
            $this->record($phase, $category, 100.0, EmissionRecord::STATUS_DRAFT),
            $this->record($phase, $category, 200.0, EmissionRecord::STATUS_PENDING_DATA),
            $this->record($phase, $category, null, EmissionRecord::STATUS_CALCULATED),
            $this->record($phase, $category, null, EmissionRecord::STATUS_NOT_AUTOMATICALLY_CALCULABLE),
        ];
        [$builder, $project, $plan] = $this->context($records);

        $emissions = $builder->build($project, $plan)['emissions'];

        self::assertSame(1, $emissions['validRecordCount']);
        self::assertSame(4.0, $emissions['totalKgCo2e']);
    }

    public function testUsesOperationalResolverForRealImplementationStates(): void
    {
        [$builder, $project, $plan] = $this->context([]);
        $plan->addPlanMeasure($this->planMeasure('Pendiente'));
        $plan->addPlanMeasure($this->planMeasure('En curso')->setImplemented(true));
        $plan->addPlanMeasure($this->implementedPlanMeasure('Implementada'));
        $plan->addPlanMeasure($this->planMeasure('No implementada')->setImplemented(false));
        $plan->addPlanMeasure($this->planMeasure('Descartada')->setWillImplement(false));

        $implementation = $builder->build($project, $plan)['implementation'];

        self::assertSame([
            PlanMeasureOperationalStateResolver::PENDING,
            PlanMeasureOperationalStateResolver::IN_PROGRESS,
            PlanMeasureOperationalStateResolver::IMPLEMENTED,
            PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED,
            PlanMeasureOperationalStateResolver::DISCARDED,
        ], array_column($implementation['measures'], 'state'));
        self::assertSame([
            'totalMeasures' => 5,
            'implementedMeasures' => 1,
            'resolvedMeasures' => 3,
            'byState' => [
                PlanMeasureOperationalStateResolver::PENDING => 1,
                PlanMeasureOperationalStateResolver::IN_PROGRESS => 1,
                PlanMeasureOperationalStateResolver::IMPLEMENTED => 1,
                PlanMeasureOperationalStateResolver::NOT_IMPLEMENTED => 1,
                PlanMeasureOperationalStateResolver::DISCARDED => 1,
                PlanMeasureOperationalStateResolver::NOT_APPLICABLE => 0,
            ],
        ], $implementation['summary']);
    }

    public function testRejectsPlanBelongingToAnotherProject(): void
    {
        $project = (new Project())->setName('Proyecto solicitado')->setType('rodaje')->setCountry('ES');
        $otherProject = (new Project())->setName('Otro proyecto')->setType('rodaje')->setCountry('ES');
        $plan = (new Plan())->setProject($otherProject);
        $repository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $repository->expects(self::never())->method('findByProjectOrderByPhaseAndDate');
        $commitmentService = (new \ReflectionClass(SustainabilityCommitmentLevelService::class))
            ->newInstanceWithoutConstructor();
        $builder = new FinalReportDataBuilder(
            $repository,
            new PlanMeasureOperationalStateResolver(),
            $commitmentService,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong');

        $builder->build($project, $plan);
    }

    public function testBuildsCommitmentSummaryWithRealProtocolAndService(): void
    {
        $project = $this->makeProjectWithTier('pro')
            ->setName('Proyecto con compromiso')
            ->setType('rodaje')
            ->setCountry('ES');
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE);
        $measure = (new Measure())
            ->setName('Medida oficial')
            ->setProtocol($protocol)
            ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
            ->setScore(5);
        $this->setEntityId($protocol, 7001);
        $this->setEntityId($measure, 7002);
        $plan = (new Plan())
            ->setProject($project)
            ->setProtocol($protocol)
            ->addPlanMeasure($this->implementedPlanMeasure('Medida oficial')->setMeasure($measure));

        $measureRepository = $this->createMock(MeasureRepository::class);
        $measureRepository->expects(self::once())
            ->method('getCatalogMeasuresForProtocol')
            ->with($project, $protocol)
            ->willReturn([$measure]);
        $commitmentService = new SustainabilityCommitmentLevelService(
            $measureRepository,
            new PlanMeasureCatalogResolver($this->makeProjectFeatureGate($this->makeDefaultCommercialPlans())),
        );
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->expects(self::once())
            ->method('findByProjectOrderByPhaseAndDate')
            ->with($project)
            ->willReturn([]);
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            $commitmentService,
        );

        $commitment = $builder->build($project, $plan)['commitment'];

        self::assertSame(5, $commitment['totalOfficialPoints']);
        self::assertSame(5, $commitment['planned']['points']);
        self::assertSame(5, $commitment['implemented']['points']);
        self::assertSame(100, $commitment['implemented']['percentageRounded']);
        self::assertSame('jungle', $commitment['implemented']['levelKey']);
    }

    public function testDeduplicatesOdsFromActuallyImplementedMeasures(): void
    {
        [$builder, $project, $plan] = $this->context([]);
        $climate = (new Ods())->setCode('13')->setName('Acción por el clima');

        $first = $this->implementedPlanMeasure('Primera');
        $first->setOds($climate);
        $first->getMeasure()?->addOdsItem($climate);
        $second = $this->implementedPlanMeasure('Segunda');
        $second->getMeasure()?->addOdsItem($climate);
        $notImplemented = $this->planMeasure('No ejecutada')->setImplemented(false)->setOds(
            (new Ods())->setCode('12')->setName('Producción responsable'),
        );
        $plan->addPlanMeasure($first)->addPlanMeasure($second)->addPlanMeasure($notImplemented);

        $ods = $builder->build($project, $plan)['ods'];

        self::assertSame([
            ['id' => null, 'code' => '13', 'name' => 'Acción por el clima'],
        ], $ods);
    }

    public function testOptionalDataRemainsNullOrEmpty(): void
    {
        [$builder, $project, $plan] = $this->context([]);
        $plan->addPlanMeasure((new PlanMeasure())->setIsApplicable(null));

        $data = $builder->build($project, $plan);

        self::assertNull($data['project']['mainLocation']);
        self::assertSame([], $data['project']['phases']);
        self::assertNull($data['plan']['protocol']);
        self::assertNull($data['plan']['measures'][0]['measure']);
        self::assertNull($data['implementation']['measures'][0]['actionTaken']);
        self::assertNull($data['implementation']['measures'][0]['incident']);
        self::assertNull($data['implementation']['measures'][0]['observations']);
        self::assertSame([], $data['implementation']['measures'][0]['responsibleCrewMembers']);
        self::assertSame([], $data['ods']);
    }

    public function testDoesNotInventPhysicalTreeEquivalences(): void
    {
        $record = $this->record(
            $this->phase('actividad'),
            (new Category())->setName('Energía'),
            125.0,
        );
        [$builder, $project, $plan] = $this->context([$record]);

        $emissions = $builder->build($project, $plan)['emissions'];
        $serialized = json_encode($emissions, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('tree', strtolower($serialized));
        self::assertStringNotContainsString('árbol', strtolower($serialized));
        self::assertSame(125.0, $emissions['totalKgCo2e']);
    }

    /**
     * @param EmissionRecord[] $records
     * @return array{FinalReportDataBuilder, Project, Plan}
     */
    private function context(array $records): array
    {
        $project = (new Project())
            ->setName('Proyecto de prueba')
            ->setType('rodaje')
            ->setCountry('ES');
        $plan = (new Plan())->setProject($project);

        /** @var EmissionRecordRepository&MockObject $repository */
        $repository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findByProjectOrderByPhaseAndDate')
            ->with($project)
            ->willReturn($records);

        $commitmentService = (new \ReflectionClass(SustainabilityCommitmentLevelService::class))
            ->newInstanceWithoutConstructor();

        return [
            new FinalReportDataBuilder(
                $repository,
                new PlanMeasureOperationalStateResolver(),
                $commitmentService,
            ),
            $project,
            $plan,
        ];
    }

    private function phase(string $code): ProjectPhaseDate
    {
        return (new ProjectPhaseDate())
            ->setPhase($code)
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-01-31'));
    }

    private function record(
        ProjectPhaseDate $phase,
        Category $category,
        ?float $emission,
        string $status = EmissionRecord::STATUS_CALCULATED,
    ): EmissionRecord {
        return (new EmissionRecord())
            ->setPhase($phase)
            ->setCategory($category)
            ->setEmission($emission)
            ->setStatus($status);
    }

    private function planMeasure(string $name): PlanMeasure
    {
        return (new PlanMeasure())
            ->setMeasure((new Measure())->setName($name))
            ->setIsApplicable(true)
            ->setWillImplement(true);
    }

    private function implementedPlanMeasure(string $name): PlanMeasure
    {
        return $this->planMeasure($name)
            ->setImplemented(true)
            ->setActionTaken(str_repeat('Acción ejecutada con detalle suficiente. ', 2))
            ->setEvidence('/evidences/report.pdf');
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }
}
