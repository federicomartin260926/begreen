<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BgosCrewProfile;
use App\Entity\CrewMember;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BgosCrewProfile>
 */
final class BgosCrewProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BgosCrewProfile::class);
    }

    public function findOneByCrewMember(CrewMember $crewMember): ?BgosCrewProfile
    {
        return $this->findOneBy(['crewMember' => $crewMember]);
    }


    /**
     * @return list<BgosCrewProfile>
     */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('profile')
            ->join('profile.crewMember', 'crewMember')
            ->andWhere('crewMember.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();
    }
}
