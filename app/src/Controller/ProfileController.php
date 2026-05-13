<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Form\ProfileFormType;
use App\Form\ChangePasswordFormType;

#[Route(path: '/profile{_locale}', name: 'app_profile', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
class ProfileController extends AbstractController
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;
    private TokenStorageInterface $tokenStorage;
    private RequestStack $requestStack;

    public function __construct(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        RequestStack $requestStack
    ) {
        $this->em = $em;
        $this->passwordHasher = $passwordHasher;
        $this->tokenStorage = $tokenStorage;
        $this->requestStack = $requestStack;
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            throw new AccessDeniedException();
        }

        if ($user->getName() === null) {
            $user->setName('');
        }

        // Formulario de perfil
        $form = $this->createForm(ProfileFormType::class, $user);

        $form->handleRequest($request);

        // Formulario de cambio de contraseña
        $passwordForm = $this->createForm(ChangePasswordFormType::class);

        $passwordForm->handleRequest($request);

        // Procesar formulario de perfil
        if ($form->isSubmitted() && $form->isValid() && $form->get('submit_profile')->isClicked()) {
            $this->em->flush();
            $this->addFlash('success', 'form.profile_updated');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }

        // Procesar formulario de cambio de contraseña
        if ($passwordForm->isSubmitted() && $passwordForm->isValid() && $passwordForm->get('submit_password')->isClicked()) {
            $newPassword = $passwordForm->get('plainPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->tokenStorage->setToken(null);
            $this->requestStack->getSession()->invalidate();

            $this->addFlash('success', 'form.password_changed_logout');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('profile/index.html.twig', [
            'profileForm' => $form->createView(),
            'passwordForm' => $passwordForm->createView(),
        ]);
    }
}
