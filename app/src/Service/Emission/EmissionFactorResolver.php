<?php

namespace App\Service\Emission;

use App\Repository\EmissionFactorRepository;

final readonly class EmissionFactorResolver
{
    public function __construct(
        private EmissionFactorRepository $repository,
        private EmissionFactorKeyGenerator $keyGenerator,
    ) {
    }

    /** @param array<string, mixed> $criteria */
    public function resolve(string $categoryKey, array $criteria, int $activityYear): EmissionFactorResolution
    {
        return $this->resolveByFunctionalKey(
            $categoryKey,
            $this->keyGenerator->generate($criteria),
            $activityYear,
        );
    }

    public function resolveByFunctionalKey(
        string $categoryKey,
        string $functionalKey,
        int $activityYear,
    ): EmissionFactorResolution {
        $factor = $this->repository->findForActivityYear($categoryKey, $functionalKey, $activityYear);
        if (null === $factor) {
            return new EmissionFactorResolution(null, $activityYear, null, false, null);
        }

        $isFallback = $factor->getYear() < $activityYear;

        return new EmissionFactorResolution(
            $factor,
            $activityYear,
            $factor->getYear(),
            $isFallback,
            $isFallback ? EmissionFactorResolution::FALLBACK_REASON_EXACT_YEAR_MISSING : null,
            $factor->getTemporalType(),
        );
    }

    /** @param array<string, mixed> $criteria */
    public function resolveVersioned(string $categoryKey, array $criteria, int $activityYear): EmissionFactorResolution
    {
        $factor = $this->repository->findVersioned($categoryKey, $this->keyGenerator->generate($criteria));

        return new EmissionFactorResolution(
            $factor,
            $activityYear,
            null,
            false,
            null,
            \App\Entity\EmissionFactor::TEMPORAL_TYPE_VERSIONED,
        );
    }
}
