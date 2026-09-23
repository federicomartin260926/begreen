<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BgosCrewTransportJourney;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BgosCrewTransportJourney>
 */
final class BgosCrewTransportJourneyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BgosCrewTransportJourney::class);
    }

    /** @return list<BgosCrewTransportJourney> */
    public function findForProjectAndDate(Project $project, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('journey')
            ->leftJoin('journey.segments', 'segment')
            ->addSelect('segment')
            ->leftJoin('segment.participants', 'participant')
            ->addSelect('participant')
            ->leftJoin('participant.crewMember', 'crewMember')
            ->addSelect('crewMember')
            ->andWhere('journey.project = :project')
            ->andWhere('journey.date = :date')
            ->setParameter('project', $project)
            ->setParameter('date', $date->setTime(0, 0))
            ->orderBy('journey.id', 'ASC')
            ->addOrderBy('segment.position', 'ASC')
            ->addOrderBy('participant.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
