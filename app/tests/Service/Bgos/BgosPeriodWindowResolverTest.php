<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Bgos\BgosPeriodWindowResolver;
use PHPUnit\Framework\TestCase;

final class BgosPeriodWindowResolverTest extends TestCase
{
    public function testDayDefaultsToTodayWhenTodayIsInsideProject(): void
    {
        $window = $this->resolver()->resolve(
            $this->project(),
            'day',
            null,
            new \DateTimeImmutable('2026-07-21 17:30'),
        );

        self::assertNotNull($window);
        self::assertSame('2026-07-21', $window->startDate->format('Y-m-d'));
        self::assertSame('2026-07-21', $window->endDate->format('Y-m-d'));
        self::assertSame('2026-07-20', $window->previousDate?->format('Y-m-d'));
        self::assertSame('2026-07-22', $window->nextDate?->format('Y-m-d'));
    }

    public function testDefaultDateIsClampedToProjectRange(): void
    {
        $before = $this->resolver()->resolve(
            $this->project(),
            'day',
            null,
            new \DateTimeImmutable('2026-06-01'),
        );

        $after = $this->resolver()->resolve(
            $this->project(),
            'day',
            null,
            new \DateTimeImmutable('2026-09-01'),
        );

        self::assertSame('2026-07-14', $before?->selectedDate->format('Y-m-d'));
        self::assertSame('2026-08-05', $after?->selectedDate->format('Y-m-d'));
    }

    public function testWeekIsSevenDaysStartingAtSelectedDate(): void
    {
        $window = $this->resolver()->resolve(
            $this->project(),
            'week',
            new \DateTimeImmutable('2026-07-21'),
            new \DateTimeImmutable('2026-07-21'),
        );

        self::assertNotNull($window);
        self::assertSame('2026-07-21', $window->startDate->format('Y-m-d'));
        self::assertSame('2026-07-27', $window->endDate->format('Y-m-d'));
        self::assertSame('2026-07-14', $window->previousDate?->format('Y-m-d'));
        self::assertSame('2026-07-28', $window->nextDate?->format('Y-m-d'));
    }

    public function testMonthUsesWholeCalendarMonth(): void
    {
        $window = $this->resolver()->resolve(
            $this->project(),
            'month',
            new \DateTimeImmutable('2026-07-21'),
            new \DateTimeImmutable('2026-07-21'),
        );

        self::assertNotNull($window);
        self::assertSame('2026-07-01', $window->startDate->format('Y-m-d'));
        self::assertSame('2026-07-31', $window->endDate->format('Y-m-d'));
        self::assertSame('2026-06-01', $window->previousDate?->format('Y-m-d'));
        self::assertSame('2026-08-01', $window->nextDate?->format('Y-m-d'));
    }

    public function testTotalUsesFullProjectPhaseRangeAndHasNoNavigation(): void
    {
        $window = $this->resolver()->resolve(
            $this->project(),
            'total',
            new \DateTimeImmutable('2030-01-01'),
            new \DateTimeImmutable('2026-07-21'),
        );

        self::assertNotNull($window);
        self::assertSame('2026-07-14', $window->startDate->format('Y-m-d'));
        self::assertSame('2026-08-05', $window->endDate->format('Y-m-d'));
        self::assertNull($window->previousDate);
        self::assertNull($window->nextDate);
    }

    public function testInvalidViewFallsBackToDay(): void
    {
        $window = $this->resolver()->resolve(
            $this->project(),
            'invalid',
            new \DateTimeImmutable('2026-07-21'),
            new \DateTimeImmutable('2026-07-21'),
        );

        self::assertSame('day', $window?->view);
        self::assertSame('2026-07-21', $window?->startDate->format('Y-m-d'));
    }

    public function testProjectWithoutUsablePhaseDatesReturnsNull(): void
    {
        self::assertNull(
            $this->resolver()->resolve(
                new Project(),
                'day',
                null,
                new \DateTimeImmutable('2026-07-21'),
            )
        );
    }

    private function resolver(): BgosPeriodWindowResolver
    {
        return new BgosPeriodWindowResolver();
    }

    private function project(): Project
    {
        $project = new Project();

        $project->addPhaseDate(
            (new ProjectPhaseDate())
                ->setPhase('preproduccion')
                ->setStartDate(new \DateTimeImmutable('2026-07-14'))
                ->setEndDate(new \DateTimeImmutable('2026-07-20'))
        );

        $project->addPhaseDate(
            (new ProjectPhaseDate())
                ->setPhase('actividad')
                ->setStartDate(new \DateTimeImmutable('2026-07-21'))
                ->setEndDate(new \DateTimeImmutable('2026-07-31'))
        );

        $project->addPhaseDate(
            (new ProjectPhaseDate())
                ->setPhase('postproduccion')
                ->setStartDate(new \DateTimeImmutable('2026-08-01'))
                ->setEndDate(new \DateTimeImmutable('2026-08-05'))
        );

        return $project;
    }
}
