<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * Devuelve la fase (ProjectPhaseDate) correspondiente a una fecha dentro de un proyecto.
     *
     * @param Project $project
     * @param \DateTimeImmutable $date
     * @return ProjectPhaseDate|null
     */
    public function findPhaseByDate(Project $project, \DateTimeImmutable $date): ?ProjectPhaseDate
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('ppd')
        ->from(ProjectPhaseDate::class, 'ppd')
        ->where('ppd.project = :project')
        ->andWhere('ppd.startDate <= :date')
        ->andWhere('ppd.endDate >= :date')
        ->setParameter('project', $project)
        ->setParameter('date', $date)
        ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    //    /**
    //     * @return Project[] Returns an array of Project objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Project
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
