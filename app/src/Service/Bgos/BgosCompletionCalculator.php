<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\ProjectPhaseDate;

final class BgosCompletionCalculator
{
    /**
     * @param iterable<BgosDailyRecord> $records
     */
    public function calculate(
        BgosSubcategoryConfig $config,
        ProjectPhaseDate $phase,
        \DateTimeInterface $periodStart,
        \DateTimeInterface $periodEnd,
        \DateTimeInterface $today,
        iterable $records,
    ): ?BgosCompletionResult {
        $periodStart = $this->normalizeDate($periodStart);
        $periodEnd = $this->normalizeDate($periodEnd);
        $today = $this->normalizeDate($today);

        if ($periodEnd < $periodStart) {
            throw new \InvalidArgumentException('BGoS completion period end cannot be before start.');
        }

        $phaseKey = $phase->getPhase();
        $phaseStartRaw = $phase->getStartDate();
        $phaseEndRaw = $phase->getEndDate();

        if (
            !is_string($phaseKey)
            || null === $phaseStartRaw
            || null === $phaseEndRaw
        ) {
            return null;
        }

        $frequency = $this->frequencyForPhase($config, $phaseKey);
        if (null === $frequency) {
            return null;
        }

        if (!$config->isActive() || BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE === $frequency) {
            return new BgosCompletionResult(
                BgosCompletionResult::STATUS_NOT_APPLICABLE,
                0,
                0,
                0,
            );
        }

        $phaseStart = $this->normalizeDate($phaseStartRaw);
        $phaseEnd = $this->normalizeDate($phaseEndRaw);

        if ($phaseEnd < $phaseStart) {
            return null;
        }

        $intersectionStart = $phaseStart > $periodStart ? $phaseStart : $periodStart;
        $intersectionEnd = $phaseEnd < $periodEnd ? $phaseEnd : $periodEnd;

        if ($intersectionEnd < $intersectionStart) {
            return new BgosCompletionResult(
                BgosCompletionResult::STATUS_NOT_APPLICABLE,
                0,
                0,
                0,
            );
        }

        if ($intersectionStart > $today) {
            return new BgosCompletionResult(
                BgosCompletionResult::STATUS_FUTURE,
                0,
                0,
                0,
            );
        }

        $effectiveEnd = $intersectionEnd < $today ? $intersectionEnd : $today;

        $matchingDates = $this->matchingDates(
            $config,
            $phaseKey,
            $intersectionStart,
            $effectiveEnd,
            $records,
        );

        return match ($frequency) {
            BgosSubcategoryConfig::FREQUENCY_DAILY => $this->dailyResult(
                $intersectionStart,
                $effectiveEnd,
                $matchingDates,
            ),
            BgosSubcategoryConfig::FREQUENCY_PUNCTUAL => $this->punctualResult(
                $matchingDates,
            ),
            default => null,
        };
    }

    /**
     * @param iterable<BgosDailyRecord> $records
     * @return array<string, true>
     */
    private function matchingDates(
        BgosSubcategoryConfig $config,
        string $phaseKey,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        iterable $records,
    ): array {
        $dates = [];

        foreach ($records as $record) {
            if (
                $record->categoryKey !== $config->getCategoryKey()
                || $record->subcategoryKey !== $config->getSubcategoryKey()
                || $record->phaseKey !== $phaseKey
            ) {
                continue;
            }

            $date = $this->normalizeDate($record->date);
            if ($date < $startDate || $date > $endDate) {
                continue;
            }

            $dates[$date->format('Y-m-d')] = true;
        }

        return $dates;
    }

    /**
     * @param array<string, true> $matchingDates
     */
    private function dailyResult(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        array $matchingDates,
    ): BgosCompletionResult {
        $expectedCount = (int) $startDate->diff($endDate)->days + 1;
        $completedCount = count($matchingDates);
        $pendingCount = $expectedCount - $completedCount;

        return new BgosCompletionResult(
            0 === $pendingCount
                ? BgosCompletionResult::STATUS_COMPLETE
                : BgosCompletionResult::STATUS_PENDING,
            $expectedCount,
            $completedCount,
            $pendingCount,
        );
    }

    /**
     * @param array<string, true> $matchingDates
     */
    private function punctualResult(array $matchingDates): BgosCompletionResult
    {
        if ([] !== $matchingDates) {
            return new BgosCompletionResult(
                BgosCompletionResult::STATUS_COMPLETE,
                1,
                1,
                0,
            );
        }

        return new BgosCompletionResult(
            BgosCompletionResult::STATUS_NO_DATA,
            1,
            0,
            0,
        );
    }

    private function frequencyForPhase(
        BgosSubcategoryConfig $config,
        string $phaseKey,
    ): ?string {
        return match ($phaseKey) {
            'preproduccion' => $config->getPreproductionFrequency(),
            'actividad' => $config->getActivityFrequency(),
            'postproduccion' => $config->getPostproductionFrequency(),
            default => null,
        };
    }

    private function normalizeDate(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }
}
