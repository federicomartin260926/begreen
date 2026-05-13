<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectSubscription>
 */
class ProjectSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectSubscription::class);
    }

    public function findOneByProject(Project $project): ?ProjectSubscription
    {
        return $this->findOneBy(['project' => $project]);
    }
}
