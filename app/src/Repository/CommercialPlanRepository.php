<?php

namespace App\Repository;

use App\Entity\CommercialPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommercialPlan>
 */
class CommercialPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialPlan::class);
    }

    public function findActiveByCode(string $code): ?CommercialPlan
    {
        return $this->findOneBy([
            'code' => $code,
            'active' => true,
        ]);
    }

    /**
     * @return CommercialPlan[]
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
