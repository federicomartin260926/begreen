<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordRequestFormType;
use App\Form\ResetPasswordFormType;
use App\Repository\UserRepository;
use App\Security\ResetPasswordHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ResetPasswordController extends AbstractController
{
    #[Route('/reset-password/{_locale}', name: 'app_forgot_password', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        ResetPasswordHelper $resetPasswordHelper,
        TranslatorInterface $translator
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $userRepository->findOneBy(['email' => $email]);

            if (!$user) {
                $this->addFlash('danger', $translator->trans('reset_password.email_not_found', [], 'messages'));
                return $this->render('reset_password/request.html.twig', [
                    'requestForm' => $form->createView(),
                ]);
            }

            $resetPasswordHelper->sendResetEmail($user);

            $this->addFlash('success', $translator->trans('reset_password.request_success', [], 'messages'));
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form->createView(),
        ]);
    }

    #[Route('/reset-password/{token}/{_locale}', name: 'app_reset_password', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function reset(
        string $token,
        Request $request,
        UserRepository $userRepository,
        ResetPasswordHelper $resetPasswordHelper,
        UserPasswordHasherInterface $passwordHasher,
        TranslatorInterface $translator
    ): Response {
        $user = $userRepository->findOneBy(['resetToken' => $token]);

        if (!$resetPasswordHelper->validateToken($user)) {
            $this->addFlash('danger', $translator->trans('reset_password.invalid_token', [], 'messages'));
            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );

            $user->setPassword($hashedPassword);
            $resetPasswordHelper->clearToken($user);

            $this->addFlash('success', $translator->trans('reset_password.password_reset_success', [], 'messages'));
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form->createView(),
        ]);
    }
}
