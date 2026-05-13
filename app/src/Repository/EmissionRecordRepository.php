<?php

namespace App\Repository;

use App\Entity\EmissionRecord;
use App\Entity\ProjectPhaseDate;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmissionRecord>
 */
class EmissionRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmissionRecord::class);
    }

    /**
     * @return EmissionRecord[]
     */
    public function findByPhaseOrdered(ProjectPhaseDate $phase): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.phase = :phase')
            ->setParameter('phase', $phase)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(EmissionRecord $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EmissionRecord $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByProjectOrderByPhaseAndDate(Project $project): array
    {
        return $this->createQueryBuilder('er')
            ->join('er.phase', 'phase')
            ->where('er.project = :project')
            ->setParameter('project', $project)
            ->orderBy('phase.startDate', 'ASC')          // Orden por nombre o código de fase
            ->addOrderBy('er.registeredAt', 'ASC')  // Luego por fecha registro
            ->getQuery()
            ->getResult();
    }
}
