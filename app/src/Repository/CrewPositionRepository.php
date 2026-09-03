<?php

namespace App\Repository;

use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CrewPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrewPosition::class);
    }

    /** @return CrewPosition[] */
    public function findByCrewDepartment(CrewDepartment $crewDepartment): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.crewDepartment = :department')
            ->setParameter('department', $crewDepartment)
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextSortOrderForDepartment(CrewDepartment $crewDepartment): int
    {
        $max = (int) $this->createQueryBuilder('p')
            ->select('COALESCE(MAX(p.sortOrder), 0)')
            ->andWhere('p.crewDepartment = :department')
            ->setParameter('department', $crewDepartment)
            ->getQuery()
            ->getSingleScalarResult();

        return $max + 10;
    }
}
