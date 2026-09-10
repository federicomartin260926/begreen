<?php

namespace App\Service\Emission;

use App\Entity\EmissionFactor;
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

        $factorYear = $factor->getYear();
        if (null === $factorYear) {
            throw new \UnexpectedValueException('Annual emission factors must define a year.');
        }
        if ($factorYear > $activityYear) {
            return new EmissionFactorResolution(null, $activityYear, null, false, null);
        }
        $metadata = $factor->getMetadata() ?? [];
        $isFallback = $factorYear < $activityYear || true === ($metadata['isTemporalFallback'] ?? false);
        $fallbackReason = is_string($metadata['fallbackReason'] ?? null)
            ? $metadata['fallbackReason']
            : EmissionFactorResolution::FALLBACK_REASON_EXACT_YEAR_MISSING;

        return new EmissionFactorResolution(
            $factor,
            $factor->getActivityYear() ?? $activityYear,
            $factorYear,
            $isFallback,
            $isFallback ? $fallbackReason : null,
            $factor->getTemporalType(),
        );
    }

    /** @param array<string, mixed> $criteria */
    public function resolveVersioned(string $categoryKey, array $criteria, int $activityYear): EmissionFactorResolution
    {
        return $this->resolveMethodological(
            $categoryKey,
            $criteria,
            $activityYear,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
        );
    }

    /** @param array<string, mixed> $criteria */
    public function resolveByApplicability(string $categoryKey, array $criteria, int $activityYear): EmissionFactorResolution
    {
        $factor = $this->repository->findForApplicabilityYear(
            $categoryKey,
            $this->keyGenerator->generate($criteria),
            $activityYear,
        );
        if (null === $factor || null === $factor->getActivityYear() || $factor->getActivityYear() > $activityYear) {
            return new EmissionFactorResolution(null, $activityYear, null, false, null);
        }

        $metadata = $factor->getMetadata() ?? [];
        $isFallback = $factor->getActivityYear() < $activityYear || true === ($metadata['isTemporalFallback'] ?? false);
        $fallbackReason = is_string($metadata['fallbackReason'] ?? null)
            ? $metadata['fallbackReason']
            : EmissionFactorResolution::FALLBACK_REASON_EXACT_YEAR_MISSING;

        return new EmissionFactorResolution(
            $factor,
            $activityYear,
            $factor->getYear(),
            $isFallback,
            $isFallback ? $fallbackReason : null,
            $factor->getTemporalType(),
        );
    }

    /** @param array<string, mixed> $criteria */
    public function resolveMethodological(
        string $categoryKey,
        array $criteria,
        int $activityYear,
        string $temporalType,
    ): EmissionFactorResolution {
        if (!in_array($temporalType, [
            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            EmissionFactor::TEMPORAL_TYPE_RULE,
            EmissionFactor::TEMPORAL_TYPE_COMPOSITE,
            EmissionFactor::TEMPORAL_TYPE_PROXY_LCA,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported methodological temporal type "%s".', $temporalType));
        }

        $factor = $this->repository->findMethodological(
            $categoryKey,
            $this->keyGenerator->generate($criteria),
            $temporalType,
        );

        return new EmissionFactorResolution(
            $factor,
            $activityYear,
            null,
            false,
            null,
            $temporalType,
        );
    }
}
