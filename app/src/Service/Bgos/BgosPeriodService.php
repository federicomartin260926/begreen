<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\Project;
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
    ) {
    }

    public function build(
        Project $project,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        \DateTimeInterface $today,
        bool $includeAllEmissions = false,
    ): array {
        return $this->assembler->build(
            $project,
            $this->catalog->categories(),
            $this->configsByIdentity($project),
            $this->dailyRecordsForProject($project),
            $periodStart,
            $periodEnd,
            $today,
            $includeAllEmissions,
        );
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
