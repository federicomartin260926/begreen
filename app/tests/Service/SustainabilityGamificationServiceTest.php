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

    public function testFirstAffirmativeDecisionQueuesMeasureMessageAndConsumesItOnlyOnceOnNextMeasure(): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(
            10,
            array_fill(0, 10, 1),
            ['Mensaje específico']
        );
        $plan->markGamificationLevelPresented('seed');
        $current = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        $message = $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame(
            ['key' => 'measure.1000', 'type' => 'measure', 'text' => 'Mensaje específico'],
            $message
        );
        self::assertSame('measure.1000', $plan->getPendingGamificationKey());
        self::assertSame('measure', $plan->getPendingGamificationType());
        self::assertSame(1000, $plan->getPendingGamificationSourceMeasureId());
        self::assertSame([], $catalog->calls);
        self::assertNull($service->claimPendingMessageForDisplay($plan, $project, 1000));
        self::assertTrue($plan->hasPendingGamificationMessage());
        self::assertSame(
            $message,
            $service->claimPendingMessageForDisplay($plan, $project, 1001)
        );
        self::assertFalse($plan->hasPendingGamificationMessage());
        self::assertNull($service->claimPendingMessageForDisplay($plan, $project, 1001));
    }

    #[DataProvider('nonAffirmativeDecisions')]
    public function testNonAffirmativeDecisionDoesNotQueueMessage(string $decision): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(
            10,
            array_fill(0, 10, 1),
            ['No debe mostrarse']
        );
        $current = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $current);

        if ($decision === 'na') {
            $current->setIsApplicable(false)->setWillImplement(null);
        } else {
            $current->setIsApplicable(true)->setWillImplement(false);
        }

        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
        self::assertFalse($plan->hasPendingGamificationMessage());
        self::assertSame([], $catalog->calls);
    }

    public static function nonAffirmativeDecisions(): iterable
    {
        yield 'No' => ['false'];
        yield 'No aplica' => ['na'];
    }

    public function testAffirmativeDecisionWithoutMeasureMessageDoesNotQueueFallback(): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(10, array_fill(0, 10, 1));
        $current = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
        self::assertFalse($plan->hasPendingGamificationMessage());
        self::assertSame([], $catalog->calls);
    }

    public function testLevelUpReplacesLowerPriorityPendingMessageAndSuppressesMeasureMessage(): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(
            10,
            array_fill(0, 10, 1),
            [null, null, 'Mensaje específico']
        );
        $plan->markGamificationLevelPresented('seed');
        $this->attachAccepted($plan, $measures[0])->markFirstDecisionAnswered();
        $this->attachAccepted($plan, $measures[1])->markFirstDecisionAnswered();
        $plan->queueGamificationMessage('measure.1001', 'measure', 1001);
        $current = $this->attachPending($plan, $measures[2]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        $message = $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame('level_up.plant.001', $message['key']);
        self::assertSame('level_up.plant.001', $plan->getPendingGamificationKey());
        self::assertSame([['level_up', 'plant', null]], $catalog->calls);
        self::assertTrue($plan->hasPresentedGamificationLevel('plant'));
    }

    public function testExactHundredHasMaximumPriorityAndSuppressesMeasureMessage(): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(
            2,
            [5, 5],
            [null, 'Mensaje específico']
        );
        $plan->markGamificationLevelPresented('tree');
        $this->attachAccepted($plan, $measures[0])->markFirstDecisionAnswered();
        $plan->queueGamificationMessage('measure.1000', 'measure', 1000);
        $current = $this->attachPending($plan, $measures[1]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        $message = $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame('completed_100.001', $message['key']);
        self::assertSame('completed_100.001', $plan->getPendingGamificationKey());
        self::assertSame([['completed_100', null, null]], $catalog->calls);
        self::assertNotNull($plan->getGamificationCompleted100At());
        self::assertTrue($plan->hasPresentedGamificationLevel('jungle'));
    }

    public function testSavingAnAlreadyAffirmativeMeasureDoesNotQueueAgain(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(
            10,
            array_fill(0, 10, 1),
            ['Mensaje específico']
        );
        $plan->markGamificationLevelPresented('seed');
        $current = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);
        $service->evaluate($plan, $project, $current, $before, 'decision');
        $service->claimPendingMessageForDisplay($plan, $project, 1001);

        $before = $service->captureTransition($plan, $project, $current);

        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
        self::assertFalse($plan->hasPendingGamificationMessage());
    }

    public function testFirstAffirmativeTransitionAfterNoQueuesOnceButLaterReacceptanceDoesNot(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(
            10,
            array_fill(0, 10, 1),
            ['Mensaje específico']
        );
        $plan->markGamificationLevelPresented('seed');
        $current = $this->attachPending($plan, $measures[0]);

        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(false);
        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
        self::assertNull($current->getFirstDecisionAnsweredAt());

        $before = $service->captureTransition($plan, $project, $current);
        $current->setWillImplement(true);
        self::assertSame(
            'Mensaje específico',
            $service->evaluate($plan, $project, $current, $before, 'decision')['text']
        );
        self::assertNotNull($current->getFirstDecisionAnsweredAt());
        $service->claimPendingMessageForDisplay($plan, $project, 1001);

        $before = $service->captureTransition($plan, $project, $current);
        $current->setWillImplement(false);
        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));

        $before = $service->captureTransition($plan, $project, $current);
        $current->setWillImplement(true);
        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
        self::assertFalse($plan->hasPendingGamificationMessage());
    }

    public function testLocalizedEnglishMeasureMessageIsUsedAsLoadedByGedmo(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(
            10,
            array_fill(0, 10, 1),
            ['English inspirational message']
        );
        $plan->markGamificationLevelPresented('seed');
        $current = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        $message = $service->evaluate($plan, $project, $current, $before, 'decision');

        self::assertSame('English inspirational message', $message['text']);
        self::assertSame(
            'English inspirational message',
            $service->claimPendingMessageForDisplay($plan, $project, 1001)['text']
        );
    }

    public function testEventProtocolUsesMeasureMessageWithoutProtocolSpecificBranch(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(
            10,
            array_fill(0, 10, 1),
            ['Mensaje Event'],
            PlanMeasureCatalogResolver::BE_GREEN_MY_EVENT_CODE
        );
        $plan->markGamificationLevelPresented('seed');
        $current = $this->attachPending($plan, $measures[0]);
        $before = $service->captureTransition($plan, $project, $current);
        $current->setIsApplicable(true)->setWillImplement(true);

        self::assertSame(
            'Mensaje Event',
            $service->evaluate($plan, $project, $current, $before, 'decision')['text']
        );
    }

    public function testPeriodicCriticalAndHighScoreMessagesAreNoLongerTriggered(): void
    {
        [$service, $catalog, $plan, $project, $measures] = $this->createScenario(3, [4, 5, 1]);
        $plan
            ->markGamificationLevelPresented('seed')
            ->markGamificationLevelPresented('plant')
            ->markGamificationLevelPresented('tree')
            ->markGamificationLevelPresented('forest')
            ->markGamificationLevelPresented('jungle')
            ->markGamificationCompleted100();

        foreach ([$measures[0], $measures[1]] as $measure) {
            $current = $this->attachPending($plan, $measure);
            $before = $service->captureTransition($plan, $project, $current);
            $current->setIsApplicable(true)->setWillImplement(false);
            self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
        }

        $critical = $plan->getPlanMeasures()->first();
        $before = $service->captureTransition($plan, $project, $critical);
        $critical
            ->setWillImplement(true)
            ->setIsCritical(true)
            ->setCriticalReason('Motivo obligatorio');

        self::assertNull($service->evaluate($plan, $project, $critical, $before, 'criticalReason'));
        self::assertSame([], $catalog->calls);
        self::assertFalse($plan->hasPendingGamificationMessage());
    }

    public function testBlockSkipDoesNotConsumeTheFirstDecisionMarker(): void
    {
        [$service, , $plan, $project, $measures] = $this->createScenario(2, [1, 1], ['Mensaje']);
        $plan->markGamificationLevelPresented('seed');
        $current = $this->attachPending($plan, $measures[0]);
        $current
            ->setIsApplicable(false)
            ->setApplicabilitySource('block_skip');
        $before = $service->captureTransition($plan, $project, $current);

        self::assertNull($service->evaluate($plan, $project, $current, $before, 'decision'));
        self::assertNull($current->getFirstDecisionAnsweredAt());
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

    /**
     * @param int[] $scores
     * @param array<int, string|null> $messages
     * @return array{SustainabilityGamificationService, RecordingGamificationCatalog, Plan, Project, Measure[]}
     */
    private function createScenario(
        int $measureCount,
        array $scores,
        array $messages = [],
        string $protocolCode = PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE,
    ): array {
        $gate = $this->makeProjectFeatureGate($this->makeDefaultCommercialPlans());
        $resolver = new PlanMeasureCatalogResolver($gate);
        $measureRepository = $this->createMock(MeasureRepository::class);
        $protocol = (new Protocol())
            ->setCode($protocolCode)
            ->setName($protocolCode);
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
                ->setImportVersion(PlanMeasureCatalogResolver::CATALOG_IMPORT_VERSION)
                ->setScore($scores[$index] ?? 1)
                ->setGamificationMessage($messages[$index] ?? null);
            $this->setEntityId($measure, 1000 + $index);
            $measures[] = $measure;
        }
        $measureRepository->method('getCatalogMeasuresForProtocol')->willReturn($measures);

        $commitmentService = new SustainabilityCommitmentLevelService($measureRepository, $resolver);
        $catalog = new RecordingGamificationCatalog();

        return [
            new SustainabilityGamificationService($commitmentService, $catalog),
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
