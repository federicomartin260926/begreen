<?php

namespace App\Repository;

use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
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

    public function findActiveByPhaseAndCode(CommercialPhase $phase, string $code): ?CommercialPlan
    {
        return $this->findOneBy([
            'phase' => $phase,
            'code' => strtolower(trim($code)),
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
            ->orderBy('p.phase', 'ASC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
