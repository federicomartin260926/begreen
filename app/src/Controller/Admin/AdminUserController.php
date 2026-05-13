<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;


#[Route('/admin/users', name: 'admin_users_')]
class AdminUserController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render('admin/user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'backend.admin.users.flash.created');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
            'edit' => false,
            'memberships' => [],
            'availableProjects' => [],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        ProjectRepository $projectRepo,
        ProjectMembershipRepository $pmRepo
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $form = $this->createForm(UserType::class, $user, ['edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'backend.admin.users.flash.updated');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $memberships = $pmRepo->createQueryBuilder('pm')
            ->leftJoin('pm.project', 'p')->addSelect('p')
            ->andWhere('pm.user = :u')->setParameter('u', $user)
            ->orderBy('p.name', 'ASC')
            ->getQuery()->getResult();

        $assignedIds = array_map(fn(ProjectMembership $m) => $m->getProject()->getId(), $memberships);

        $availableProjects = $projectRepo->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/user/form.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
            'edit' => true,
            'memberships' => $memberships,
            'availableProjects' => array_filter($availableProjects, fn(Project $p) => !in_array($p->getId(), $assignedIds, true)),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        ProjectRepository $projectRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid('delete_user_' . $user->getId(), (string) $request->request->get('_token'))) {
            // Bloquear si es creador de algún proyecto
            $projectsAsCreator = $projectRepository->count(['user' => $user]);
            if ($projectsAsCreator > 0) {
                $this->addFlash('danger', 'backend.admin.users.flash.cannot_delete_creator');
                $this->addFlash('info',   'backend.admin.users.flash.delete_hint');
                return $this->redirectToRoute('admin_users_index');
            }

            try {
                $em->remove($user);
                $em->flush();
                $this->addFlash('success', 'backend.admin.users.flash.deleted');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'backend.admin.users.flash.delete_error');
                $this->addFlash('info',   'backend.admin.users.flash.delete_hint');
            }
        } else {
            $this->addFlash('warning', 'backend.common.csrf_invalid');
        }

        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/assign-project', name: 'assign_project', methods: ['POST'])]
    public function assignProject(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        ProjectRepository $projectRepo,
        ProjectMembershipRepository $pmRepo
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $projectId = (int) $request->request->get('project_id', 0);
        $token     = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('assign_project_' . $user->getId(), $token)) {
            $this->addFlash('danger', 'backend.common.csrf_invalid');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $project = $projectRepo->find($projectId);
        if (!$project) {
            $this->addFlash('danger', 'backend.admin.users.flash.project_not_found');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $exists = $pmRepo->findOneBy(['user' => $user, 'project' => $project]);
        if ($exists) {
            $this->addFlash('info', 'backend.admin.users.flash.already_member');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $membership = (new ProjectMembership())
            ->setUser($user)
            ->setProject($project)
            ->setProjectRole('member');

        $em->persist($membership);
        $em->flush();

        $this->addFlash('success', 'backend.admin.users.flash.assigned_ok');
        return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id}/remove-membership/{membershipId}', name: 'remove_membership', methods: ['POST'])]
    public function removeMembership(
        User $user,
        int $membershipId,
        Request $request,
        EntityManagerInterface $em,
        ProjectMembershipRepository $pmRepo
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid('remove_membership_' . $membershipId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.common.csrf_invalid');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $membership = $pmRepo->find($membershipId);
        if (!$membership || $membership->getUser() !== $user) {
            $this->addFlash('danger', 'backend.admin.users.flash.membership_not_found');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        if ($membership->getProject()->getUser() === $user) {
            $this->addFlash('warning', 'backend.admin.users.flash.cannot_remove_creator');
            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        $em->remove($membership);
        $em->flush();

        $this->addFlash('success', 'backend.admin.users.flash.membership_removed');
        return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
    }
}
