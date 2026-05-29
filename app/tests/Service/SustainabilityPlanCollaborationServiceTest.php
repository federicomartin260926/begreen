<?php

namespace App\Tests\Service;

use App\Entity\CrewMember;
use App\Entity\Department;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Service\SustainabilityPlanCollaborationService;
use App\Service\SustainabilityPlanCustomMeasureParser;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Repository\ProjectSubscriptionRepository;

final class SustainabilityPlanCollaborationServiceTest extends TestCase
{
    public function testBuildProgressSummaryCountsEvidenceResponsiblesAndCustomMeasures(): void
    {
        $service = $this->createService();
        [$plan, $project, $protocol] = $this->createPlanContext();

        $dept = $this->createDepartment(10, 'prod', 'Producción');
        $otherDept = $this->createDepartment(11, 'post', 'Postproducción');

        $crew1 = $this->createCrewMember(101, 'Ana', 'García', $dept, $project);
        $crew2 = $this->createCrewMember(102, 'Luis', 'Pérez', $otherDept, $project);

        $measure = $this->createMeasure(201, $protocol);
        $measure->addDepartment($dept);

        $planMeasure = (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable(true)
            ->setWillImplement(true)
            ->setImplemented(true)
            ->setVerification(true)
            ->setEvidence("/uploads/evidences/1/a.pdf\n/uploads/evidences/1/b.pdf")
            ->setPublicComment('Visible comment')
            ->setInternalNotes('Internal comment');
        $planMeasure->addResponsibleCrewMember($crew1);
        $planMeasure->addResponsibleCrewMember($crew2);

        $plan->addPlanMeasure($planMeasure);
        $plan->setCustomMeasures("Custom A | Desc A | 4 | implemented\nCustom B | Desc B | 3 | verified");

        $summary = $service->buildProgressSummary($plan, $project);

        self::assertSame(1, $summary['toImplement']);
        self::assertSame(1, $summary['implemented']);
        self::assertSame(1, $summary['verified']);
        self::assertSame(2, $summary['evidenceFiles']);
        self::assertSame(1, $summary['responsibles']);
        self::assertSame(1, $summary['publicComments']);
        self::assertSame(1, $summary['internalNotes']);
        self::assertSame(2, $summary['customMeasures']);
    }

    public function testSkippedBlockMeasuresAreExcludedFromProgressSummary(): void
    {
        $service = $this->createService();
        [$plan, $project, $protocol] = $this->createPlanContext();

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('block-1')
            ->setName('Bloque 1')
            ->setSortOrder(1);
        $this->setEntityId($block, 7001);

        $blockAnswer = (new SustainabilityPlanBlockAnswer())
            ->setSustainabilityPlan($plan)
            ->setMeasureBlock($block)
            ->setApplies(false);
        $this->setEntityId($blockAnswer, 7002);
        $plan->addBlockAnswer($blockAnswer);

        $measureVisible = $this->createMeasure(701, $protocol);
        $measureSkipped = $this->createMeasure(702, $protocol);
        $measureSkipped->setMeasureBlock($block);

        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($measureVisible)
                ->setIsApplicable(true)
                ->setWillImplement(true)
                ->setImplemented(true)
                ->setVerification(true)
                ->setEvidence("/uploads/evidences/1/a.pdf")
                ->setPublicComment('Visible comment')
                ->setInternalNotes('Internal comment')
        );

        $plan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($measureSkipped)
                ->setIsApplicable(false)
                ->setApplicabilitySource('block_skip')
                ->setBlockSkipAnswer($blockAnswer)
                ->setWillImplement(null)
                ->setImplemented(null)
                ->setVerification(true)
                ->setEvidence("/uploads/evidences/1/b.pdf")
                ->setPublicComment('Skipped comment')
                ->setInternalNotes('Skipped internal')
        );

        $summary = $service->buildProgressSummary($plan, $project);

        self::assertSame(1, $summary['toImplement']);
        self::assertSame(1, $summary['implemented']);
        self::assertSame(1, $summary['verified']);
        self::assertSame(1, $summary['evidenceFiles']);
        self::assertSame(0, $summary['responsibles']);
        self::assertSame(1, $summary['publicComments']);
        self::assertSame(1, $summary['internalNotes']);
    }

    public function testSortCrewMembersForMeasurePrioritizesMatchingDepartments(): void
    {
        $service = $this->createService();
        [$plan, $project, $protocol] = $this->createPlanContext();
        $measure = $this->createMeasure(301, $protocol);

        $dept = $this->createDepartment(20, 'prod', 'Producción');
        $otherDept = $this->createDepartment(21, 'post', 'Postproducción');
        $measure->addDepartment($dept);

        $crew1 = $this->createCrewMember(401, 'Zoe', 'Álvarez', $otherDept, $project);
        $crew2 = $this->createCrewMember(402, 'Ana', 'García', $dept, $project);
        $crew3 = $this->createCrewMember(403, 'Luis', 'Pérez', null, $project);

        $sorted = $service->sortCrewMembersForMeasure($measure, [$crew1, $crew2, $crew3]);

        self::assertSame([402, 403, 401], array_map(static fn (CrewMember $crewMember): int => $crewMember->getId(), $sorted));
    }

    public function testSyncResponsibleCrewMembersDeduplicatesSelections(): void
    {
        $service = $this->createService();
        [$plan, $project, $protocol] = $this->createPlanContext();
        $measure = $this->createMeasure(501, $protocol);
        $planMeasure = (new PlanMeasure())->setMeasure($measure);

        $crew = $this->createCrewMember(601, 'Ana', 'García', null, $project);

        $service->syncResponsibleCrewMembers($planMeasure, [$crew, $crew]);

        self::assertCount(1, $planMeasure->getResponsibleCrewMembers());
        self::assertSame(601, $planMeasure->getResponsibleCrewMembers()->first()?->getId());
    }

    private function createService(): SustainabilityPlanCollaborationService
    {
        $subscriptionRepository = $this->createMock(ProjectSubscriptionRepository::class);
        $subscriptionRepository->method('findOneByProject')->willReturn(null);

        $gate = new ProjectFeatureGate($subscriptionRepository);
        $resolver = new PlanMeasureCatalogResolver($gate);
        $parser = new SustainabilityPlanCustomMeasureParser();

        return new SustainabilityPlanCollaborationService($resolver, $parser);
    }

    /**
     * @return array{0: Plan, 1: Project, 2: Protocol}
     */
    private function createPlanContext(): array
    {
        $protocol = (new Protocol())->setCode('be-green-my-film')->setName('Be Green My Film');
        $this->setEntityId($protocol, 1001);

        $project = new Project();
        $subscription = (new ProjectSubscription())
            ->setTier(ProjectSubscription::TIER_PRO)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL);
        $project->setSubscription($subscription);
        $project->setType('rodaje');

        $plan = (new Plan())
            ->setProject($project)
            ->setProtocol($protocol);

        return [$plan, $project, $protocol];
    }

    private function createMeasure(int $id, Protocol $protocol): Measure
    {
        $measure = new Measure();
        $this->setEntityId($measure, $id);
        $measure->setName('Measure ' . $id);
        $measure->setProtocol($protocol);
        $measure->setImportVersion('v23');
        $measure->setScore(5);

        return $measure;
    }

    private function createDepartment(int $id, string $code, string $name): Department
    {
        $department = (new Department())
            ->setCode($code)
            ->setName($name);
        $this->setEntityId($department, $id);

        return $department;
    }

    private function createCrewMember(int $id, string $name, string $lastName, ?Department $department, Project $project): CrewMember
    {
        $crewMember = (new CrewMember())
            ->setName($name)
            ->setLastName($lastName)
            ->setDepartment($department)
            ->setProject($project);
        $this->setEntityId($crewMember, $id);

        return $crewMember;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
