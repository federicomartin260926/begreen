<?php

namespace App\Repository;

use App\Entity\EmissionActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmissionActivity>
 *
 * @method EmissionActivity|null find($id, $lockMode = null, $lockVersion = null)
 * @method EmissionActivity|null findOneBy(array $criteria, array $orderBy = null)
 * @method EmissionActivity[]    findAll()
 * @method EmissionActivity[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EmissionActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmissionActivity::class);
    }

    /**
     * Subcategorías fijas por nombre ES (para UI/listados).
     * Devuelve mapa "Etiqueta ES" => "codigo".
     */
    public function getSubcategories(string $categoryName): array
    {
        $subcategories = [
            'Energía' => [
                'Archivo y almacenamiento digital'       => 'almacenamiento',
                'Bombona de gas'                         => 'gas_bombona',
                'Caldera de gas'                         => 'gas_caldera',
                'Depósito de propano'                    => 'gas_propano',
                'Electricidad'                           => 'electricidad',
                'Generador a gas'                        => 'gas_generador',
                'Oficina en remoto'                      => 'remoto',
                'Postproducción - Animación'             => 'animacion',
                'Postproducción - Montaje y edición'     => 'montaje_edicion',
            ],
            'Transporte' => [
                'Aéreo'       => 'aereo',
                'Carretera'   => 'carretera',
                'Ferroviario' => 'ferroviario',
                'Marítimo'    => 'maritimo',
                'Otros'       => 'otros',
            ],
            'Viajes' => [
                'Aéreo'       => 'aereo',
                'Carretera'   => 'carretera',
                'Ferroviario' => 'ferroviario',
                'Marítimo'    => 'maritimo',
                'Otros'       => 'otros',
            ],
        ];

        return $subcategories[$categoryName] ?? [];
    }

    /**
     * Devuelve lista de CÓDIGOS canónicos de subcategoría por ID de categoría.
     * Ej.: ['carretera','aereo',...]
     */
    public function getSubcategoriesByCategoryId(int $categoryId): array
    {
        $qb = $this->createQueryBuilder('ea')
            ->select('DISTINCT ea.subcategory AS subcategory')
            ->where('ea.category = :cid')
            ->andWhere('ea.subcategory IS NOT NULL')
            ->setParameter('cid', $categoryId)
            ->orderBy('ea.subcategory', 'ASC');

        return array_column($qb->getQuery()->getArrayResult(), 'subcategory');
    }

    /**
     * Devuelve lista de CÓDIGOS canónicos de subcategoría por NOMBRE de categoría (ES base).
     */
    public function getSubcategoriesByCategoryName(string $categoryName): array
    {
        $qb = $this->createQueryBuilder('ea')
            ->select('DISTINCT ea.subcategory AS subcategory')
            ->join('ea.category', 'c')
            ->where('c.name = :cname')
            ->andWhere('ea.subcategory IS NOT NULL')
            ->setParameter('cname', $categoryName)
            ->orderBy('ea.subcategory', 'ASC');

        return array_column($qb->getQuery()->getArrayResult(), 'subcategory');
    }

    public function findOneByNameAndYear(string $name, int $year): ?EmissionActivity
    {
        return $this->createQueryBuilder('a')
            ->join('a.emissionSource', 's')
            ->where('a.name = :name')
            ->andWhere('s.year = :year')
            ->setParameter('name', $name)
            ->setParameter('year', $year)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneBySubcatetoryForLatestYear(string $subcategory, int $year, string $sourceName = 'MITECO'): ?EmissionActivity
    {
        return $this->createQueryBuilder('a')
            ->join('a.emissionSource', 's')
            ->where('a.subcategory = :subcategory')
            ->andWhere('s.name = :sourceName')
            ->andWhere('s.year <= :year')
            ->setParameter('subcategory', $subcategory)
            ->setParameter('sourceName', $sourceName)
            ->setParameter('year', $year)
            ->orderBy('s.year', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getActivitiesForLatestYear(string $sourceName = 'MITECO', $category = 'Energía', ?string $subcategory = null): array
    {
        $year = (int) date('Y');

        // 1) Año más reciente disponible para esa fuente/categoría (y subcategoría si aplica)
        $qb = $this->createQueryBuilder('a')
            ->select('MAX(s.year)')
            ->join('a.emissionSource', 's')
            ->join('a.category', 'c')
            ->where('c.name = :categoria')
            ->andWhere('s.name = :sourceName')
            ->andWhere('s.year <= :year')
            ->setParameter('categoria', $category)
            ->setParameter('sourceName', $sourceName)
            ->setParameter('year', $year);

        if ($subcategory) {
            $qb->andWhere('a.subcategory = :subcategory')
               ->setParameter('subcategory', $subcategory);
        }

        $maxYear = $qb->getQuery()->getSingleScalarResult() ?? $year;

        // 2) Actividades del año más reciente (y subcategoría si aplica)
        $qb2 = $this->createQueryBuilder('a')
            ->join('a.emissionSource', 's')
            ->join('a.category', 'c')
            ->where('c.name = :categoria')
            ->andWhere('s.year = :year')
            ->setParameter('categoria', $category)
            ->setParameter('year', $maxYear)
            ->orderBy('a.name', 'ASC');

        if ($subcategory) {
            $qb2->andWhere('a.subcategory = :subcategory')
                ->setParameter('subcategory', $subcategory);
        }

        return $qb2->getQuery()->getResult();
    }

    public function getActivitiesForLatestYearByCategoryId(string $sourceName, int $categoryId, ?string $subcategory = null): array
    {
        $year = (int) date('Y');

        // 1) Año más reciente disponible para esa fuente/categoría (ID) y subcategoría opcional
        $qb = $this->createQueryBuilder('a')
            ->select('MAX(s.year)')
            ->join('a.emissionSource', 's')
            ->join('a.category', 'c')
            ->where('c.id = :cid')
            ->andWhere('s.name = :sourceName')
            ->andWhere('s.year <= :year')
            ->setParameter('cid', $categoryId)
            ->setParameter('sourceName', $sourceName)
            ->setParameter('year', $year);

        if ($subcategory) {
            $qb->andWhere('a.subcategory = :subcategory')
            ->setParameter('subcategory', $subcategory);
        }

        $maxYear = $qb->getQuery()->getSingleScalarResult() ?? $year;

        // 2) Actividades del año más reciente
        $qb2 = $this->createQueryBuilder('a')
            ->join('a.emissionSource', 's')
            ->join('a.category', 'c')
            ->where('c.id = :cid')
            ->andWhere('s.year = :year')
            ->setParameter('cid', $categoryId)
            ->setParameter('year', $maxYear)
            ->orderBy('a.name', 'ASC');

        if ($subcategory) {
            $qb2->andWhere('a.subcategory = :subcategory')
                ->setParameter('subcategory', $subcategory);
        }

        return $qb2->getQuery()->getResult();
    }

}
