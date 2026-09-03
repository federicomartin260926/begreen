<?php

namespace App\Repository;

use App\Entity\CrewDepartment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CrewDepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrewDepartment::class);
    }

    /** @return CrewDepartment[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.scope', 'ASC')
            ->addOrderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return CrewDepartment[] */
    public function findByScope(string $scope): array
    {
        if (!in_array($scope, CrewDepartment::SCOPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported crew catalog scope "%s".', $scope));
        }

        return $this->createQueryBuilder('d')
            ->andWhere('d.scope = :scope')
            ->setParameter('scope', $scope)
            ->orderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextSortOrderForScope(string $scope): int
    {
        if (!in_array($scope, CrewDepartment::SCOPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported crew catalog scope "%s".', $scope));
        }

        $max = (int) $this->createQueryBuilder('d')
            ->select('COALESCE(MAX(d.sortOrder), 0)')
            ->andWhere('d.scope = :scope')
            ->setParameter('scope', $scope)
            ->getQuery()
            ->getSingleScalarResult();

        return $max + 10;
    }
}
