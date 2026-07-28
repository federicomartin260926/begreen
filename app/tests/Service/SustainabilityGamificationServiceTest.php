<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\Protocol;
use App\Enum\CommercialPhase;
use App\Repository\MeasureRepository;
use App\Repository\PlanMeasureRepository;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\SustainabilityCommitmentLevelService;
use App\Service\SustainabilityGamificationMessageCatalog;
use App\Service\SustainabilityGamificationService;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SustainabilityGamificationServiceTest extends TestCase
{
    use CommercialPlanTestHelpers;

    public function testEverySecondFirstDecisionUsesResultingLevelAndDoesNotCountEdits(): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(10, array_fill(0, 10, 1));
        $plan->markGamificationLevelPresented('seed');

        $first = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $first);
        $first->setIsApplicable(true)->setWillImplement(false);

        self::assertNull($service->evaluate($plan, $project, $first, $before, 'decision'));
        self::assertNotNull($first->getFirstDecisionAnsweredAt());

        $second = $this->attachPending($plan, $measures[1]);
        $before = $service->captureTransition($plan, $project, $second);
        $second->setIsApplicable(false)->setWillImplement(null);
        $message = $service->evaluate($plan, $project, $second, $before, 'decision');

        self::assertSame('progress.seed.001', $message['key']);
        self::assertSame('progress.seed.001', $plan->getLastGamificationProgressKey());
        self::assertSame('progress.seed.001', $plan->getPendingGamificationKey());
        self::assertSame(2, $this->countFirstDecisions($plan));

        $before = $service->captureTransition($plan, $project, $second);
        self::assertNull($service->evaluate($plan, $project, $second, $before, 'decision'));
        self::assertSame(2, $this->countFirstDecisions($plan));
        self::assertSame(
            ['progress', 'seed', null],
            $catalog->calls[0]
        );
        self::assertSame(
            'progress.seed.001',
            $service->claimPendingMessageForDisplay($plan, $project, $measures[2]->getId())['key']
        );

        $third = $this->attachPending($plan, $measures[2]);
        $before = $service->captureTransition($plan, $project, $third);
        $third->setIsApplicable(true)->setWillImplement(false);
        self::assertNull($service->evaluate($plan, $project, $third, $before, 'decision'));

        $fourth = $this->attachPending($plan, $measures[3]);
        $before = $service->captureTransition($plan, $project, $fourth);
        $fourth->setIsApplicable(false);
        $service->evaluate($plan, $project, $fourth, $before, 'decision');

        self::assertSame(
            ['progress', 'seed', 'progress.seed.001'],
            $catalog->calls[1]
        );
    }

    public function testBlockSkipDoesNotCountAndHistoricalMarkerPreventsRecountAfterReopen(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(2, [1, 1]);
        $plan->markGamificationLevelPresented('seed');
        $planMeasure = $this->attachPending($plan, $measures[0]);
        $planMeasure
            ->setIsApplicable(false)
            ->setApplicabilitySource('block_skip');

        $before = $service->captureTransition($plan, $project, $planMeasure);
        self::assertNull($service->evaluate($plan, $project, $planMeasure, $before, 'decision'));
        self::assertNull($planMeasure->getFirstDecisionAnsweredAt());

        $planMeasure
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $before = $service->captureTransition($plan, $project, $planMeasure);
        $planMeasure->setIsApplicable(true)->setWillImplement(false);
        $service->evaluate($plan, $project, $planMeasure, $before, 'decision');
        $firstAnsweredAt = $planMeasure->getFirstDecisionAnsweredAt();

        $planMeasure
            ->setIsApplicable(false)
            ->setWillImplement(null)
            ->setApplicabilitySource('block_skip');
        $planMeasure
            ->setIsApplicable(null)
            ->setApplicabilitySource('manual');
        $before = $service->captureTransition($plan, $project, $planMeasure);
        $planMeasure->setIsApplicable(true)->setWillImplement(false);

        self::assertNull($service->evaluate($plan, $project, $planMeasure, $before, 'decision'));
        self::assertSame($firstAnsweredAt, $planMeasure->getFirstDecisionAnsweredAt());
        self::assertSame(1, $this->countFirstDecisions($plan));
    }

    #[DataProvider('welcomeLevels')]
    public function testWelcomeForCurrentLevelIsClaimedOnlyOnce(int $points, string $expectedLevel): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(100, array_fill(0, 100, 1));
        for ($index = 0; $index < $points; ++$index) {
            $this->attachAccepted($plan, $measures[$index]);
        }

        $message = $service->claimCurrentLevelWelcome($plan, $project);

        self::assertSame('welcome.' . $expectedLevel . '.001', $message['key']);
        self::assertTrue($plan->hasPresentedGamificationLevel($expectedLevel));
        self::assertSame('welcome.' . $expectedLevel . '.001', $plan->getPendingGamificationKey());
        self::assertNull($service->claimCurrentLevelWelcome($plan, $project));
    }

    public static function welcomeLevels(): iterable
    {
        yield 'seed' => [0, 'seed'];
        yield 'plant' => [21, 'plant'];
        yield 'tree' => [41, 'tree'];
        yield 'forest' => [61, 'forest'];
        yield 'jungle' => [81, 'jungle'];
    }

    public function testLevelUpHasPriorityOverPeriodicAndIsNotRepeatedAfterReentry(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(10, array_fill(0, 10, 1));
        $plan->markGamificationLevelPresented('seed');
        $this->attachAccepted($plan, $measures[0])->markFirstDecisionAnswered();
        $this->attachAccepted($plan, $measures[1]);

        $current = $this->attachPending($plan, $measures[2]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);
        $message = $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame('level_up.plant.001', $message['key']);
        self::assertTrue($plan->hasPresentedGamificationLevel('plant'));

        $current->setWillImplement(false);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setWillImplement(true);

        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
    }

    public function testJumpAcrossSeveralLevelsUsesResultingLevel(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(2, [5, 5]);
        $plan->markGamificationLevelPresented('seed');
        $current = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        $message = $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame('level_up.tree.001', $message['key']);
        self::assertTrue($plan->hasPresentedGamificationLevel('tree'));
        self::assertFalse($plan->hasPresentedGamificationLevel('plant'));
    }

    public function testExactHundredHasMaximumPriorityAndOnlyRunsOnce(): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(2, [5, 5]);
        $plan->markGamificationLevelPresented('tree');
        $this->attachAccepted($plan, $measures[0])->markFirstDecisionAnswered();
        $plan->queueGamificationMessage('progress.forest.005', 'progress', $measures[0]->getId());
        $current = $this->attachPending($plan, $measures[1]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        $message = $service->evaluate($plan, $project, $current, $before, 'decision');
        $after = $service->captureTransition($plan, $project, $current);

        self::assertGreaterThan(0, $after['summary']['totalOfficialPoints']);
        self::assertSame(
            $after['summary']['totalOfficialPoints'],
            $after['summary']['planned']['points']
        );
        self::assertSame('completed_100.001', $message['key']);
        self::assertSame('completed_100.001', $plan->getPendingGamificationKey());
        self::assertSame('completed_100', $plan->getPendingGamificationType());
        self::assertSame($measures[1]->getId(), $plan->getPendingGamificationSourceMeasureId());
        self::assertNotNull($plan->getGamificationCompleted100At());
        self::assertTrue($plan->hasPresentedGamificationLevel('jungle'));
        self::assertSame(
            [['completed_100', null, null]],
            $catalog->calls,
            'No lower-priority catalogue may be evaluated after selecting completed_100.'
        );

        $displayed = $service->claimPendingMessageForDisplay($plan, $project, null);
        self::assertStringStartsWith('completed_100.', $displayed['key']);
        self::assertStringNotContainsString('progress.', $displayed['key']);
        self::assertStringNotContainsString('level_up.', $displayed['key']);
        self::assertStringNotContainsString('welcome.', $displayed['key']);
        self::assertFalse($plan->hasPendingGamificationMessage());
        self::assertNull($service->claimPendingMessageForDisplay($plan, $project, null));

        $current->setWillImplement(false);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setWillImplement(true);
        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
    }

    #[DataProvider('criticalScores')]
    public function testCriticalMessageRequiresCompleteConfirmationAndIsIndependentOfScore(int $score): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(2, [$score, $score]);
        $plan
            ->markGamificationLevelPresented('tree')
            ->markGamificationCompleted100()
            ->markGamificationLevelPresented('jungle');
        $planMeasure = $this->attachAccepted($plan, $measures[0]);

        $before = $service->captureTransition($plan, $project, $planMeasure);
        $planMeasure->setIsCritical(true);
        self::assertNull($service->evaluate($plan, $project, $planMeasure, $before, 'critical'));

        $before = $service->captureTransition($plan, $project, $planMeasure);
        $planMeasure->setCriticalReason('Motivo obligatorio');
        $message = $service->evaluate($plan, $project, $planMeasure, $before, 'criticalReason');

        self::assertSame('critical.001', $message['key']);
        self::assertNotNull($planMeasure->getCriticalGamificationHandledAt());

        $before = $service->captureTransition($plan, $project, $planMeasure);
        self::assertNull($service->evaluate($plan, $project, $planMeasure, $before, 'willImplement'));
    }

    public static function criticalScores(): iterable
    {
        yield 'one point' => [1];
        yield 'five points' => [5];
    }

    public function testPendingWelcomeBeatsCriticalAndConsumesCriticalTrigger(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(2, [1, 1]);
        $planMeasure = $this->attachAccepted($plan, $measures[0]);
        $planMeasure->setIsCritical(true)->setCriticalReason('Motivo');
        $before = $service->captureTransition($plan, $project, $planMeasure);

        $message = $service->evaluate($plan, $project, $planMeasure, $before, 'criticalReason');

        self::assertSame('welcome.tree.001', $message['key']);
        self::assertNotNull($planMeasure->getCriticalGamificationHandledAt());

        $before = $service->captureTransition($plan, $project, $planMeasure);
        self::assertNull($service->evaluate($plan, $project, $planMeasure, $before, 'willImplement'));
    }

    public function testPendingMessageWaitsForAnotherMeasureAndIsConsumedOnlyOnce(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(10, array_fill(0, 10, 1));
        $plan->markGamificationLevelPresented('seed');
        $this->attachAccepted($plan, $measures[0])->markFirstDecisionAnswered();
        $current = $this->attachPending($plan, $measures[1]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(false);

        $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame($measures[1]->getId(), $plan->getPendingGamificationSourceMeasureId());
        self::assertNull(
            $service->claimPendingMessageForDisplay($plan, $project, $measures[1]->getId()),
            'Reloading the source measure must not consume the pending message.'
        );

        $message = $service->claimPendingMessageForDisplay($plan, $project, $measures[2]->getId());

        self::assertSame('progress.seed.001', $message['key']);
        self::assertFalse($plan->hasPendingGamificationMessage());
        self::assertNull($service->claimPendingMessageForDisplay($plan, $project, $measures[2]->getId()));
    }

    public function testPendingMessageCanBeConsumedOnTerminalScreenWithoutBeingLost(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(2, [5, 5]);
        $plan->markGamificationLevelPresented('tree');
        $this->attachAccepted($plan, $measures[0])->markFirstDecisionAnswered();
        $current = $this->attachPending($plan, $measures[1]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame('completed_100.001', $plan->getPendingGamificationKey());
        self::assertSame(
            'completed_100.001',
            $service->claimPendingMessageForDisplay($plan, $project, null)['key']
        );
        self::assertNull($service->claimPendingMessageForDisplay($plan, $project, null));
    }

    public function testInitialWelcomeIsClaimedForIntegratedDisplayOnlyOnce(): void
    {
        [$service, , $plan, $project] = $this->createScenario(2, [1, 1]);

        $message = $service->claimPendingMessageForDisplay($plan, $project, 1000);

        self::assertSame('welcome.seed.001', $message['key']);
        self::assertTrue($plan->hasPresentedGamificationLevel('seed'));
        self::assertFalse($plan->hasPendingGamificationMessage());
        self::assertNull($service->claimPendingMessageForDisplay($plan, $project, 1000));
    }

    /**
     * @param int[] $scores
     * @return array{SustainabilityGamificationService, RecordingGamificationCatalog, Plan, Project, Measure[]}
     */
    private function createScenario(int $measureCount, array $scores): array
    {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $resolver = new PlanMeasureCatalogResolver($gate);
        $measureRepository = $this->createMock(MeasureRepository::class);
        $protocol = (new Protocol())
            ->setCode(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE)
            ->setName('Be Green My Film');
        $this->setEntityId($protocol, 9001);

        $project = new Project();
        $project->addSubscription(
            (new ProjectSubscription())
                ->setPhase(CommercialPhase::ELABORATION)
                ->setTier(ProjectSubscription::TIER_PRO)
                ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                ->setSource(ProjectSubscription::SOURCE_MANUAL)
        );
        $plan = (new Plan())->setProject($project)->setProtocol($protocol);

        $measures = [];
        for ($index = 0; $index < $measureCount; ++$index) {
            $measure = (new Measure())
                ->setName('Measure ' . ($index + 1))
                ->setProtocol($protocol)
                ->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION)
                ->setScore($scores[$index] ?? 1);
            $this->setEntityId($measure, 1000 + $index);
            $measures[] = $measure;
        }
        $measureRepository->method('getCatalogMeasuresForProtocol')->willReturn($measures);

        $commitmentService = new SustainabilityCommitmentLevelService($measureRepository, $resolver);
        $catalog = new RecordingGamificationCatalog();
        $planMeasureRepository = $this->createMock(PlanMeasureRepository::class);
        $planMeasureRepository->method('countFirstDecisionsForPlan')->willReturnCallback(
            static fn (Plan $candidate): int => count(array_filter(
                $candidate->getPlanMeasures()->toArray(),
                static fn (PlanMeasure $planMeasure): bool => $planMeasure->getFirstDecisionAnsweredAt() !== null
            ))
        );

        return [
            new SustainabilityGamificationService($commitmentService, $catalog, $planMeasureRepository),
            $catalog,
            $plan,
            $project,
            $measures,
        ];
    }

    private function attachPending(Plan $plan, Measure $measure): PlanMeasure
    {
        $planMeasure = (new PlanMeasure())->setMeasure($measure)->markAsManual();
        $plan->addPlanMeasure($planMeasure);

        return $planMeasure;
    }

    private function attachAccepted(Plan $plan, Measure $measure): PlanMeasure
    {
        $planMeasure = $this->attachPending($plan, $measure);
        $planMeasure->setIsApplicable(true)->setWillImplement(true);

        return $planMeasure;
    }

    private function countFirstDecisions(Plan $plan): int
    {
        return count(array_filter(
            $plan->getPlanMeasures()->toArray(),
            static fn (PlanMeasure $planMeasure): bool => $planMeasure->getFirstDecisionAnsweredAt() !== null
        ));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }
}

final class RecordingGamificationCatalog extends SustainabilityGamificationMessageCatalog
{
    /** @var array<int, array{string, ?string, ?string}> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function choose(string $event, ?string $level = null, ?string $excludedKey = null): array
    {
        $this->calls[] = [$event, $level, $excludedKey];
        $key = $event . ($level === null ? '' : '.' . $level) . '.001';

        return ['key' => $key, 'type' => $event, 'text' => $key];
    }

    public function translate(string $key, string $event): array
    {
        return ['key' => $key, 'type' => $event, 'text' => $key];
    }
}
