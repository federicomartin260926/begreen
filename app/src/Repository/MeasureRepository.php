<?php

namespace App\Repository;

use App\Entity\{Measure, Project, Category, Department, Ods, EsG};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Gedmo\Translatable\TranslatableListener;
use App\Service\PlanMeasureCatalogResolver;

class MeasureRepository extends ServiceEntityRepository
{
    private ProtocolRepository $protocolRepository;
    private PlanMeasureCatalogResolver $catalogResolver;

    public function __construct(ManagerRegistry $registry, ProtocolRepository $protocolRepository, PlanMeasureCatalogResolver $catalogResolver)
    {
        parent::__construct($registry, Measure::class);
        $this->protocolRepository = $protocolRepository;
        $this->catalogResolver = $catalogResolver;
    }

    /** Devuelve nombres de protocolos permitidos para el tipo de proyecto. */
    public function getProtocols(Project $project): array
    {
        return $this->protocolRepository->getNamesForProjectType($project->getType());
    }

    /** @return Category[] */
    public function getCategories(Project $project, ?string $locale = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT c')
            ->from(Category::class, 'c')
            ->innerJoin(Measure::class, 'm', 'WITH', 'm.category = c')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('c.name', 'ASC');
        $this->applyCatalogFilter($qb);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    /** @return Department[] */
    public function getDepartments(Project $project, ?string $locale = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT d')
            ->from(Department::class, 'd')
            ->innerJoin(Measure::class, 'm', 'WITH', 'm.department = d')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('d.name', 'ASC');
        $this->applyCatalogFilter($qb);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    /** @return Ods[] */
    public function getOds(Project $project, ?string $locale = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT o')
            ->from(Ods::class, 'o')
            ->innerJoin(Measure::class, 'm', 'WITH', 'm.ods = o')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('o.name', 'ASC');
        $this->applyCatalogFilter($qb);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    /** @return EsG[] */
    public function getEsg(Project $project, ?string $locale = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT e')
            ->from(EsG::class, 'e')
            ->innerJoin(Measure::class, 'm', 'WITH', 'm.esg = e')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('e.name', 'ASC');
        $this->applyCatalogFilter($qb);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    private function applyCatalogFilter(QueryBuilder $qb): void
    {
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p');
    }
}
