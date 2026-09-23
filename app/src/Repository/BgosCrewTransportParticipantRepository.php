<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BgosCrewTransportParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BgosCrewTransportParticipant>
 */
final class BgosCrewTransportParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BgosCrewTransportParticipant::class);
    }
}
