<?php

namespace App\Repository;

use App\Entity\ProjectSubscription;
use App\Entity\Project;
use App\Enum\CommercialPhase;
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

    public function findOneByProjectAndPhase(Project $project, CommercialPhase $phase): ?ProjectSubscription
    {
        return $this->findOneBy([
            'project' => $project,
            'phase' => $phase,
        ]);
    }

    public function findOneByStripeCheckoutSessionId(string $sessionId): ?ProjectSubscription
    {
        return $this->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
    }
}
