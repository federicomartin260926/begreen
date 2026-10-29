<?php

namespace App\Repository;

use App\Entity\Measure;
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

    /**
     * @param list<Measure> $measures
     * @return list<PlanMeasure>
     */
    public function findAllForPlanAndMeasures(Plan $plan, array $measures): array
    {
        if ($measures === []) {
            return [];
        }

        return $this->createQueryBuilder('planMeasure')
            ->andWhere('planMeasure.plan = :plan')
            ->andWhere('planMeasure.measure IN (:measures)')
            ->setParameter('plan', $plan)
            ->setParameter('measures', $measures)
            ->orderBy('planMeasure.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
