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

        // Proyectos en los que el usuario es miembro
        $projects = $membershipRepo->projectsOf($user);

        if (count($projects) > 0) {
            return $this->redirectToRoute('backend_project_index');
        }

        return $this->render('backend/index.html.twig', [
            'controller_name' => 'BackendController',
        ]);
    }
}
