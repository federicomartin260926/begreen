<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\Project;

final class BgosPeriodWindowResolver
{
    public const VIEW_DAY = 'day';
    public const VIEW_WEEK = 'week';
    public const VIEW_MONTH = 'month';
    public const VIEW_TOTAL = 'total';

    public const VIEWS = [
        self::VIEW_DAY,
        self::VIEW_WEEK,
        self::VIEW_MONTH,
        self::VIEW_TOTAL,
    ];

    public function resolve(
        Project $project,
        string $view,
        ?\DateTimeInterface $selectedDate,
        \DateTimeInterface $today,
    ): ?BgosPeriodWindow {
        [$projectStart, $projectEnd] = $this->projectRange($project);

        if (null === $projectStart || null === $projectEnd) {
            return null;
        }

        $today = $this->normalizeDate($today);

        if (!in_array($view, self::VIEWS, true)) {
            $view = self::VIEW_DAY;
        }

        $selected = null === $selectedDate
            ? $this->defaultSelectedDate($today, $projectStart, $projectEnd)
            : $this->normalizeDate($selectedDate);

        return match ($view) {
            self::VIEW_WEEK => $this->week($selected),
            self::VIEW_MONTH => $this->month($selected),
            self::VIEW_TOTAL => new BgosPeriodWindow(
                view: self::VIEW_TOTAL,
                selectedDate: $projectStart,
                startDate: $projectStart,
                endDate: $projectEnd,
                previousDate: null,
                nextDate: null,
            ),
            default => $this->day($selected),
        };
    }

    private function day(\DateTimeImmutable $selected): BgosPeriodWindow
    {
        return new BgosPeriodWindow(
            view: self::VIEW_DAY,
            selectedDate: $selected,
            startDate: $selected,
            endDate: $selected,
            previousDate: $selected->modify('-1 day'),
            nextDate: $selected->modify('+1 day'),
        );
    }

    private function week(\DateTimeImmutable $selected): BgosPeriodWindow
    {
        return new BgosPeriodWindow(
            view: self::VIEW_WEEK,
            selectedDate: $selected,
            startDate: $selected,
            endDate: $selected->modify('+6 days'),
            previousDate: $selected->modify('-7 days'),
            nextDate: $selected->modify('+7 days'),
        );
    }

    private function month(\DateTimeImmutable $selected): BgosPeriodWindow
    {
        $start = $selected->modify('first day of this month');
        $end = $selected->modify('last day of this month');

        return new BgosPeriodWindow(
            view: self::VIEW_MONTH,
            selectedDate: $start,
            startDate: $start,
            endDate: $end,
            previousDate: $start->modify('first day of previous month'),
            nextDate: $start->modify('first day of next month'),
        );
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function projectRange(Project $project): array
    {
        $start = null;
        $end = null;

        foreach ($project->getPhaseDates() as $phase) {
            $phaseStart = $phase->getStartDate();
            $phaseEnd = $phase->getEndDate();

            if (null === $phaseStart || null === $phaseEnd) {
                continue;
            }

            $phaseStart = $this->normalizeDate($phaseStart);
            $phaseEnd = $this->normalizeDate($phaseEnd);

            if (null === $start || $phaseStart < $start) {
                $start = $phaseStart;
            }

            if (null === $end || $phaseEnd > $end) {
                $end = $phaseEnd;
            }
        }

        return [$start, $end];
    }

    private function defaultSelectedDate(
        \DateTimeImmutable $today,
        \DateTimeImmutable $projectStart,
        \DateTimeImmutable $projectEnd,
    ): \DateTimeImmutable {
        if ($today < $projectStart) {
            return $projectStart;
        }

        if ($today > $projectEnd) {
            return $projectEnd;
        }

        return $today;
    }

    private function normalizeDate(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }
}
