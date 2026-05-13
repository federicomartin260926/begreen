<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Repository\ProjectMembershipRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

class ActiveProjectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private ProjectRepository $projectRepository,
        private ProjectMembershipRepository $membershipRepository,
        private RequestStack $requestStack,
        private Environment $twig
    ) {}

    public function onKernelController(ControllerEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Admin (incluye super admin)
        $isAdmin = $this->security->isGranted('ROLE_SUPER_ADMIN')
               || $this->security->isGranted('ROLE_ADMIN');

        /** @var Project[] $userProjects */
        if ($isAdmin) {
            // Admin: todos los proyectos por fecha de creación DESC
            $userProjects = $this->projectRepository->findBy([], ['createdAt' => 'DESC']);
        } else {
            // No admin: proyectos únicamente desde memberships (ya incluye al creador)
            $userProjects = $this->membershipRepository->projectsOf($user);

            // Reordenar por createdAt DESC (en caso de que el repo no lo haga)
            usort($userProjects, static function (Project $a, Project $b): int {
                $ca = $a->getCreatedAt();
                $cb = $b->getCreatedAt();
                if ($ca === null && $cb === null) return 0;
                if ($ca === null) return 1;
                if ($cb === null) return -1;
                return $cb <=> $ca;
            });
        }

        // --- Validar/seleccionar proyecto activo en sesión ---
        $session = $this->requestStack->getSession();
        $activeProjectId = $session?->get('active_project_id');

        // Map rápido de ids disponibles para el usuario actual
        $allowedIds = [];
        foreach ($userProjects as $p) {
            $allowedIds[$p->getId()] = true;
        }

        $activeProject = null;

        if ($activeProjectId) {
            $candidate = $this->projectRepository->find($activeProjectId);
            if ($candidate instanceof Project) {
                if ($isAdmin || isset($allowedIds[$candidate->getId()])) {
                    $activeProject = $candidate;
                } else {
                    $session?->remove('active_project_id');
                }
            } else {
                $session?->remove('active_project_id');
            }
        }

        // Si no hay activo y existen proyectos, el primero
        if (!$activeProject && !empty($userProjects)) {
            $activeProject = $userProjects[0];
            $session?->set('active_project_id', $activeProject->getId());
        }

        // Variables globales para Twig (compatibles con tu layout)
        $this->twig->addGlobal('userProjects', $userProjects);
        $this->twig->addGlobal('activeProject', $activeProject);
        $this->twig->addGlobal('is_admin', $isAdmin);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
