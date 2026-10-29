<?php

namespace App\Tests\Service;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Exception\PlanMeasureLockTimeoutException;
use App\Repository\PlanMeasureRepository;
use App\Service\PlanMeasureGetOrCreate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PlanMeasureGetOrCreateTest extends TestCase
{
    public function testReusesExistingDatabaseRelationWithoutLocking(): void
    {
        $plan = $this->plan(10);
        $measure = $this->measure(20);
        $existing = (new PlanMeasure())->setPlan($plan)->setMeasure($measure);

        $repository = $this->createMock(PlanMeasureRepository::class);
        $repository->expects(self::once())->method('findAllForPlanAndMeasures')->willReturn([$existing]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        $result = (new PlanMeasureGetOrCreate($repository, $entityManager, $connection))->getOrCreate($plan, $measure);

        self::assertSame($existing, $result);
    }

    public function testCreatesAndFlushesMissingRelationBeforeReleasingLock(): void
    {
        $plan = $this->plan(10);
        $measure = $this->measure(20);
        $repository = $this->createMock(PlanMeasureRepository::class);
        $repository->expects(self::exactly(2))->method('findAllForPlanAndMeasures')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects(self::once())->method('persist')
            ->willReturnCallback(static function (PlanMeasure $relation) use (&$persisted): void {
                $persisted = $relation;
            });
        $entityManager->expects(self::once())->method('flush');
        $connection = $this->createMock(Connection::class);
        $calls = 0;
        $connection->expects(self::exactly(2))->method('fetchOne')
            ->willReturnCallback(static function (string $sql) use (&$calls): int {
                self::assertSame($calls++ === 0 ? 'SELECT GET_LOCK(?, ?)' : 'SELECT RELEASE_LOCK(?)', $sql);

                return 1;
            });

        $result = (new PlanMeasureGetOrCreate($repository, $entityManager, $connection))->getOrCreate($plan, $measure);

        self::assertSame($persisted, $result);
        self::assertSame($plan, $result->getPlan());
        self::assertSame($measure, $result->getMeasure());
    }

    public function testLockContentionDoesNotPersistOrFlush(): void
    {
        $plan = $this->plan(10);
        $measure = $this->measure(20);
        $repository = $this->createMock(PlanMeasureRepository::class);
        $repository->expects(self::once())->method('findAllForPlanAndMeasures')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')
            ->with('SELECT GET_LOCK(?, ?)', ['begreen:plan-measures:10', 0])
            ->willReturn(0);

        $service = new PlanMeasureGetOrCreate($repository, $entityManager, $connection, 0);

        $this->expectException(PlanMeasureLockTimeoutException::class);
        $service->getOrCreate($plan, $measure);
    }

    private function plan(int $id): Plan
    {
        $plan = new Plan();
        $this->setId($plan, $id);

        return $plan;
    }

    private function measure(int $id): Measure
    {
        $measure = new Measure();
        $this->setId($measure, $id);

        return $measure;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }
}
