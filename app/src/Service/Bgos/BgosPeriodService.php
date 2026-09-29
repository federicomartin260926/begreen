<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\Project;
use App\Repository\BgosCrewTransportDayRepository;
use App\Repository\BgosCrewTransportJourneyRepository;
use App\Repository\BgosSubcategoryConfigRepository;
use App\Repository\EmissionRecordRepository;

final class BgosPeriodService
{
    public function __construct(
        private readonly EmissionRecordRepository $recordRepository,
        private readonly BgosSubcategoryConfigRepository $configRepository,
        private readonly BgosSubcategoryCatalog $catalog,
        private readonly BgosEmissionRecordTemporalMapper $temporalMapper,
        private readonly BgosDailyRecordProjector $dailyProjector,
        private readonly BgosPeriodAssembler $assembler,
        private readonly BgosCrewTransportDayRepository $crewTransportDayRepository,
        private readonly BgosCrewTransportJourneyRepository $crewTransportJourneyRepository,
        private readonly BgosCrewTransportCompletionService $crewTransportCompletionService,
        private readonly BgosCrewRosterService $crewRosterService,
        private readonly BgosCompletionAggregator $completionAggregator,
    ) {
    }

    public function build(
        Project $project,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        \DateTimeInterface $today,
        bool $includeAllEmissions = false,
    ): array {
        $period = $this->assembler->build(
            $project,
            $this->catalog->categories(),
            $this->configsByIdentity($project),
            $this->dailyRecordsForProject($project),
            $periodStart,
            $periodEnd,
            $today,
            $includeAllEmissions,
        );

        $crewDays = $this->crewTransportDayRepository->findByProjectAndPeriod(
            $project,
            $periodStart,
            $periodEnd,
        );
        $crewJourneys = $this->crewTransportJourneyRepository->findForProjectAndPeriod(
            $project,
            $periodStart,
            $periodEnd,
        );

        $crewDaysByDate = $this->indexByDate($crewDays);
        $crewJourneysByDate = $this->indexByDate($crewJourneys);
        $todayDate = \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
        $futureCompletion = $this->completionAggregator->aggregate([
            new BgosCompletionResult(
                BgosCompletionResult::STATUS_FUTURE,
                0,
                0,
                0,
            ),
        ]);

        $crewSummary = $this->crewTransportCompletionService->summarize(
            $crewDays,
            $crewJourneys,
        );

        $crewCompletion = $this->completionAggregator->aggregate([
            new BgosCompletionResult(
                $crewSummary['status'],
                $crewSummary['expectedCount'],
                $crewSummary['completedCount'],
                $crewSummary['pendingCount'],
            ),
        ]);

        foreach ($period['categories'] as &$category) {
            if ('transport' !== $category['key']) {
                continue;
            }

            foreach ($category['subcategories'] as &$subcategory) {
                if ('people' !== $subcategory['key']) {
                    continue;
                }

                $subcategory['crewTracking'] = $crewSummary;

                if ($subcategory['active']) {
                    $subcategory['completion'] = $crewCompletion;
                    $subcategory['trackingStatus'] = $crewSummary['status'];

                    foreach ($period['calendarDays'] as $calendarDay) {
                        $dateKey = $calendarDay['key'];

                        if ($calendarDay['date'] > $todayDate) {
                            $subcategory['dailyBreakdown'][$dateKey]['completion'] =
                                $futureCompletion;
                            $subcategory['dailyBreakdown'][$dateKey]['trackingStatus'] =
                                BgosCompletionResult::STATUS_FUTURE;

                            continue;
                        }

                        $dailyCrewSummary = $this->crewTransportCompletionService->summarize(
                            $crewDaysByDate[$dateKey] ?? [],
                            $crewJourneysByDate[$dateKey] ?? [],
                        );

                        $subcategory['dailyBreakdown'][$dateKey]['completion'] =
                            $this->completionAggregator->aggregate([
                                new BgosCompletionResult(
                                    $dailyCrewSummary['status'],
                                    $dailyCrewSummary['expectedCount'],
                                    $dailyCrewSummary['completedCount'],
                                    $dailyCrewSummary['pendingCount'],
                                ),
                            ]);
                        $subcategory['dailyBreakdown'][$dateKey]['trackingStatus'] =
                            $dailyCrewSummary['status'];
                    }
                }
            }
            unset($subcategory);

            $categoryResults = [];

            foreach ($category['subcategories'] as $subcategory) {
                $completion = $subcategory['completion'];

                $categoryResults[] = new BgosCompletionResult(
                    $completion->status,
                    $completion->expectedCount,
                    $completion->completedCount,
                    $completion->pendingCount,
                );
            }

            $category['completion'] = $this->completionAggregator->aggregate(
                $categoryResults
            );
        }
        unset($category);

        $period['crewRoster'] = $this->crewRosterService->build($project);

        return $period;
    }

    /** @return array<string, list<object>> */
    private function indexByDate(iterable $items): array
    {
        $indexed = [];

        foreach ($items as $item) {
            $date = $item->getDate();

            if (!$date instanceof \DateTimeInterface) {
                continue;
            }

            $indexed[$date->format('Y-m-d')][] = $item;
        }

        return $indexed;
    }

    /**
     * @return array<string, array<string, BgosSubcategoryConfig>>
     */
    private function configsByIdentity(Project $project): array
    {
        $result = [];

        foreach ($this->configRepository->findBy(['project' => $project]) as $config) {
            $result[$config->getCategoryKey()][$config->getSubcategoryKey()] = $config;
        }

        return $result;
    }

    /**
     * @return list<BgosDailyRecord>
     */
    private function dailyRecordsForProject(Project $project): array
    {
        $result = [];

        foreach ($this->recordRepository->findByProjectOrderByPhaseAndDate($project) as $record) {
            $temporalRecord = $this->temporalMapper->map($record);

            if (null === $temporalRecord) {
                continue;
            }

            array_push(
                $result,
                ...$this->dailyProjector->project($temporalRecord),
            );
        }

        return $result;
    }
}
