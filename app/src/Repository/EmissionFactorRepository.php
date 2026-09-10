<?php

namespace App\Repository;

use App\Entity\EmissionFactor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
            ->andWhere('factor.temporalType IN (:temporalTypes)')
            ->andWhere('(factor.activityYear IS NULL OR factor.activityYear <= :activityYear)')
            ->andWhere('factor.year <= :activityYear')
            ->setParameter('categoryKey', $categoryKey)
            ->setParameter('functionalKey', $functionalKey)
            ->setParameter('temporalTypes', [
                EmissionFactor::TEMPORAL_TYPE_ANNUAL,
                EmissionFactor::TEMPORAL_TYPE_VERSIONED,
            ])
            ->setParameter('activityYear', $activityYear)
            ->orderBy('factor.activityYear', 'DESC')
            ->addOrderBy('factor.year', 'DESC')
            ->addOrderBy('factor.factorId', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findVersioned(string $categoryKey, string $functionalKey): ?EmissionFactor
    {
        return $this->findMethodological(
            $categoryKey,
            $functionalKey,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED,
        );
    }

    public function findMethodological(
        string $categoryKey,
        string $functionalKey,
        string $temporalType,
    ): ?EmissionFactor
    {
        return $this->createQueryBuilder('factor')
            ->andWhere('factor.categoryKey = :categoryKey')
            ->andWhere('factor.functionalKey = :functionalKey')
            ->andWhere('factor.temporalType = :temporalType')
            ->setParameter('categoryKey', $categoryKey)
            ->setParameter('functionalKey', $functionalKey)
            ->setParameter('temporalType', $temporalType)
            ->orderBy('factor.factorId', 'ASC')
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

    /**
     * @param array{categoryKey?: string, temporalType?: string, year?: int, source?: string} $filters
     * @return Paginator<EmissionFactor>
     */
    public function findAdminPage(array $filters, int $page, int $perPage): Paginator
    {
        $queryBuilder = $this->createQueryBuilder('factor')
            ->orderBy('factor.categoryKey', 'ASC')
            ->addOrderBy('factor.functionalKey', 'ASC')
            ->addOrderBy('factor.year', 'DESC')
            ->addOrderBy('factor.id', 'ASC');

        if (isset($filters['categoryKey']) && '' !== $filters['categoryKey']) {
            $queryBuilder
                ->andWhere('factor.categoryKey = :adminCategoryKey')
                ->setParameter('adminCategoryKey', $filters['categoryKey']);
        }
        if (isset($filters['temporalType']) && '' !== $filters['temporalType']) {
            $queryBuilder
                ->andWhere('factor.temporalType = :adminTemporalType')
                ->setParameter('adminTemporalType', $filters['temporalType']);
        }
        if (isset($filters['year'])) {
            $queryBuilder
                ->andWhere('factor.year = :adminYear')
                ->setParameter('adminYear', $filters['year']);
        }
        if (isset($filters['source']) && '' !== $filters['source']) {
            $queryBuilder
                ->andWhere('LOWER(factor.source) LIKE LOWER(:adminSource)')
                ->setParameter('adminSource', '%'.$filters['source'].'%');
        }
        $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($queryBuilder->getQuery());
    }

    /** @return list<string> */
    public function findDistinctCategoryKeys(): array
    {
        $rows = $this->createQueryBuilder('factor')
            ->select('DISTINCT factor.categoryKey AS categoryKey')
            ->orderBy('factor.categoryKey', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'categoryKey');
    }

    public function findIdentityCollision(
        string $categoryKey,
        string $functionalKey,
        ?int $year,
        ?int $excludedId = null,
    ): ?EmissionFactor {
        if (null === $year) {
            return null;
        }

        $queryBuilder = $this->createQueryBuilder('factor')
            ->andWhere('factor.categoryKey = :collisionCategoryKey')
            ->andWhere('factor.functionalKey = :collisionFunctionalKey')
            ->andWhere('factor.year = :collisionYear')
            ->setParameter('collisionCategoryKey', $categoryKey)
            ->setParameter('collisionFunctionalKey', $functionalKey)
            ->setParameter('collisionYear', $year)
            ->setMaxResults(1);

        if (null !== $excludedId) {
            $queryBuilder
                ->andWhere('factor.id != :collisionExcludedId')
                ->setParameter('collisionExcludedId', $excludedId);
        }

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }
}
