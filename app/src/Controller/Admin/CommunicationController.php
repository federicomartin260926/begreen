<?php
namespace App\Controller\Admin;

use App\Entity\CommunicationFile;
use App\Form\CommunicationFileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/communication')]
class CommunicationController extends AbstractController
{
    #[Route('/', name: 'admin_communication_index')]
    public function list(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $files = $em->getRepository(CommunicationFile::class)->findBy([], ['uploadedAt' => 'DESC']);

        return $this->render('admin/communication/index.html.twig', [
            'files' => $files,
        ]);
    }

    #[Route('/new', name: 'admin_communication_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $communicationFile = new CommunicationFile();
        $form = $this->createForm(CommunicationFileType::class, $communicationFile, ['is_edit' => false]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            if ($file) {
                $originalFilename = $file->getClientOriginalName();
                $safeFilename = uniqid() . '.' . $file->guessExtension();

                try {
                    $file->move($this->getParameter('communication_files_directory'), $safeFilename);
                } catch (FileException $e) {
                    $this->addFlash('danger', 'backend.admin.communication.flash.upload_error');
                    return $this->redirectToRoute('admin_communication_new');
                }

                $communicationFile->setFilename($safeFilename);
                $communicationFile->setOriginalFilename($originalFilename);
                $communicationFile->setUploadedAt(new \DateTimeImmutable());

                $em->persist($communicationFile);
                $em->flush();

                $this->addFlash('success', 'backend.admin.communication.flash.upload_success');
                return $this->redirectToRoute('admin_communication_index');
            }
        }

        return $this->render('admin/communication/form.html.twig', [
            'form' => $form->createView(),
            'file' => $communicationFile,
            'is_edit' => false,
        ]);
    }

    #[Route('/edit/{id}', name: 'admin_communication_edit')]
    public function edit(Request $request, CommunicationFile $file, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(CommunicationFileType::class, $file, ['is_edit' => true]);

        $oldFilename = $file->getFilename();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $newFile = $form->get('file')->getData();

            if ($newFile) {
                $originalFilename = $newFile->getClientOriginalName();
                $safeFilename = uniqid() . '.' . $newFile->guessExtension();

                try {
                    $newFile->move($this->getParameter('communication_files_directory'), $safeFilename);
                    $oldFilePath = $this->getParameter('communication_files_directory') . '/' . $oldFilename;
                    if (file_exists($oldFilePath)) {
                        @unlink($oldFilePath);
                    }
                } catch (FileException $e) {
                    $this->addFlash('danger', 'backend.admin.communication.flash.replace_error');
                    return $this->redirectToRoute('admin_communication_edit', ['id' => $file->getId()]);
                }

                $file->setFilename($safeFilename);
                $file->setOriginalFilename($originalFilename);
                $file->setUploadedAt(new \DateTimeImmutable());
            }

            $em->persist($file);
            $em->flush();

            $this->addFlash('success', 'backend.admin.communication.flash.update_success');
            return $this->redirectToRoute('admin_communication_index');
        }

        return $this->render('admin/communication/form.html.twig', [
            'form' => $form->createView(),
            'file' => $file,
            'is_edit' => true,
        ]);
    }

    #[Route('/download/{id}', name: 'admin_communication_download')]
    public function download(CommunicationFile $file): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $filePath = $this->getParameter('communication_files_directory') . '/' . $file->getFilename();

        return $this->file($filePath, $file->getOriginalFilename());
    }

    #[Route('/delete/{id}', name: 'admin_communication_delete', methods: ['POST'])]
    public function delete(Request $request, CommunicationFile $file, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($this->isCsrfTokenValid('delete_file_' . $file->getId(), $request->request->get('_token'))) {
            $filePath = $this->getParameter('communication_files_directory') . '/' . $file->getFilename();
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $em->remove($file);
            $em->flush();

            $this->addFlash('success', 'backend.admin.communication.flash.delete_success');
        } else {
            $this->addFlash('danger', 'backend.admin.communication.flash.csrf_invalid');
        }

        return $this->redirectToRoute('admin_communication_index');
    }
}
