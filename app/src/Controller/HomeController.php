<?php

namespace App\Controller;

use App\Form\ContactTypeForm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Form\ContactType;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Repository\LogoRepository;
use App\Entity\HomeCounter;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\CmsSetting;

class HomeController extends AbstractController
{
    private string $contactEmail;

    public function __construct(string $contactEmail)
    {
        $this->contactEmail = $contactEmail;
    }

    #[Route('/{_locale}', name: 'home', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function index(Request $request, MailerInterface $mailer, LogoRepository $logoRepo, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ContactTypeForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $email = (new Email())
                ->from($data['email'])
                ->to($this->contactEmail)
                ->subject('Nuevo mensaje de contacto desde begreenmyfriend.com')
                ->html($this->renderView('emails/contact.html.twig', [
                    'data' => $data,
                ]));

            $mailer->send($email);

            $this->addFlash('success', 'form.success_message');

            return $this->redirectToRoute('home');
        }

        $repo = $em->getRepository(HomeCounter::class);
        $rows = $repo->findAll();

        $counters = ['eventos' => 0, 'rodajes' => 0, 'compensado' => 0];
        foreach ($rows as $r) {
            $counters[$r->getType()] = $r->getValue();
        }
        // 1) Ajustes con defaults (40, no shuffle)
        $sRepo = $em->getRepository(CmsSetting::class);
        $get = fn(string $k, string $d) => ($sRepo->findOneBy(['key'=>$k]))?->getValue() ?? $d;

        $chunk   = max(1, (int) $get('logo_chunk', '40'));
        $shuffle = $get('logo_shuffle', '0') === '1';

        // 2) Logos activos
        $logos = $logoRepo->findActiveOrdered();

        // 3) Aleatorizar si procede (solo array_shuffle en PHP; no tocar DB)
        if ($shuffle) {
            // reindexa para que Twig|batch no reciba claves “huecas”
            $tmp = $logos;
            shuffle($tmp);
            $logos = array_values($tmp);
        }

        return $this->render('home/index.html.twig', [
            'form' => $form->createView(),
            'home_counters' => $counters,
            'client_logos' => $logos,
            'logo_chunk'   => $chunk,
        ]);
    }

    #[Route('/politica_privacidad/{_locale}', name: 'politica_privacidad', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function politica_privacidad(): Response
    {
        return $this->render('home/privacy_policy.html.twig');
    }

    #[Route('/aviso_legal/{_locale}', name: 'aviso_legal', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function aviso_legal(): Response
    {
        return $this->render('home/legal_notice.html.twig');
    }

    #[Route('/accesibilidad/{_locale}', name: 'accesibilidad', requirements: ['_locale' => 'es|en'], defaults: ['_locale' => 'es'])]
    public function accesibilidad(): Response
    {
        return $this->render('home/accessibility.html.twig');
    }
}
