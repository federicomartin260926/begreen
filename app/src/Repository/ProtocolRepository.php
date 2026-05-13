<?php

namespace App\Repository;

use App\Entity\Protocol;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProtocolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Protocol::class);
    }

    /**
     * Devuelve un array simple con los NOMBRES de protocolos cuyo type coincide con
     * el del proyecto indicado, incluyendo los de tipo 'ambos'.
     *
     * @return string[]
     */
    public function getNamesForProjectType(string $projectType): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.name')
            ->where('p.type = :type OR p.type = :both')
            ->setParameter('type', $projectType)   // 'rodaje' o 'evento'
            ->setParameter('both', 'ambos')
            ->orderBy('p.name', 'ASC');

        // ['name' => 'XYZ'] -> 'XYZ'
        return array_column($qb->getQuery()->getScalarResult(), 'name');
    }
}
