<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return array<int, Category> */
    public function findEnabledInEmissionCalculator(): array
    {
        return $this->createQueryBuilder('category')
            ->andWhere('category.enabledInEmissionCalculator = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('category.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
