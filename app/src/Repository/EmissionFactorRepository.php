<?php

namespace App\Repository;

use App\Entity\EmissionFactor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmissionFactorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmissionFactor::class);
    }

    public function findForActivityYear(
        string $categoryKey,
        string $functionalKey,
        int $activityYear,
    ): ?EmissionFactor {
        return $this->createQueryBuilder('factor')
            ->andWhere('factor.categoryKey = :categoryKey')
            ->andWhere('factor.functionalKey = :functionalKey')
            ->andWhere('factor.year <= :activityYear')
            ->setParameter('categoryKey', $categoryKey)
            ->setParameter('functionalKey', $functionalKey)
            ->setParameter('activityYear', $activityYear)
            ->orderBy('factor.year', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
