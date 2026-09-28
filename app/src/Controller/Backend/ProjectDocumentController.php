<?php

namespace App\Controller\Backend;

use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Security\ProjectVoter;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/project')]
#[IsGranted('ROLE_USER')]
final class ProjectDocumentController extends AbstractController
{
    #[Route(
        '/{projectId}/documents/{documentId}/download',
        name: 'backend_project_document_download',
        methods: ['GET'],
        requirements: ['projectId' => '\d+', 'documentId' => '\d+']
    )]
    public function download(
        int $projectId,
        int $documentId,
        EntityManagerInterface $entityManager,
        ProjectDocumentStorage $storage,
    ): Response {
        $project = $entityManager->getRepository(Project::class)->find($projectId);
        $document = $entityManager->getRepository(ProjectDocument::class)->find($documentId);

        if (!$project instanceof Project
            || !$document instanceof ProjectDocument
            || $document->getProject()?->getId() !== $project->getId()
            || !$document->isFile()
            || null === $document->getStoredName()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $path = $storage->absolutePath($document);
        if (!is_file($path) || !is_readable($path)) {
            throw $this->createNotFoundException('Project document not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalName() ?: 'document'
        );

        return $response;
    }
}
