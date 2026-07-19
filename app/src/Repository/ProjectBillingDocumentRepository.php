<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectBillingDocument>
 */
class ProjectBillingDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectBillingDocument::class);
    }

    /**
     * @return ProjectBillingDocument[]
     */
    public function findByProjectOrdered(Project $project): array
    {
        return $this->createQueryBuilder('document')
            ->andWhere('document.project = :project')
            ->setParameter('project', $project)
            ->orderBy('document.issuedAt', 'DESC')
            ->addOrderBy('document.createdAt', 'DESC')
            ->addOrderBy('document.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ProjectBillingDocument[]
     */
    public function findBySubscriptionOrdered(ProjectSubscription $subscription): array
    {
        return $this->createQueryBuilder('document')
            ->andWhere('document.subscription = :subscription')
            ->setParameter('subscription', $subscription)
            ->orderBy('document.issuedAt', 'DESC')
            ->addOrderBy('document.createdAt', 'DESC')
            ->addOrderBy('document.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByProjectAndId(Project $project, int $documentId): ?ProjectBillingDocument
    {
        $document = $this->find($documentId);
        if (!$document instanceof ProjectBillingDocument || $document->getProject()?->getId() !== $project->getId()) {
            return null;
        }

        return $document;
    }

    public function findOneMatchingStripeIdentifiers(
        Project $project,
        ?string $invoiceId = null,
        ?string $sessionId = null,
        ?string $paymentIntentId = null
    ): ?ProjectBillingDocument {
        $qb = $this->createQueryBuilder('document')
            ->andWhere('document.project = :project')
            ->andWhere('document.provider = :provider')
            ->setParameter('project', $project)
            ->setParameter('provider', ProjectBillingDocument::PROVIDER_STRIPE)
            ->setMaxResults(1)
            ->orderBy('document.issuedAt', 'DESC')
            ->addOrderBy('document.createdAt', 'DESC')
            ->addOrderBy('document.id', 'DESC');

        $conditions = [];
        if ($invoiceId !== null && trim($invoiceId) !== '') {
            $conditions[] = 'document.stripeInvoiceId = :invoiceId';
            $qb->setParameter('invoiceId', trim($invoiceId));
        }
        if ($sessionId !== null && trim($sessionId) !== '') {
            $conditions[] = 'document.stripeCheckoutSessionId = :sessionId';
            $qb->setParameter('sessionId', trim($sessionId));
        }
        if ($paymentIntentId !== null && trim($paymentIntentId) !== '') {
            $conditions[] = 'document.stripePaymentIntentId = :paymentIntentId';
            $qb->setParameter('paymentIntentId', trim($paymentIntentId));
        }

        if ($conditions === []) {
            return null;
        }

        $qb->andWhere('('.implode(' OR ', $conditions).')');

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result instanceof ProjectBillingDocument ? $result : null;
    }
}
