<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectCommercialPayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProjectCommercialPayment> */
final class ProjectCommercialPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectCommercialPayment::class);
    }

    public function findOneBySessionId(string $sessionId): ?ProjectCommercialPayment
    {
        return $this->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
    }

    public function findPendingForProject(Project $project): ?ProjectCommercialPayment
    {
        return $this->findOneBy(
            ['project' => $project, 'status' => ProjectCommercialPayment::STATUS_PENDING],
            ['id' => 'DESC'],
        );
    }


    /** A project can have only one in-flight checkout across all tiers. */
    public function findInProgressForProject(Project $project): ?ProjectCommercialPayment
    {
        return $this->findOneBy(
            [
                'project' => $project,
                'status' => [ProjectCommercialPayment::STATUS_CREATED, ProjectCommercialPayment::STATUS_PENDING, ProjectCommercialPayment::STATUS_PAID_REVIEW],
            ],
            ['id' => 'DESC'],
        );
    }

    /** @return ProjectCommercialPayment[] */
    public function findByProjectOrdered(Project $project): array
    {
        return $this->findBy(['project' => $project], ['id' => 'DESC']);
    }
}
