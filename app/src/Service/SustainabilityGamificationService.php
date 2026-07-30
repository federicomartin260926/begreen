<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class SustainabilityGamificationService
{
    private const EVENT_MEASURE = 'measure';

    public function __construct(
        private readonly SustainabilityCommitmentLevelService $commitmentLevelService,
        private readonly SustainabilityGamificationMessageCatalog $messageCatalog,
    ) {
    }

    /**
     * @return array{
     *     summary:array{
     *         totalOfficialPoints:int,
     *         planned:array{points:int,levelKey:string}
     *     },
     *     primaryDecision:?string
     * }
     */
    public function captureTransition(Plan $plan, Project $project, PlanMeasure $planMeasure): array
    {
        return [
            'summary' => $this->commitmentLevelService->buildSummary($plan, $project),
            'primaryDecision' => $planMeasure->getPrimaryDecision(),
        ];
    }

    /**
     * @param array{
     *     summary:array{
     *         totalOfficialPoints:int,
     *         planned:array{points:int,levelKey:string}
     *     },
     *     primaryDecision:?string
     * } $before
     *
     * @return array{key:string,type:string,text:string}|null
     */
    public function evaluateWithLock(
        EntityManagerInterface $entityManager,
        Plan $plan,
        Project $project,
        PlanMeasure $planMeasure,
        array $before,
        string $field,
    ): ?array {
        if ($plan->getId() === null) {
            return $this->evaluate($plan, $project, $planMeasure, $before, $field);
        }

        return $entityManager->wrapInTransaction(function (EntityManagerInterface $transactionalEntityManager) use (
            $plan,
            $project,
            $planMeasure,
            $before,
            $field
        ): ?array {
            $transactionalEntityManager->refresh($plan, LockMode::PESSIMISTIC_WRITE);
            $transactionalEntityManager->refresh($planMeasure);
            $message = $this->evaluate($plan, $project, $planMeasure, $before, $field);
            $transactionalEntityManager->flush();

            return $message;
        });
    }

    /**
     * @param array{
     *     summary:array{
     *         totalOfficialPoints:int,
     *         planned:array{points:int,levelKey:string}
     *     },
     *     primaryDecision:?string
     * } $before
     *
     * @return array{key:string,type:string,text:string}|null
     */
    public function evaluate(
        Plan $plan,
        Project $project,
        PlanMeasure $planMeasure,
        array $before,
        string $field,
    ): ?array {
        $firstAffirmativeDecision = $field === 'decision'
            && $before['primaryDecision'] !== 'true'
            && $planMeasure->getPrimaryDecision() === 'true'
            && $planMeasure->getApplicabilitySource() !== 'block_skip'
            && $planMeasure->getFirstDecisionAnsweredAt() === null;

        if ($firstAffirmativeDecision) {
            $planMeasure->markFirstDecisionAnswered();
        }

        $after = $this->commitmentLevelService->buildSummary($plan, $project);
        $previousLevel = $before['summary']['planned']['levelKey'];
        $resultingLevel = $after['planned']['levelKey'];
        $scoreIncreased = $field === 'decision'
            && $after['planned']['points'] > $before['summary']['planned']['points'];
        $enteredHigherLevel = $scoreIncreased
            && $this->commitmentLevelService->isHigherLevel($previousLevel, $resultingLevel)
            && !$plan->hasPresentedGamificationLevel($resultingLevel);

        $reached100 = $field === 'decision'
            && $plan->getGamificationCompleted100At() === null
            && $this->commitmentLevelService->hasReachedExactPlannedMaximum($after);

        if ($reached100) {
            $plan
                ->markGamificationCompleted100()
                ->markGamificationLevelPresented('jungle');

            return $this->chooseAndQueue(
                $plan,
                $planMeasure,
                SustainabilityGamificationMessageCatalog::EVENT_COMPLETED_100,
                replacePending: true
            );
        }

        if ($enteredHigherLevel) {
            $plan->markGamificationLevelPresented($resultingLevel);

            return $this->chooseAndQueue(
                $plan,
                $planMeasure,
                SustainabilityGamificationMessageCatalog::EVENT_LEVEL_UP,
                $resultingLevel,
                replacePending: true
            );
        }

        if ($firstAffirmativeDecision) {
            return $this->queueMeasureMessage($plan, $planMeasure);
        }

        return null;
    }

    /**
     * @return array{key:string,type:string,text:string}|null
     */
    public function claimCurrentLevelWelcome(Plan $plan, Project $project): ?array
    {
        $summary = $this->commitmentLevelService->buildSummary($plan, $project);
        $level = $summary['planned']['levelKey'];

        if ($plan->hasPresentedGamificationLevel($level)) {
            return null;
        }

        $plan->markGamificationLevelPresented($level);

        return $this->chooseAndQueue(
            $plan,
            null,
            SustainabilityGamificationMessageCatalog::EVENT_WELCOME,
            $level,
        );
    }

    /**
     * @return array{key:string,type:string,text:string}|null
     */
    public function claimPendingMessageForDisplayWithLock(
        EntityManagerInterface $entityManager,
        Plan $plan,
        Project $project,
        ?int $currentMeasureId,
    ): ?array {
        if ($plan->getId() === null) {
            return $this->claimPendingMessageForDisplay($plan, $project, $currentMeasureId);
        }

        return $entityManager->wrapInTransaction(function (EntityManagerInterface $transactionalEntityManager) use (
            $plan,
            $project,
            $currentMeasureId
        ): ?array {
            $transactionalEntityManager->refresh($plan, LockMode::PESSIMISTIC_WRITE);
            $message = $this->claimPendingMessageForDisplay($plan, $project, $currentMeasureId);
            $transactionalEntityManager->flush();

            return $message;
        });
    }

    /**
     * @return array{key:string,type:string,text:string}|null
     */
    public function claimPendingMessageForDisplay(
        Plan $plan,
        Project $project,
        ?int $currentMeasureId,
    ): ?array {
        if (!$plan->hasPendingGamificationMessage()) {
            $this->claimCurrentLevelWelcome($plan, $project);
        }

        if (!$plan->hasPendingGamificationMessage()) {
            return null;
        }

        $sourceMeasureId = $plan->getPendingGamificationSourceMeasureId();
        if ($sourceMeasureId !== null && $sourceMeasureId === $currentMeasureId) {
            return null;
        }

        $key = $plan->getPendingGamificationKey();
        $type = $plan->getPendingGamificationType();
        if ($key === null || $type === null) {
            return null;
        }

        $plan->clearPendingGamificationMessage();

        if ($type === self::EVENT_MEASURE) {
            return $this->resolveMeasureMessage($plan, $sourceMeasureId, $key);
        }

        return $this->messageCatalog->translate($key, $type);
    }

    /**
     * @return array{key:string,type:string,text:string}|null
     */
    private function chooseAndQueue(
        Plan $plan,
        ?PlanMeasure $planMeasure,
        string $event,
        ?string $level = null,
        ?string $excludedKey = null,
        bool $replacePending = false,
    ): ?array {
        if ($plan->hasPendingGamificationMessage() && !$replacePending) {
            return null;
        }

        $message = $this->messageCatalog->choose($event, $level, $excludedKey);
        $sourceMeasureId = $planMeasure?->getMeasure()?->getId();
        if ($replacePending) {
            $plan->replacePendingGamificationMessage($message['key'], $message['type'], $sourceMeasureId);
        } else {
            $plan->queueGamificationMessage($message['key'], $message['type'], $sourceMeasureId);
        }

        return $message;
    }

    /**
     * @return array{key:string,type:string,text:string}|null
     */
    private function queueMeasureMessage(Plan $plan, PlanMeasure $planMeasure): ?array
    {
        if ($plan->hasPendingGamificationMessage()) {
            return null;
        }

        $measure = $planMeasure->getMeasure();
        $measureId = $measure?->getId();
        $text = trim((string) $measure?->getGamificationMessage());
        if ($measureId === null || $text === '') {
            return null;
        }

        $key = 'measure.' . $measureId;
        $plan->queueGamificationMessage($key, self::EVENT_MEASURE, $measureId);

        return ['key' => $key, 'type' => self::EVENT_MEASURE, 'text' => $text];
    }

    /**
     * @return array{key:string,type:string,text:string}|null
     */
    private function resolveMeasureMessage(Plan $plan, ?int $sourceMeasureId, string $key): ?array
    {
        if ($sourceMeasureId === null) {
            return null;
        }

        foreach ($plan->getPlanMeasures() as $planMeasure) {
            $measure = $planMeasure->getMeasure();
            if ($measure?->getId() !== $sourceMeasureId) {
                continue;
            }

            $text = trim((string) $measure->getGamificationMessage());

            return $text === ''
                ? null
                : ['key' => $key, 'type' => self::EVENT_MEASURE, 'text' => $text];
        }

        return null;
    }
}
