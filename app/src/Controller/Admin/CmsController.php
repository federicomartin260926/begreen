<?php

namespace App\Controller\Admin;

use App\Entity\HomeCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\CmsSetting;

#[Route('/admin/cms', name: 'admin_cms_')]
class CmsController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/cms/index.html.twig');
    }

    #[Route('/counters', name: 'counters', methods: ['GET', 'POST'])]
    public function counters(Request $request, EntityManagerInterface $em): Response
    {
        // POST -> crear/editar (mismo endpoint)
        if ($request->isMethod('POST')) {
            $this->isCsrfTokenValidOrThrow(
                'cms_counters',
                $request->request->get('_token', '')
            );

            $type  = (string) $request->request->get('type', '');
            $value = (int) $request->request->get('value', 0);

            if (!in_array($type, HomeCounter::TYPES, true)) {
                $this->addFlash('danger', 'backend.cms.counters.flash.invalid_type');
                return $this->redirectToRoute('admin_cms_counters');
            }

            $repo = $em->getRepository(HomeCounter::class);
            /** @var HomeCounter|null $counter */
            $counter = $repo->findOneBy(['type' => $type]);

            if (!$counter) {
                $counter = new HomeCounter();
                $counter->setType($type);
                $em->persist($counter);
            }

            $counter->setValue($value);
            $em->flush();

            $this->addFlash('success', 'backend.cms.counters.flash.saved');
            return $this->redirectToRoute('admin_cms_counters');
        }

        // GET -> listar
        $repo = $em->getRepository(HomeCounter::class);
        $rows = $repo->findBy([], ['type' => 'ASC']);

        // Normaliza array para mostrar 3 tipos fijos siempre
        $byType = [];
        foreach (HomeCounter::TYPES as $t) {
            $byType[$t] = null;
        }
        foreach ($rows as $r) {
            $byType[$r->getType()] = $r;
        }

        return $this->render('admin/cms/counters.html.twig', [
            'countersByType' => $byType,
            'csrf_token' => $this->container->get('security.csrf.token_manager')->getToken('cms_counters'),
        ]);
    }

    #[Route('/logo-settings', name: 'logo_settings', methods: ['GET','POST'])]
    public function logoSettings(Request $request, EntityManagerInterface $em): Response
    {
        $repo = $em->getRepository(CmsSetting::class);

        // helper para leer ajuste (con default)
        $get = function (string $key, string $default) use ($repo): string {
            $s = $repo->findOneBy(['key' => $key]);
            return $s ? $s->getValue() : $default;
        };

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cms_logo_settings', (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException('backend.common.csrf_invalid');
            }

            $chunk = max(1, (int)$request->request->get('logo_chunk', 40));
            $shuffle = $request->request->get('logo_shuffle') === '1' ? '1' : '0';

            foreach ([ 'logo_chunk' => (string)$chunk, 'logo_shuffle' => $shuffle ] as $key => $value) {
                $s = $repo->findOneBy(['key' => $key]) ?? (new CmsSetting())->setKey($key);
                $s->setValue($value);
                $em->persist($s);
            }
            $em->flush();

            $this->addFlash('success', 'backend.cms.logo.flash.saved');
            return $this->redirectToRoute('admin_cms_logo_settings');
        }

        return $this->render('admin/cms/logo_settings.html.twig', [
            'logo_chunk'   => (int)$get('logo_chunk', '40'),
            'logo_shuffle' => $get('logo_shuffle', '0') === '1',
            'csrf_token'   => $this->container->get('security.csrf.token_manager')->getToken('cms_logo_settings'),
        ]);
    }

    private function isCsrfTokenValidOrThrow(string $id, string $token): void
    {
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('backend.common.csrf_invalid');
        }
    }
}
