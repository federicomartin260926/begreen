<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Exception\PlanMeasureLockTimeoutException;
use App\Repository\PlanMeasureRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class PlanMeasureGetOrCreate
{
    private const LOCK_PREFIX = 'begreen:plan-measures:';

    public function __construct(
        private readonly PlanMeasureRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly int $lockTimeoutSeconds = 2,
    ) {
    }

    public function getOrCreate(Plan $plan, Measure $measure): PlanMeasure
    {
        $measureId = $measure->getId();
        if ($measureId === null) {
            throw new \LogicException('Cannot create a PlanMeasure for a non-persisted Measure.');
        }

        return $this->getOrCreateMany($plan, [$measure])[$measureId];
    }

    /**
     * Uses at most one database lock for the complete batch.
     *
     * @param iterable<Measure> $measures
     * @return array<int, PlanMeasure> keyed by measure ID
     */
    public function getOrCreateMany(Plan $plan, iterable $measures): array
    {
        $requested = $this->normalizeMeasures($measures);
        if ($requested === []) {
            return [];
        }

        $resolved = $this->resolveExisting($plan, $requested);
        $missing = array_diff_key($requested, $resolved);
        if ($missing === []) {
            return $resolved;
        }

        $planId = $plan->getId();
        if ($planId === null) {
            throw new \LogicException('Cannot lock PlanMeasure creation for a non-persisted Plan.');
        }

        $lockName = self::LOCK_PREFIX.$planId;
        $acquired = (int) $this->connection->fetchOne(
            'SELECT GET_LOCK(?, ?)',
            [$lockName, $this->lockTimeoutSeconds]
        ) === 1;
        if (!$acquired) {
            throw new PlanMeasureLockTimeoutException('The sustainability plan is being updated by another request.');
        }

        try {
            // A concurrent request may have inserted any missing relation while
            // this request waited. Always query the database again under lock.
            $resolved = array_replace($resolved, $this->resolveFromDatabase($plan, $missing));
            $created = false;

            foreach (array_diff_key($missing, $resolved) as $measureId => $measure) {
                $planMeasure = (new PlanMeasure())->setMeasure($measure);
                $plan->addPlanMeasure($planMeasure);
                $this->entityManager->persist($planMeasure);
                $resolved[$measureId] = $planMeasure;
                $created = true;
            }

            // The insert must be visible before RELEASE_LOCK; persist() alone is
            // insufficient because another connection could otherwise create it.
            if ($created) {
                $this->entityManager->flush();
            }
        } finally {
            try {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {
                // The connection releases advisory locks when it closes. Do not
                // replace the original application result with a cleanup error.
            }
        }

        return $resolved;
    }

    /** @param iterable<Measure> $measures @return array<int, Measure> */
    private function normalizeMeasures(iterable $measures): array
    {
        $normalized = [];
        foreach ($measures as $measure) {
            if (!$measure instanceof Measure || $measure->getId() === null) {
                continue;
            }
            $normalized[$measure->getId()] = $measure;
        }

        return $normalized;
    }

    /**
     * @param array<int, Measure> $measures
     * @return array<int, PlanMeasure>
     */
    private function resolveExisting(Plan $plan, array $measures): array
    {
        $resolved = $this->indexRelations(PlanMeasureDuplicateResolver::unique($plan->getPlanMeasures()), $measures);
        $missing = array_diff_key($measures, $resolved);

        return $missing === []
            ? $resolved
            : array_replace($resolved, $this->resolveFromDatabase($plan, $missing));
    }

    /**
     * @param array<int, Measure> $measures
     * @return array<int, PlanMeasure>
     */
    private function resolveFromDatabase(Plan $plan, array $measures): array
    {
        $resolved = $this->indexRelations(
            PlanMeasureDuplicateResolver::unique(
                $this->repository->findAllForPlanAndMeasures($plan, array_values($measures))
            ),
            $measures
        );

        // The collection may already be initialized and therefore stale after
        // waiting for another connection. Keep the aggregate consistent with
        // the relation that was just hydrated from the database.
        foreach ($resolved as $relation) {
            $plan->addPlanMeasure($relation);
        }

        return $resolved;
    }

    /**
     * @param iterable<PlanMeasure> $relations
     * @param array<int, Measure> $requested
     * @return array<int, PlanMeasure>
     */
    private function indexRelations(iterable $relations, array $requested): array
    {
        $indexed = [];
        foreach ($relations as $relation) {
            $measureId = $relation->getMeasure()?->getId();
            if ($measureId !== null && isset($requested[$measureId])) {
                $indexed[$measureId] = $relation;
            }
        }

        return $indexed;
    }
}
