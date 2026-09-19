<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BgosCrewTransportDay;
use App\Entity\CrewMember;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BgosCrewTransportDay>
 */
final class BgosCrewTransportDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BgosCrewTransportDay::class);
    }

    public function findOneByCrewMemberAndDate(
        CrewMember $crewMember,
        \DateTimeInterface $date,
    ): ?BgosCrewTransportDay {
        return $this->findOneBy([
            'crewMember' => $crewMember,
            'date' => \DateTimeImmutable::createFromInterface($date)->setTime(0, 0),
        ]);
    }

    /**
     * @return list<BgosCrewTransportDay>
     */
    public function findByProjectAndPeriod(
        Project $project,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): array {
        $start = \DateTimeImmutable::createFromInterface($start)->setTime(0, 0);
        $end = \DateTimeImmutable::createFromInterface($end)->setTime(0, 0);

        return $this->createQueryBuilder('day')
            ->join('day.crewMember', 'crewMember')
            ->andWhere('crewMember.project = :project')
            ->andWhere('day.date BETWEEN :start AND :end')
            ->setParameter('project', $project)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('day.date', 'ASC')
            ->addOrderBy('crewMember.name', 'ASC')
            ->addOrderBy('crewMember.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
