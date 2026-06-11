<?php

namespace App\Repository;

use App\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /**
     * Devuelve un QB filtrado por projectType:
     * - 'rodaje'  => departments con projectType 'rodaje' o NULL (genérico)
     * - 'evento'  => departments con projectType 'evento' o NULL (genérico)
     * - null      => todos
     */
    public function qbForProjectType(?string $projectType): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->orderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.name', 'ASC');

        if ($projectType === 'rodaje' || $projectType === 'evento') {
            $qb->andWhere('d.projectType = :pt OR d.projectType IS NULL')
               ->setParameter('pt', $projectType);
        }

        return $qb;
    }

    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.projectType = :t')
            ->setParameter('t', $type)
            ->orderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
