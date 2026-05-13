<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login/{_locale}', name: 'app_login', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Redirigir a home si ya está autenticado
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }
        
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/post-logout/{_locale}', name: 'app_post_logout', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function postLogout(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('home', [
            '_locale' => $request->getLocale() ?? 'es',
        ]);
    }
}
