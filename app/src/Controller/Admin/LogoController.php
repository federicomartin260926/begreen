<?php

namespace App\Controller\Admin;

use App\Entity\Logo;
use App\Form\LogoType;
use App\Repository\LogoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/logo', name: 'admin_logo_')]
class LogoController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(LogoRepository $repo): Response
    {
        return $this->render('admin/logo/index.html.twig', [
            'logos' => $repo->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET','POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $logo = new Logo();
        $form = $this->createForm(LogoType::class, $logo, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('imageFile')->getData();
            if ($file) {
                $filename = uniqid('logo_').'.'.$file->guessExtension();
                $file->move($this->getParameter('kernel.project_dir').'/public/uploads/logos', $filename);
                $logo->setImagePath('/uploads/logos/'.$filename);
            }
            $em->persist($logo);
            $em->flush();

            $this->addFlash('success', 'backend.logo.flash.created');
            return $this->redirectToRoute('admin_logo_index');
        }

        return $this->render('admin/logo/form.html.twig', [
            'form' => $form->createView(),
            'edit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET','POST'])]
    public function edit(Logo $logo, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(LogoType::class, $logo, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('imageFile')->getData();
            if ($file) {
                $old = $logo->getImagePath();
                $filename = uniqid('logo_').'.'.$file->guessExtension();
                $file->move($this->getParameter('kernel.project_dir').'/public/uploads/logos', $filename);
                $logo->setImagePath('/uploads/logos/'.$filename);

                // borra el antiguo si existe
                if ($old && str_starts_with($old, '/uploads/logos/')) {
                    $fs = new Filesystem();
                    $fs->remove($this->getParameter('kernel.project_dir').'/public'.$old);
                }
            }

            $em->flush();
            $this->addFlash('success', 'backend.logo.flash.updated');
            return $this->redirectToRoute('admin_logo_index');
        }

        return $this->render('admin/logo/form.html.twig', [
            'form' => $form->createView(),
            'edit' => true,
            'logo' => $logo,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Logo $logo, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        if ($this->isCsrfTokenValid('delete_logo_'.$logo->getId(), $request->request->get('_token'))) {

            $old = $logo->getImagePath();
            $em->remove($logo);
            $em->flush();

            if ($old && str_starts_with($old, '/uploads/logos/')) {
                $fs = new Filesystem();
                $fs->remove($this->getParameter('kernel.project_dir').'/public'.$old);
            }

            $this->addFlash('success', 'backend.logo.flash.deleted');
        } else {
            $this->addFlash('danger', 'backend.common.csrf_invalid');
        }
        return $this->redirectToRoute('admin_logo_index');
    }
}
