<?php

namespace App\Controller\Backend;

use App\Entity\CommunicationFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/backend/communication')]
class CommunicationController extends AbstractController
{
    #[Route('/', name: 'backend_communication_index')]
    public function list(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $files = $em->getRepository(CommunicationFile::class)->findBy([], ['filename' => 'DESC']);

        return $this->render('backend/communication/index.html.twig', [
            'files' => $files,
        ]);
    }

    #[Route('/download/{id}', name: 'backend_communication_download')]
    public function download(CommunicationFile $file): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $filePath = $this->getParameter('communication_files_directory') . '/' . $file->getFilename();

        // Obtener tipo MIME
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $fileContent = file_get_contents($filePath);

        $disposition = 'attachment'; // Por defecto descarga

        if (in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'])) {
            $disposition = 'inline'; // Mostrar embebido en navegador
        }

        return new Response($fileContent, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition . '; filename="' . $file->getOriginalFilename() . '"',
            'Content-Length' => filesize($filePath),
            'Cache-Control' => 'public, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
