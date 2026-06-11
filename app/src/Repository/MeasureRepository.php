<?php

namespace App\Repository;

use App\Entity\{Measure, Project, Category, Department, Ods, EsG, Scope, ImpactArea, TripleBalanceAxis};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Gedmo\Translatable\TranslatableListener;
use App\Service\PlanMeasureCatalogResolver;
use App\Entity\Protocol;

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
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC');
        $this->applyCatalogFilter($qb, $project);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        $result = $q->getResult();

        usort($result, static function (Category $left, Category $right): int {
            $leftOrder = $left->getSortOrder() > 0 ? $left->getSortOrder() : PHP_INT_MAX;
            $rightOrder = $right->getSortOrder() > 0 ? $right->getSortOrder() : PHP_INT_MAX;

            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            if ($left->getName() !== $right->getName()) {
                return strcmp((string) $left->getName(), (string) $right->getName());
            }

            return ($left->getId() ?? 0) <=> ($right->getId() ?? 0);
        });

        return $result;
    }

    /** @return Department[] */
    public function getDepartments(Project $project, ?string $locale = null): array
    {
        $legacyQb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT d')
            ->from(Department::class, 'd')
            ->innerJoin(Measure::class, 'm', 'WITH', 'm.department = d')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.name', 'ASC');
        $this->applyCatalogFilter($legacyQb, $project);

        $multiQb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT d')
            ->from(Department::class, 'd')
            ->innerJoin(Measure::class, 'm', 'WITH', 'd MEMBER OF m.departments')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.name', 'ASC');
        $this->applyCatalogFilter($multiQb, $project);

        $legacyQuery = $legacyQb->getQuery();
        $multiQuery = $multiQb->getQuery();
        if ($locale) {
            $legacyQuery->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
            $multiQuery->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }

        $result = $this->mergeDistinctEntitiesById(
            $legacyQuery->getResult(),
            $multiQuery->getResult()
        );

        usort($result, static function (Department $left, Department $right): int {
            $leftOrder = $left->getSortOrder() > 0 ? $left->getSortOrder() : PHP_INT_MAX;
            $rightOrder = $right->getSortOrder() > 0 ? $right->getSortOrder() : PHP_INT_MAX;

            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            if ($left->getName() !== $right->getName()) {
                return strcmp((string) $left->getName(), (string) $right->getName());
            }

            return ($left->getId() ?? 0) <=> ($right->getId() ?? 0);
        });

        return $result;
    }

    /** @return Ods[] */
    public function getOds(Project $project, ?string $locale = null): array
    {
        $legacyQb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT o')
            ->from(Ods::class, 'o')
            ->innerJoin(Measure::class, 'm', 'WITH', 'm.ods = o')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('o.name', 'ASC');
        $this->applyCatalogFilter($legacyQb, $project);

        $multiQb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT o')
            ->from(Ods::class, 'o')
            ->innerJoin(Measure::class, 'm', 'WITH', 'o MEMBER OF m.odsItems')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('o.name', 'ASC');
        $this->applyCatalogFilter($multiQb, $project);

        $legacyQuery = $legacyQb->getQuery();
        $multiQuery = $multiQb->getQuery();
        if ($locale) {
            $legacyQuery->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
            $multiQuery->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }

        $result = $this->mergeDistinctEntitiesById(
            $legacyQuery->getResult(),
            $multiQuery->getResult()
        );
        return $result;
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
        $this->applyCatalogFilter($qb, $project);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    /** @return Scope[] */
    public function getScopes(Project $project, ?string $locale = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT s')
            ->from(Scope::class, 's')
            ->innerJoin(Measure::class, 'm', 'WITH', 'm.scope = s')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('s.name', 'ASC');
        $this->applyCatalogFilter($qb, $project);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    /** @return ImpactArea[] */
    public function getImpactAreas(Project $project, ?string $locale = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT ia')
            ->from(ImpactArea::class, 'ia')
            ->innerJoin(Measure::class, 'm', 'WITH', 'ia MEMBER OF m.impactAreas')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('ia.name', 'ASC');
        $this->applyCatalogFilter($qb, $project);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    /** @return TripleBalanceAxis[] */
    public function getTripleBalanceAxes(Project $project, ?string $locale = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT tba')
            ->from(TripleBalanceAxis::class, 'tba')
            ->innerJoin(Measure::class, 'm', 'WITH', 'tba MEMBER OF m.tripleBalanceAxes')
            ->innerJoin('m.protocol', 'p')
            ->where('p.name IN (:protocols)')
            ->setParameter('protocols', $this->getProtocols($project))
            ->orderBy('tba.name', 'ASC');
        $this->applyCatalogFilter($qb, $project);

        $q = $qb->getQuery();
        if ($locale) {
            $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, $locale);
        }
        return $q->getResult();
    }

    /**
     * @return Measure[]
     */
    public function getCatalogMeasuresForProtocol(Project $project, Protocol $protocol): array
    {
        $qb = $this->createQueryBuilder('m')
            ->innerJoin('m.protocol', 'p')
            ->andWhere('p = :protocol')
            ->setParameter('protocol', $protocol)
            ->orderBy('m.id', 'ASC');

        $this->applyCatalogFilter($qb, $project);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Measure[]
     */
    public function findAllForExport(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.protocol', 'p')->addSelect('p')
            ->leftJoin('m.measureBlock', 'mb')->addSelect('mb')
            ->leftJoin('m.category', 'c')->addSelect('c')
            ->leftJoin('m.categoryGhg', 'cghg')->addSelect('cghg')
            ->leftJoin('m.department', 'd')->addSelect('d')
            ->leftJoin('m.departments', 'dm')->addSelect('dm')
            ->leftJoin('m.ods', 'o')->addSelect('o')
            ->leftJoin('m.odsItems', 'oi')->addSelect('oi')
            ->leftJoin('m.esg', 'e')->addSelect('e')
            ->leftJoin('m.scope', 's')->addSelect('s')
            ->leftJoin('m.impactAreas', 'ia')->addSelect('ia')
            ->leftJoin('m.tripleBalanceAxes', 'tba')->addSelect('tba')
            ->leftJoin('m.verificationSourceLinks', 'vsl')->addSelect('vsl')
            ->leftJoin('vsl.verificationSource', 'vs')->addSelect('vs')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('mb.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    private function applyCatalogFilter(QueryBuilder $qb, Project $project): void
    {
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p', $project);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function applyPlanTaxonomyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['category'])) {
            $qb->andWhere('m.category = :category')->setParameter('category', $filters['category']);
        }

        if (!empty($filters['department'])) {
            $departmentEntity = $this->getEntityManager()->getRepository(Department::class)->find((int) $filters['department']);
            if ($departmentEntity) {
                $qb->andWhere('(m.department = :department OR :department MEMBER OF m.departments)')
                    ->setParameter('department', $departmentEntity);
            }
        }

        if (!empty($filters['ods'])) {
            $odsEntity = $this->getEntityManager()->getRepository(Ods::class)->find((int) $filters['ods']);
            if ($odsEntity) {
                $qb->andWhere('(m.ods = :ods OR :ods MEMBER OF m.odsItems)')
                    ->setParameter('ods', $odsEntity);
            }
        }

        if (!empty($filters['impact_area'])) {
            $impactAreaEntity = $this->getEntityManager()->getRepository(ImpactArea::class)->find((int) $filters['impact_area']);
            if ($impactAreaEntity) {
                $qb->andWhere(':impactArea MEMBER OF m.impactAreas')
                    ->setParameter('impactArea', $impactAreaEntity);
            }
        }

        if (!empty($filters['triple_balance_axis'])) {
            $axisEntity = $this->getEntityManager()->getRepository(TripleBalanceAxis::class)->find((int) $filters['triple_balance_axis']);
            if ($axisEntity) {
                $qb->andWhere(':tripleBalanceAxis MEMBER OF m.tripleBalanceAxes')
                    ->setParameter('tripleBalanceAxis', $axisEntity);
            }
        }

        if (!empty($filters['scope'])) {
            $scopeEntity = $this->getEntityManager()->getRepository(Scope::class)->find((int) $filters['scope']);
            if ($scopeEntity) {
                $qb->andWhere('m.scope = :scope')->setParameter('scope', $scopeEntity);
            }
        }

        if (!empty($filters['esg'])) {
            $esgEntity = $this->getEntityManager()->getRepository(EsG::class)->find((int) $filters['esg']);
            if ($esgEntity) {
                $qb->andWhere('m.esg = :esg')->setParameter('esg', $esgEntity);
            }
        }
    }

    /**
     * @param array<int, object> $first
     * @param array<int, object> $second
     * @return array<int, object>
     */
    private function mergeDistinctEntitiesById(array $first, array $second): array
    {
        $merged = [];

        foreach (array_merge($first, $second) as $entity) {
            if (!is_object($entity) || !method_exists($entity, 'getId')) {
                continue;
            }

            $id = $entity->getId();
            if ($id === null) {
                continue;
            }

            $merged[$id] = $entity;
        }

        $merged = array_values($merged);

        usort($merged, static function (object $left, object $right): int {
            $leftName = method_exists($left, 'getName') ? (string) $left->getName() : '';
            $rightName = method_exists($right, 'getName') ? (string) $right->getName() : '';

            return strnatcasecmp($leftName, $rightName);
        });

        return $merged;
    }
}
