<?php

namespace App\Controller\Backend;

use App\Entity\CommercialPlan;
use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Repository\CommercialPlanRepository;
use App\Repository\ProjectBillingDocumentRepository;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\StripeProjectCheckoutService;
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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/project', name: 'backend_project_')]
#[IsGranted('ROLE_USER')]
final class ProjectBillingController extends AbstractController
{
    public function __construct(
        private readonly ActiveProjectService $activeProjectService,
        private readonly ProjectBillingDocumentRepository $billingDocumentRepository,
        private readonly StripeInvoiceStorageService $invoiceStorageService,
        private readonly StripeProjectCheckoutService $checkoutService,
        private readonly CommercialPlanRepository $commercialPlanRepository,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/billing', name: 'billing', methods: ['GET'])]
    public function __invoke(Project $project, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $this->activeProjectService->setActiveProject($project);

        $subscription = $project->getSubscription();
        $availableUpgradeTargets = $this->checkoutService->getAvailableUpgradeTargets($project);
        $hasPendingUpgrade = $subscription
            && $subscription->getTargetTier() !== null
            && $subscription->getStripeCheckoutSessionId() !== null;
        $canVerify = $hasPendingUpgrade;
        $upgradeCta = $this->buildUpgradeCta($project, $availableUpgradeTargets, $this->commercialPlanRepository);
        $showUpgradeCta = $subscription === null || $subscription->getStatus() === ProjectSubscription::STATUS_CANCELLED;
        $pendingUpgrade = $this->buildPendingUpgrade($subscription, $upgradeCta);
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
            'upgradeCta' => $upgradeCta,
            'showUpgradeCta' => $showUpgradeCta,
            'pendingUpgrade' => $pendingUpgrade,
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

        if ($this->invoiceStorageService->hasLocalCopy($document)) {
            $this->addFlash('info', 'backend.billing.project.invoice_already_downloaded');

            return $this->redirectToBilling($project, $origin);
        }

        if ($this->invoiceStorageService->syncInvoicePdf($document)) {
            $this->entityManager->flush();
            $this->addFlash('success', 'backend.billing.project.invoice_sync_success');
        } elseif (
            $document->getStripeInvoiceId() === null
            && $document->getStripeCheckoutSessionId() === null
            && $document->getPaymentReference() === null
        ) {
            $this->addFlash('warning', 'backend.billing.project.invoice_missing_association');
        } elseif ($this->isStripeBillingAdmin()) {
            $this->addFlash('warning', $this->resolveStripeSyncFailureMessage($document));
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

    private function isStripeBillingAdmin(): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPER_ADMIN');
    }

    private function resolveStripeSyncFailureMessage(ProjectBillingDocument $document): string
    {
        $paymentReference = (string) ($document->getPaymentReference() ?? '');
        $looksLikeCheckoutSession = str_starts_with($paymentReference, 'cs_');
        $looksLikeInvoice = str_starts_with($paymentReference, 'in_');
        $hasCheckoutSession = $document->getStripeCheckoutSessionId() !== null || $looksLikeCheckoutSession;
        $hasInvoice = $document->getStripeInvoiceId() !== null || $looksLikeInvoice;

        if (!$hasCheckoutSession && !$hasInvoice) {
            return 'backend.billing.project.invoice_missing_association';
        }

        if ($hasInvoice && $document->getInvoicePdfUrl() === null) {
            return 'backend.billing.project.invoice_pdf_pending';
        }

        if ($hasInvoice && $document->getInvoicePdfUrl() !== null && !$this->invoiceStorageService->hasLocalCopy($document)) {
            return 'backend.billing.project.invoice_download_failed';
        }

        if ($hasCheckoutSession && !$hasInvoice) {
            return 'backend.billing.project.invoice_no_session_invoice';
        }

        return 'backend.billing.project.invoice_sync_failed';
    }

    private function getDocumentForProject(Project $project, int $documentId): ProjectBillingDocument
    {
        $document = $this->billingDocumentRepository->findOneByProjectAndId($project, $documentId);
        if (!$document instanceof ProjectBillingDocument) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    private function buildUpgradeCta(Project $project, array $availableUpgradeTargets, CommercialPlanRepository $commercialPlanRepository): array
    {
        $projectTierCode = $this->resolveProjectTier($project);

        if ($projectTierCode === ProjectSubscription::TIER_PRO) {
            return [
                'mode' => 'none',
                'label' => $this->translator->trans('backend.plan.upgrade.active_title'),
                'title' => null,
                'options' => [],
            ];
        }

        $candidateTiers = match ($projectTierCode) {
            ProjectSubscription::TIER_BASIC => [ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO],
            ProjectSubscription::TIER_STANDARD => [ProjectSubscription::TIER_PRO],
            default => [],
        };

        $options = [];
        foreach ($candidateTiers as $targetTier) {
            $targetData = $availableUpgradeTargets[$targetTier] ?? null;
            if (!is_array($targetData) || !array_key_exists('priceId', $targetData) || trim((string) $targetData['priceId']) === '') {
                continue;
            }

            $plan = $commercialPlanRepository->findActiveByCode($targetTier);
            if (!$plan instanceof CommercialPlan) {
                continue;
            }

            $displayAmountCents = isset($targetData['amountCents']) && is_int($targetData['amountCents'])
                ? $targetData['amountCents']
                : $plan->getPriceAmount();
            $priceLabel = $this->formatPlanPrice($displayAmountCents, $plan->getPriceCurrency());
            $options[] = [
                'targetTier' => $targetTier,
                'name' => $plan->getName(),
                'description' => $plan->getDescription(),
                'priceAmount' => $displayAmountCents,
                'priceCurrency' => $plan->getPriceCurrency(),
                'priceLabel' => $priceLabel,
                'ctaLabel' => $this->translator->trans('backend.plan.upgrade.upgrade_to', [
                    '%name%' => $plan->getName(),
                    '%price%' => $priceLabel,
                ]),
                'allowedScores' => $plan->getAllowedScores(),
            ];
        }

        if ($options === []) {
            return [
                'mode' => 'unavailable',
                'label' => $this->translator->trans('backend.plan.upgrade.price_id_missing'),
                'title' => $this->translator->trans('backend.plan.upgrade.select_title'),
                'options' => [],
            ];
        }

        if (\count($options) === 1) {
            return [
                'mode' => 'single',
                'label' => $options[0]['ctaLabel'],
                'title' => $this->translator->trans('backend.plan.upgrade.select_title'),
                'options' => $options,
            ];
        }

        return [
            'mode' => 'modal',
            'label' => $this->translator->trans('backend.plan.upgrade.open_selector'),
            'title' => $this->translator->trans('backend.plan.upgrade.select_title'),
            'options' => $options,
        ];
    }

    private function buildPendingUpgrade(?ProjectSubscription $subscription, array $upgradeCta): ?array
    {
        if (!$subscription instanceof ProjectSubscription) {
            return null;
        }

        $targetTier = $subscription->getTargetTier();
        if ($targetTier === null) {
            return null;
        }

        foreach ($upgradeCta['options'] ?? [] as $option) {
            if (($option['targetTier'] ?? null) === $targetTier) {
                return [
                    'targetTier' => $targetTier,
                    'label' => $option['name'] ?? $targetTier,
                    'description' => $option['description'] ?? null,
                    'priceLabel' => $option['priceLabel'] ?? null,
                    'sessionId' => $subscription->getStripeCheckoutSessionId(),
                    'status' => $subscription->getLastPaymentStatus() ?? 'checkout_created',
                ];
            }
        }

        return [
            'targetTier' => $targetTier,
            'label' => ucfirst($targetTier),
            'description' => null,
            'priceLabel' => null,
            'sessionId' => $subscription->getStripeCheckoutSessionId(),
            'status' => $subscription->getLastPaymentStatus() ?? 'checkout_created',
        ];
    }

    private function resolveProjectTier(Project $project): string
    {
        return $project->getSubscription()?->getTier() ?? ProjectSubscription::TIER_BASIC;
    }

    private function formatPlanPrice(?int $priceAmount, string $currency): string
    {
        if ($priceAmount === null) {
            return $this->translator->trans('backend.common.placeholder');
        }

        $amount = number_format($priceAmount / 100, 2, ',', '.');
        $currency = strtoupper(trim($currency));
        $currencyLabel = $currency === 'EUR' ? '€' : $currency;

        return sprintf('%s %s', $amount, $currencyLabel);
    }
}
