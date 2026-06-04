<?php

namespace App\Controller\Backend;

use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Repository\ProjectBillingDocumentRepository;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\StripeInvoiceStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/project', name: 'backend_project_')]
#[IsGranted('ROLE_USER')]
final class ProjectBillingController extends AbstractController
{
    public function __construct(
        private readonly ActiveProjectService $activeProjectService,
        private readonly ProjectBillingDocumentRepository $billingDocumentRepository,
        private readonly StripeInvoiceStorageService $invoiceStorageService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/billing', name: 'billing', methods: ['GET'])]
    public function __invoke(Project $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $this->activeProjectService->setActiveProject($project);

        $subscription = $project->getSubscription();
        $canVerify = $subscription
            && $subscription->getStripeCheckoutSessionId()
            && in_array($subscription->getStatus(), ['pending_payment', 'active'], true);
        $origin = $this->resolveOrigin($request);
        $backUrl = $this->resolveBackUrl($project, $origin);
        $billingDocuments = [];
        $hasLocalBillingDocument = false;
        foreach ($this->billingDocumentRepository->findByProjectOrdered($project) as $document) {
            $hasLocalCopy = $this->invoiceStorageService->hasLocalCopy($document);
            $hasLocalBillingDocument = $hasLocalBillingDocument || $hasLocalCopy;

            $billingDocuments[] = [
                'document' => $document,
                'hasLocalCopy' => $hasLocalCopy,
            ];
        }

        return $this->render('backend/project/billing.html.twig', [
            'project' => $project,
            'subscription' => $subscription,
            'canVerifyPayment' => $canVerify,
            'from' => $origin,
            'backUrl' => $backUrl,
            'billingDocuments' => $billingDocuments,
            'hasLocalBillingDocument' => $hasLocalBillingDocument,
        ]);
    }

    #[Route('/{id}/billing/document/{documentId}/view', name: 'billing_document_view', methods: ['GET'])]
    public function viewInvoice(Project $project, int $documentId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->activeProjectService->setActiveProject($project);

        $document = $this->getDocumentForProject($project, $documentId);
        $origin = $this->resolveOrigin($request);
        $absolutePath = $this->invoiceStorageService->getLocalInvoiceAbsolutePath($document);

        if ($absolutePath === null) {
            $this->addFlash('warning', 'backend.billing.project.invoice_local_missing');

            return $this->redirectToBilling($project, $origin);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($absolutePath));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    #[Route('/{id}/billing/document/{documentId}/download', name: 'billing_document_download', methods: ['GET'])]
    public function downloadInvoice(Project $project, int $documentId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->activeProjectService->setActiveProject($project);

        $document = $this->getDocumentForProject($project, $documentId);
        $origin = $this->resolveOrigin($request);
        $absolutePath = $this->invoiceStorageService->getLocalInvoiceAbsolutePath($document);

        if ($absolutePath === null) {
            $this->addFlash('warning', 'backend.billing.project.invoice_local_missing');

            return $this->redirectToBilling($project, $origin);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($absolutePath));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    #[Route('/{id}/billing/document/{documentId}/sync', name: 'billing_document_sync', methods: ['POST'])]
    public function syncInvoice(Project $project, int $documentId, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->activeProjectService->setActiveProject($project);

        $origin = $this->resolveOrigin($request);
        $document = $this->getDocumentForProject($project, $documentId);

        if (!$this->isCsrfTokenValid('project_billing_document_sync_'.$project->getId().'_'.$documentId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.subscription.flash.invalid_token');

            return $this->redirectToBilling($project, $origin);
        }

        if ($document->getInvoicePdfUrl() === null) {
            $this->addFlash('warning', 'backend.billing.project.invoice_not_available');

            return $this->redirectToBilling($project, $origin);
        }

        if ($this->invoiceStorageService->syncInvoicePdf($document, true)) {
            $this->entityManager->flush();
            $this->addFlash('success', 'backend.billing.project.invoice_sync_success');
        } else {
            $this->addFlash('warning', 'backend.billing.project.invoice_sync_failed');
        }

        return $this->redirectToBilling($project, $origin);
    }

    private function resolveOrigin(Request $request): string
    {
        return $request->query->getString('from') === 'index' ? 'index' : 'project';
    }

    private function resolveBackUrl(Project $project, string $origin): string
    {
        return $origin === 'index'
            ? $this->generateUrl('backend_project_index')
            : $this->generateUrl('backend_project_edit', ['id' => $project->getId()]);
    }

    private function redirectToBilling(Project $project, string $origin): RedirectResponse
    {
        return $this->redirectToRoute('backend_project_billing', [
            'id' => $project->getId(),
            'from' => $origin,
        ]);
    }

    private function getDocumentForProject(Project $project, int $documentId): ProjectBillingDocument
    {
        $document = $this->billingDocumentRepository->findOneByProjectAndId($project, $documentId);
        if (!$document instanceof ProjectBillingDocument) {
            throw $this->createNotFoundException();
        }

        return $document;
    }
}
