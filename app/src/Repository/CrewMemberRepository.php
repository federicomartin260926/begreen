<?php

namespace App\Repository;

use App\Entity\CrewMember;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrewMember>
 */
class CrewMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrewMember::class);
    }

    /** @return CrewMember[] */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['id' => 'ASC']);
    }
}
