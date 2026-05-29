<?php

namespace App\Repository;

use App\Entity\Plan;
use App\Entity\SustainabilityPlanBlockAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SustainabilityPlanBlockAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SustainabilityPlanBlockAnswer::class);
    }

    /**
     * @return SustainabilityPlanBlockAnswer[]
     */
    public function findByPlan(Plan $plan): array
    {
        return $this->findBy(['sustainabilityPlan' => $plan]);
    }

    public function findOneByPlanAndBlock(Plan $plan, int $blockId): ?SustainabilityPlanBlockAnswer
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.measureBlock', 'b')
            ->andWhere('a.sustainabilityPlan = :plan')
            ->andWhere('b.id = :blockId')
            ->setParameter('plan', $plan)
            ->setParameter('blockId', $blockId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
