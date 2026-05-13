<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\Position;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    /** @return Position[] */
    public function findByDepartment(Department $department): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.department = :d')
            ->setParameter('d', $department)
            ->orderBy('p.name', 'ASC')
            ->getQuery()->getResult();
    }
}
