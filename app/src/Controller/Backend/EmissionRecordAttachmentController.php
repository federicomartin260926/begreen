<?php

namespace App\Controller\Backend;

use App\Entity\EmissionRecord;
use App\Entity\EmissionRecordAttachment;
use App\Security\EmissionRecordVoter;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/emission')]
#[IsGranted('ROLE_USER')]
final class EmissionRecordAttachmentController extends AbstractController
{
    #[Route('/{recordId}/attachments/{attachmentId}/download', name: 'backend_emission_attachment_download', methods: ['GET'])]
    public function download(
        int $recordId,
        int $attachmentId,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        EmissionRecordAttachmentStorage $storage,
    ): Response {
        [$record, $attachment] = $this->ownedAttachment($recordId, $attachmentId, $activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::VIEW, $record);

        $path = $storage->absolutePath($attachment);
        if (!is_file($path) || !is_readable($path)) {
            throw $this->createNotFoundException('Attachment file not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getOriginalName());

        return $response;
    }

    #[Route('/{recordId}/attachments/{attachmentId}/delete', name: 'backend_emission_attachment_delete', methods: ['POST'])]
    public function delete(
        int $recordId,
        int $attachmentId,
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        EmissionRecordAttachmentStorage $storage,
    ): Response {
        [$record, $attachment] = $this->ownedAttachment($recordId, $attachmentId, $activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::EDIT, $record);

        if (!$this->isCsrfTokenValid('delete_emission_attachment_'.$attachmentId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.emission.attachments.flash.csrf_invalid');

            return $this->redirectToRoute('backend_emission_edit_transport_v20', ['id' => $recordId] + $request->query->all());
        }

        try {
            $storage->delete($attachment);
            $record->removeAttachment($attachment);
            $entityManager->remove($attachment);
            $entityManager->flush();
            $this->addFlash('success', 'backend.emission.attachments.flash.deleted');
        } catch (\Throwable) {
            $this->addFlash('danger', 'backend.emission.attachments.flash.delete_failed');
        }

        return $this->redirectToRoute('backend_emission_edit_transport_v20', ['id' => $recordId] + $request->query->all());
    }

    /** @return array{EmissionRecord, EmissionRecordAttachment} */
    private function ownedAttachment(
        int $recordId,
        int $attachmentId,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
    ): array {
        $record = $entityManager->find(EmissionRecord::class, $recordId);
        $attachment = $entityManager->find(EmissionRecordAttachment::class, $attachmentId);
        $project = $activeProjectService->getActiveProject();

        if (!$record || !$attachment || !$project
            || $record->getProject()->getId() !== $project->getId()
            || $attachment->getEmissionRecord()->getId() !== $record->getId()
        ) {
            throw $this->createNotFoundException('Attachment not found.');
        }

        return [$record, $attachment];
    }
}
