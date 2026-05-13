<?php

namespace App\Repository;

use App\Entity\EmissionSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmissionSource>
 *
 * @method EmissionSource|null find($id, $lockMode = null, $lockVersion = null)
 * @method EmissionSource|null findOneBy(array $criteria, array $orderBy = null)
 * @method EmissionSource[]    findAll()
 * @method EmissionSource[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EmissionSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmissionSource::class);
    }
}
