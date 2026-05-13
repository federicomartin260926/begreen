<?php

namespace App\Repository;

use App\Entity\ProjectPhaseDate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectPhaseDateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectPhaseDate::class);
    }

    // Aquí podés agregar métodos personalizados más adelante
}
