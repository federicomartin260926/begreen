<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectMembership>
 *
 * @method ProjectMembership|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProjectMembership|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProjectMembership[]    findAll()
 * @method ProjectMembership[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProjectMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMembership::class);
    }

    /**
     * Devuelve la membresía (si existe) de un usuario en un proyecto.
     */
    public function findMembership(Project $project, User $user): ?ProjectMembership
    {
        return $this->createQueryBuilder('pm')
            ->andWhere('pm.project = :p')->setParameter('p', $project)
            ->andWhere('pm.user = :u')->setParameter('u', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ¿El usuario es miembro del proyecto?
     */
    public function isMember(Project $project, User $user): bool
    {
        return (bool) $this->createQueryBuilder('pm')
            ->select('1')
            ->andWhere('pm.project = :p')->setParameter('p', $project)
            ->andWhere('pm.user = :u')->setParameter('u', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ¿El usuario es owner del proyecto?
     */
    public function isOwner(Project $project, User $user): bool
    {
        return (bool) $this->createQueryBuilder('pm')
            ->select('1')
            ->andWhere('pm.project = :p')->setParameter('p', $project)
            ->andWhere('pm.user = :u')->setParameter('u', $user)
            ->andWhere('pm.projectRole = :r')->setParameter('r', 'owner')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Lista de miembros (usuarios) de un proyecto.
     * @return User[]
     */
    public function membersOf(Project $project): array
    {
        return $this->createQueryBuilder('pm')
            ->select('u')
            ->join('pm.user', 'u')
            ->andWhere('pm.project = :p')->setParameter('p', $project)
            ->orderBy('u.surnames', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lista de owners (usuarios) de un proyecto.
     * @return User[]
     */
    public function ownersOf(Project $project): array
    {
        return $this->createQueryBuilder('pm')
            ->select('u')
            ->join('pm.user', 'u')
            ->andWhere('pm.project = :p')->setParameter('p', $project)
            ->andWhere('pm.projectRole = :r')->setParameter('r', 'owner')
            ->orderBy('u.surnames', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Número de miembros de un proyecto.
     */
    public function countMembers(Project $project): int
    {
        return (int) $this->createQueryBuilder('pm')
            ->select('COUNT(pm.id)')
            ->andWhere('pm.project = :p')->setParameter('p', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Proyectos en los que participa un usuario.
     * @return Project[]
     */
    public function projectsOf(User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->innerJoin('p.projectMemberships', 'pm')
            ->andWhere('pm.user = :u')->setParameter('u', $user)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Añade o actualiza la membresía (idempotente). Devuelve la entidad.
     */
    public function upsertMembership(Project $project, User $user, string $role = 'member'): ProjectMembership
    {
        $pm = $this->findMembership($project, $user);
        if (!$pm) {
            $pm = new ProjectMembership();
            $pm->setProject($project)->setUser($user);
            $this->getEntityManager()->persist($pm);
        }
        $pm->setProjectRole($role);
        // no flush aquí: deja que el caller decida
        return $pm;
    }

    /**
     * Elimina la membresía si existe (no flush).
     */
    public function removeMembership(Project $project, User $user): void
    {
        if ($pm = $this->findMembership($project, $user)) {
            $this->getEntityManager()->remove($pm);
        }
    }
}
