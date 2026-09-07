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
            ->andWhere('factor.temporalType = :temporalType')
            ->andWhere('factor.year <= :activityYear')
            ->setParameter('categoryKey', $categoryKey)
            ->setParameter('functionalKey', $functionalKey)
            ->setParameter('temporalType', EmissionFactor::TEMPORAL_TYPE_ANNUAL)
            ->setParameter('activityYear', $activityYear)
            ->orderBy('factor.year', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<array<string, mixed>> */
    public function findCriteriaByCategoryKey(string $categoryKey): array
    {
        $rows = $this->createQueryBuilder('factor')
            ->select('factor.criteria')
            ->andWhere('factor.categoryKey = :categoryKey')
            ->setParameter('categoryKey', $categoryKey)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(
            static fn (array $row): mixed => $row['criteria'] ?? null,
            $rows,
        ), 'is_array'));
    }
}
