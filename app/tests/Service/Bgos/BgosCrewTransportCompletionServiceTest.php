<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosCrewTransportDay;
use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Service\Bgos\BgosCompletionResult;
use App\Service\Bgos\BgosCrewTransportCompletionService;
use PHPUnit\Framework\TestCase;

final class BgosCrewTransportCompletionServiceTest extends TestCase
{
    public function testSummarizesPeopleByDepartment(): void
    {
        $production = (new CrewDepartment())
            ->setName('Producción')
            ->setScope(CrewDepartment::SCOPE_FILMING)
            ->setSortOrder(10);

        $camera = (new CrewDepartment())
            ->setName('Cámara')
            ->setScope(CrewDepartment::SCOPE_FILMING)
            ->setSortOrder(20);

        $days = [
            $this->day('Ana', $production, BgosCrewTransportDay::STATUS_RESOLVED),
            $this->day('Luis', $production, BgosCrewTransportDay::STATUS_PENDING),
            $this->day('Marta', $camera, BgosCrewTransportDay::STATUS_RESOLVED),
            $this->day('Pedro', $camera, BgosCrewTransportDay::STATUS_NOT_APPLICABLE),
        ];

        $summary = (new BgosCrewTransportCompletionService())->summarize($days);

        self::assertSame(3, $summary['expectedCount']);
        self::assertSame(2, $summary['completedCount']);
        self::assertSame(1, $summary['pendingCount']);
        self::assertSame(1, $summary['notApplicableCount']);
        self::assertSame(BgosCompletionResult::STATUS_PENDING, $summary['status']);

        self::assertCount(2, $summary['departments']);

        self::assertSame('Producción', $summary['departments'][0]['department']?->getName());
        self::assertSame(2, $summary['departments'][0]['expectedCount']);
        self::assertSame(1, $summary['departments'][0]['completedCount']);
        self::assertSame(1, $summary['departments'][0]['pendingCount']);

        self::assertSame('Cámara', $summary['departments'][1]['department']?->getName());
        self::assertSame(1, $summary['departments'][1]['expectedCount']);
        self::assertSame(1, $summary['departments'][1]['completedCount']);
        self::assertSame(0, $summary['departments'][1]['pendingCount']);
        self::assertSame(1, $summary['departments'][1]['notApplicableCount']);
        self::assertSame(BgosCompletionResult::STATUS_COMPLETE, $summary['departments'][1]['status']);
    }

    private function day(
        string $name,
        CrewDepartment $department,
        string $status,
    ): BgosCrewTransportDay {
        $member = (new CrewMember())->setName($name);

        $assignment = (new CrewMemberAssignment())
            ->setCrewDepartment($department);

        $member->addAssignment($assignment);

        return (new BgosCrewTransportDay())
            ->setCrewMember($member)
            ->setDate(new \DateTimeImmutable('2026-09-18'))
            ->setCrewAssignment($assignment)
            ->setStatus($status);
    }
}
