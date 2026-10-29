<?php

namespace App\Tests\Integration;

use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Entity\User;
use App\Exception\PlanMeasureLockTimeoutException;
use App\Repository\PlanMeasureRepository;
use App\Service\PlanMeasureDuplicateResolver;
use App\Service\PlanMeasureGetOrCreate;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanMeasureAdvisoryLockTest extends KernelTestCase
{
    public function testRelationInsertedBySecondEntityManagerWhileAcquiringLockIsReusedAndAttached(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $primaryConnection = $entityManager->getConnection();
        if (!$primaryConnection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Concurrent EntityManager coverage requires MariaDB/MySQL.');
        }

        $secondConnection = DriverManager::getConnection($primaryConnection->getParams());
        $secondEntityManager = new EntityManager($secondConnection, $entityManager->getConfiguration());
        $createdId = null;
        $temporaryPlanId = null;
        $temporaryMeasureId = null;
        $temporaryProjectId = null;
        $temporaryProtocolId = null;
        $temporaryUserId = null;

        try {
            $suffix = bin2hex(random_bytes(6));
            $user = (new User())
                ->setName('Concurrent')
                ->setSurnames('Test')
                ->setEmail('concurrent-'.$suffix.'@example.test')
                ->setPassword('test');
            $protocol = (new Protocol())
                ->setCode('concurrent-'.$suffix)
                ->setName('Concurrent test')
                ->setType(Protocol::TYPE_RODAJE);
            $project = (new Project())
                ->setName('Concurrent test')
                ->setType(Protocol::TYPE_RODAJE)
                ->setCountry('ES')
                ->setUser($user);
            $temporaryMeasure = (new Measure())
                ->setName('Concurrent measure')
                ->setProtocol($protocol);
            $temporaryPlan = (new Plan())
                ->setProject($project)
                ->setUser($user)
                ->setProtocol($protocol);
            $secondEntityManager->persist($user);
            $secondEntityManager->persist($protocol);
            $secondEntityManager->persist($project);
            $secondEntityManager->persist($temporaryMeasure);
            $secondEntityManager->persist($temporaryPlan);
            $secondEntityManager->flush();
            $temporaryPlanId = $temporaryPlan->getId();
            $temporaryMeasureId = $temporaryMeasure->getId();
            $temporaryProjectId = $project->getId();
            $temporaryProtocolId = $protocol->getId();
            $temporaryUserId = $user->getId();
            self::assertNotNull($temporaryPlanId);
            self::assertNotNull($temporaryMeasureId);

            $plan = $entityManager->find(Plan::class, $temporaryPlanId);
            $measure = $entityManager->find(Measure::class, $temporaryMeasureId);
            self::assertInstanceOf(Plan::class, $plan);
            self::assertInstanceOf(Measure::class, $measure);
            $plan->getPlanMeasures()->toArray(); // Force the stale initialized collection.

            $repository = $this->createMock(PlanMeasureRepository::class);
            $repository->expects(self::exactly(2))->method('findAllForPlanAndMeasures')
                ->willReturnCallback(static function (Plan $queriedPlan, array $measures) use ($entityManager): array {
                    return $entityManager->createQuery(
                        'SELECT pm FROM App\\Entity\\PlanMeasure pm WHERE pm.plan = :plan AND pm.measure IN (:measures) ORDER BY pm.id ASC'
                    )
                        ->setParameter('plan', $queriedPlan)
                        ->setParameter('measures', $measures)
                        ->getResult();
                });

            $lockConnection = $this->createMock(Connection::class);
            $lockConnection->expects(self::exactly(2))->method('fetchOne')
                ->willReturnCallback(function (string $sql) use (
                    $secondEntityManager,
                    $temporaryPlanId,
                    $temporaryMeasureId,
                    &$createdId
                ): int {
                    if ($sql === 'SELECT GET_LOCK(?, ?)') {
                        // Models the other request committing while this request
                        // waits to acquire the advisory lock.
                        $otherPlan = $secondEntityManager->find(Plan::class, $temporaryPlanId);
                        $otherMeasure = $secondEntityManager->find(Measure::class, $temporaryMeasureId);
                        $created = (new PlanMeasure())->setPlan($otherPlan)->setMeasure($otherMeasure);
                        $secondEntityManager->persist($created);
                        $secondEntityManager->flush();
                        $createdId = $created->getId();

                        return 1;
                    }

                    self::assertSame('SELECT RELEASE_LOCK(?)', $sql);

                    return 1;
                });

            $service = new PlanMeasureGetOrCreate($repository, $entityManager, $lockConnection, 0);
            $resolved = $service->getOrCreate($plan, $measure);

            self::assertSame($createdId, $resolved->getId());
            self::assertSame($resolved, PlanMeasureDuplicateResolver::forMeasure($plan, $measure));
            self::assertCount(1, array_filter(
                $plan->getPlanMeasures()->toArray(),
                static fn (PlanMeasure $relation): bool => $relation->getMeasure()?->getId() === $measure->getId()
            ));
        } finally {
            $entityManager->clear();
            $secondEntityManager->clear();
            if ($createdId !== null) {
                $secondConnection->executeStatement('DELETE FROM plan_measure WHERE id = ?', [$createdId]);
            }
            if ($temporaryPlanId !== null) {
                $secondConnection->executeStatement('DELETE FROM plan WHERE id = ?', [$temporaryPlanId]);
            }
            if ($temporaryMeasureId !== null) {
                $secondConnection->executeStatement('DELETE FROM measure WHERE id = ?', [$temporaryMeasureId]);
            }
            if ($temporaryProjectId !== null) {
                $secondConnection->executeStatement('DELETE FROM project WHERE id = ?', [$temporaryProjectId]);
            }
            if ($temporaryProtocolId !== null) {
                $secondConnection->executeStatement('DELETE FROM protocol WHERE id = ?', [$temporaryProtocolId]);
            }
            if ($temporaryUserId !== null) {
                $secondConnection->executeStatement('DELETE FROM `user` WHERE id = ?', [$temporaryUserId]);
            }
            $secondEntityManager->close();
        }
    }

    public function testSecondDatabaseConnectionGetsControlledTimeout(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('GET_LOCK concurrency coverage requires MariaDB/MySQL.');
        }

        $lockName = 'begreen:plan-measures:987654321';
        $otherConnection = DriverManager::getConnection($connection->getParams());

        try {
            self::assertSame(1, (int) $otherConnection->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]));

            $plan = new Plan();
            $measure = new Measure();
            $this->setId($plan, 987654321);
            $this->setId($measure, 123456789);
            $repository = $this->createMock(PlanMeasureRepository::class);
            $repository->method('findAllForPlanAndMeasures')->willReturn([]);
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->expects(self::never())->method('persist');
            $entityManager->expects(self::never())->method('flush');

            $service = new PlanMeasureGetOrCreate($repository, $entityManager, $connection, 0);

            $this->expectException(PlanMeasureLockTimeoutException::class);
            $service->getOrCreate($plan, $measure);
        } finally {
            $otherConnection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            $otherConnection->close();
        }
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }
}
