<?php

namespace App\Repository;

use App\Entity\Plan;
use App\Entity\PlanMeasure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanMeasure>
 */
class PlanMeasureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanMeasure::class);
    }

    public function countFirstDecisionsForPlan(Plan $plan): int
    {
        return (int) $this->createQueryBuilder('pm')
            ->select('COUNT(pm.id)')
            ->andWhere('pm.plan = :plan')
            ->andWhere('pm.firstDecisionAnsweredAt IS NOT NULL')
            ->setParameter('plan', $plan)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
