<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BgosCrewTransportSegment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BgosCrewTransportSegment>
 */
final class BgosCrewTransportSegmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BgosCrewTransportSegment::class);
    }
}
