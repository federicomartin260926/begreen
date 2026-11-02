<?php

namespace App\Controller\Backend;

use App\Repository\ProjectMembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Attribute\IsGranted;

#[Route('/backend', name: 'app_backend')]
#[IsGranted('ROLE_USER')]
final class HomeController extends AbstractController
{
    public function __invoke(ProjectMembershipRepository $membershipRepo): Response
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            // Por si acaso, aunque IsGranted ya lo cubre
            return $this->redirectToRoute('app_login');
        }

        // Administrador y Super Administrador ven todos los proyectos,
        // aunque todavía no figuren como miembros de ninguno.
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('backend_project_index');
        }

        // El resto de perfiles entra en el Dashboard solo con proyectos asignados.
        $projects = $membershipRepo->projectsOf($user);
        if (count($projects) > 0) {
            return $this->redirectToRoute('backend_project_index');
        }

        return $this->render('backend/index.html.twig', [
            'controller_name' => 'BackendController',
        ]);
    }
}
