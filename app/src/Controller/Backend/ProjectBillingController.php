<?php

namespace App\Controller\Backend;

use App\Entity\CommercialPlan;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
use App\Repository\PlanRepository;
use App\Repository\ProjectBillingDocumentRepository;
use App\Repository\ProjectMembershipRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectCommercialPaymentRepository;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\Animation\AnimationPlanSynchronizer;
use App\Service\CommercialPlanComparisonBuilder;
use App\Service\ProjectCommercialPricing;
use App\Service\ProjectCommercialInvoiceSyncService;
use App\Service\StripeProjectCheckoutService;
use App\Service\StripeInvoiceStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly CommercialPlanComparisonBuilder $commercialPlanComparisonBuilder,
        private readonly CommercialPlanRepository $commercialPlanRepository,
        private readonly PlanRepository $planRepository,
        private readonly MeasureRepository $measureRepository,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectCommercialPricing $commercialPricing,
        private readonly ProjectCommercialPaymentRepository $commercialPaymentRepository,
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private readonly string $stripeSecretKey,
        #[Autowire('%env(bool:BEGREEN_PROJECT_CHECKOUT_ENABLED)%')]
        private readonly bool $checkoutEnabled,
        private readonly ?AnimationPlanSynchronizer $animationPlanSynchronizer = null,
    ) {
    }

    // The commercial tier is project-wide. Old phase-specific URLs remain as redirects
    // so bookmarks and historical links continue to work.
    #[Route('/billing/{phase}', name: 'billing', methods: ['GET'], requirements: ['phase' => 'elaboration|implementation'])]
    public function __invoke(CommercialPhase $phase): RedirectResponse
    {
        return $this->redirectToRoute('backend_project_billing_overview');
    }

    #[Route('/billing', name: 'billing_overview', methods: ['GET'])]
    public function overview(
        ProjectRepository $projectRepository,
        ProjectMembershipRepository $projectMembershipRepository,
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $projects = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPER_ADMIN')
            ? $projectRepository->findBy([], ['name' => 'ASC'])
            : $projectMembershipRepository->projectsOf($user);
        $projects = array_values(array_filter(
            $projects,
            fn (Project $project): bool => $this->isGranted(ProjectVoter::VIEW, $project),
        ));

        $projectBillings = array_map(
            fn (Project $project): array => $this->buildProjectBilling($project, CommercialPhase::ELABORATION),
            $projects,
        );
        $projectBillings = array_values(array_filter(
            $projectBillings,
            fn (array $billing): bool => $this->hasBillingActivity($billing),
        ));

        return $this->render('backend/project/billing.html.twig', [
            'projectBillings' => $projectBillings,
            'backUrl' => $this->generateUrl('backend_project_index'),
        ]);
    }

    private function buildProjectBilling(Project $project, CommercialPhase $phase): array
    {
        $subscription = $project->getSubscriptionForPhase($phase);
        $plan = $this->planRepository->findOneBy(['project' => $project]);
        $pendingCheckoutInspection = $this->checkoutService->inspectPendingCheckout($project, $phase);
        $availableUpgradeTargets = [];
        $hasPendingUpgrade = $subscription
            && $subscription->getTargetTier() !== null
            && $subscription->getStripeCheckoutSessionId() !== null;
        $canVerify = $hasPendingUpgrade;
        $upgradeCta = $this->buildUpgradeCta($project, $phase, $availableUpgradeTargets, $this->commercialPlanRepository);
        $upgradeCta = $this->attachMeasureCounts($upgradeCta, $plan, $phase);
        $showUpgradeCta = $subscription === null || $subscription->getStatus() === ProjectSubscription::STATUS_CANCELLED;
        $pendingUpgrade = $this->buildPendingUpgrade($subscription, $upgradeCta);
        $canManageBilling = $this->isGranted(ProjectVoter::EDIT, $project);
        // Project-level payments are independent of the legacy phase subscriptions.
        // This is read-only: the invoice itself is synchronized separately.
        $projectPayments = $this->commercialPaymentRepository->findByProjectOrdered($project);
        $projectInvoiceDocuments = [];
        foreach ($projectPayments as $commercialPayment) {
            if ($commercialPayment->getStatus() !== \App\Entity\ProjectCommercialPayment::STATUS_PAID
                || $commercialPayment->getStripeCheckoutSessionId() === null) {
                continue;
            }
            $invoiceDocument = $this->billingDocumentRepository->findOneBy([
                'project' => $project,
                'subscription' => null,
                'stripeCheckoutSessionId' => $commercialPayment->getStripeCheckoutSessionId(),
            ]);
            if ($invoiceDocument instanceof ProjectBillingDocument) {
                $projectInvoiceDocuments[$commercialPayment->getId()] = [
                    'document' => $invoiceDocument,
                    'hasLocalCopy' => $this->invoiceStorageService->hasLocalCopy($invoiceDocument),
                ];
            }
        }
        $billingDocuments = [];
        $hasLocalBillingDocument = false;
        // Keep historical Elaboracion/Implementacion documents, but display them
        // together on one project-level billing page, with their original phase for
        // authorization of private PDF view/download/sync routes.
        $legacySubscriptions = [];
        foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $legacyPhase) {
            $legacySubscription = $project->getSubscriptionForPhase($legacyPhase);
            if (!$legacySubscription instanceof ProjectSubscription) {
                continue;
            }
            $legacySubscriptions[] = $legacySubscription;
            foreach ($this->billingDocumentRepository->findBySubscriptionOrdered($legacySubscription) as $document) {
                $hasLocalCopy = $this->invoiceStorageService->hasLocalCopy($document);
                $hasLocalBillingDocument = $hasLocalBillingDocument || $hasLocalCopy;
                $billingDocuments[] = [
                    'document' => $document,
                    'hasLocalCopy' => $hasLocalCopy,
                    'legacyPhase' => $legacyPhase,
                    'legacySubscription' => $legacySubscription,
                ];
            }
        }
        usort($billingDocuments, static function (array $a, array $b): int {
            $aDocument = $a['document'];
            $bDocument = $b['document'];
            $aTime = ($aDocument->getPaidAt() ?? $aDocument->getIssuedAt())?->getTimestamp() ?? 0;
            $bTime = ($bDocument->getPaidAt() ?? $bDocument->getIssuedAt())?->getTimestamp() ?? 0;

            return $bTime <=> $aTime;
        });

        return [
            'project' => $project,
            'subscription' => $subscription,
            'legacySubscriptions' => $legacySubscriptions,
            'projectPayments' => $projectPayments,
            'projectInvoiceDocuments' => $projectInvoiceDocuments,
            'canSyncProjectInvoice' => $canManageBilling && $this->checkoutEnabled && str_starts_with($this->stripeSecretKey, 'sk_test_'),
            'pendingCheckout' => [
                'status' => $pendingCheckoutInspection->status,
                'isReusable' => $pendingCheckoutInspection->isReusable(),
                'shouldRetry' => $pendingCheckoutInspection->shouldRetry(),
                'shouldVerify' => $pendingCheckoutInspection->shouldVerify(),
            ],
            'canVerifyPayment' => $canVerify,
            'canManageBilling' => $canManageBilling,
            'billingDocuments' => $billingDocuments,
            'hasLocalBillingDocument' => $hasLocalBillingDocument,
            'upgradeCta' => $upgradeCta,
            'showUpgradeCta' => $showUpgradeCta,
            'pendingUpgrade' => $pendingUpgrade,
        ];
    }

    private function hasBillingActivity(array $billing): bool
    {
        if (($billing['projectPayments'] ?? []) !== []) {
            return true;
        }
        if (($billing['billingDocuments'] ?? []) !== []) {
            return true;
        }

        foreach ($billing['legacySubscriptions'] ?? [] as $subscription) {
            if ($subscription->getTier() !== ProjectSubscription::TIER_BASIC
                || $subscription->getPaidAmountCents() !== null
                || $subscription->getPaidAt() !== null
                || $subscription->getPaymentReference() !== null
                || $subscription->getStripeCheckoutSessionId() !== null
                || $subscription->getStripePaymentIntentId() !== null
                || $subscription->getStripeInvoiceId() !== null
                || $subscription->getLastPaymentStatus() !== null
                || $subscription->getTargetTier() !== null) {
                return true;
            }
        }

        return false;
    }

    #[Route('/{id}/plans/{phase}', name: 'plan_comparison', methods: ['GET'], requirements: ['phase' => 'elaboration|implementation'])]
    public function comparison(Project $project, CommercialPhase $phase, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->activeProjectService->setActiveProject($project);

        $subscription = $project->getSubscriptionForPhase($phase);
        $availableUpgradeTargets = [];
        $comparison = $this->commercialPlanComparisonBuilder->build(
            $phase,
            $this->resolveProjectTier($project, $phase),
            $this->planRepository->findOneBy(['project' => $project]),
            $availableUpgradeTargets,
            $project,
        );
        $origin = $this->resolveComparisonOrigin($request);

        // The new project checkout is explicitly opt-in, TEST only.
        // Never take an amount or tier from the browser's form fields.
        $canUseCheckout = $this->checkoutEnabled && str_starts_with($this->stripeSecretKey, 'sk_test_');
        $pendingPayment = $canUseCheckout ? $this->commercialPaymentRepository->findInProgressForProject($project) : null;
        $currentTier = $project->getCommercialTier();
        $checkoutOffers = [];
        $checkoutPricingUnavailable = false;

        if ($canUseCheckout && $pendingPayment === null && $currentTier !== Project::COMMERCIAL_TIER_PRO) {
            foreach ([Project::COMMERCIAL_TIER_STANDARD, Project::COMMERCIAL_TIER_PRO] as $targetTier) {
                if ($targetTier === $currentTier || !($comparison['plans'][$targetTier]['active'] ?? false)) {
                    continue;
                }

                try {
                    $amountCents = $currentTier === Project::COMMERCIAL_TIER_STANDARD
                        ? $this->commercialPricing->getUpgradeAmountCents($project, $currentTier, $targetTier)
                        : $this->commercialPricing->getAmountCents($project, $targetTier);
                    if ($amountCents <= 0) {
                        throw new \DomainException('Checkout quote must be positive.');
                    }
                    $checkoutOffers[$targetTier] = [
                        'amountCents' => $amountCents,
                        'isUpgrade' => $currentTier === Project::COMMERCIAL_TIER_STANDARD,
                    ];
                } catch (\DomainException | \InvalidArgumentException) {
                    // Do not block the comparison screen for an incomplete project.
                    $checkoutPricingUnavailable = true;
                }
            }
        }

        return $this->render('backend/commercial_plan/comparison.html.twig', [
            'project' => $project,
            'phase' => $phase,
            'phaseLabel' => $this->translator->trans('backend.commercial_phases.'.$phase->value),
            'comparison' => $comparison,
            'checkoutEnabled' => $canUseCheckout,
            'checkoutOffers' => $checkoutOffers,
            'checkoutPricingUnavailable' => $checkoutPricingUnavailable,
            'pendingPaymentStatus' => $pendingPayment?->getStatus(),
            'origin' => $origin,
            'backUrl' => $this->resolveComparisonBackUrl($project, $phase, $origin),
        ]);
    }

    #[Route('/{id}/billing/project-payment/{paymentId}/sync-invoice', name: 'billing_project_invoice_sync', methods: ['POST'])]
    public function syncProjectInvoice(Project $project, int $paymentId, Request $request, ProjectCommercialInvoiceSyncService $invoiceSyncService): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        if (!$this->checkoutEnabled || !str_starts_with($this->stripeSecretKey, 'sk_test_')) {
            throw $this->createAccessDeniedException('Project invoice sync is disabled.');
        }
        if (!$this->isCsrfTokenValid('project_commercial_invoice_sync_'.$project->getId().'_'.$paymentId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid invoice synchronization token.');
        }
        $payment = $this->commercialPaymentRepository->findOneBy(['id' => $paymentId, 'project' => $project]);
        if (!$payment instanceof \App\Entity\ProjectCommercialPayment) {
            throw $this->createNotFoundException();
        }
        try {
            $result = $invoiceSyncService->sync($payment);
            if (!$result['invoiceFound']) {
                $this->addFlash('warning', 'Stripe todavía no tiene una factura disponible para este pago.');
            } elseif (!$result['pdfAvailable']) {
                $this->addFlash('warning', 'Factura asociada al pago. El PDF todavía no se ha podido descargar; puedes reintentarlo.');
            } else {
                $this->addFlash('success', 'Factura de Stripe sincronizada y PDF guardado correctamente.');
            }
        } catch (\DomainException $exception) {
            $this->addFlash('warning', 'La factura de Stripe no coincide con este pago. No se ha modificado el plan.');
        } catch (\Throwable $exception) {
            // Never expose Stripe exception messages or API credentials in the browser.
            $this->addFlash('danger', 'No se pudo sincronizar la factura. Puedes reintentarlo sin repetir el pago.');
        }

        return $this->redirectToRoute('backend_project_billing_overview', ['_fragment' => 'billing-project-'.$project->getId()]);
    }

    #[Route('/{id}/billing/project-document/{documentId}/view', name: 'billing_project_document_view', methods: ['GET'])]
    public function viewProjectInvoice(Project $project, int $documentId): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $document = $this->getProjectInvoiceDocument($project, $documentId);
        $path = $this->invoiceStorageService->getLocalInvoiceAbsolutePath($document);
        if ($path === null) {
            throw $this->createNotFoundException('The invoice PDF is not available.');
        }
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    #[Route('/{id}/billing/project-document/{documentId}/download', name: 'billing_project_document_download', methods: ['GET'])]
    public function downloadProjectInvoice(Project $project, int $documentId): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $document = $this->getProjectInvoiceDocument($project, $documentId);
        $path = $this->invoiceStorageService->getLocalInvoiceAbsolutePath($document);
        if ($path === null) {
            throw $this->createNotFoundException('The invoice PDF is not available.');
        }
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    private function getProjectInvoiceDocument(Project $project, int $documentId): ProjectBillingDocument
    {
        $document = $this->billingDocumentRepository->findOneByProjectAndId($project, $documentId);
        if (!$document instanceof ProjectBillingDocument || $document->getSubscription() !== null
            || $document->getStripeCheckoutSessionId() === null) {
            throw $this->createNotFoundException();
        }
        $payment = $this->commercialPaymentRepository->findOneBy([
            'project' => $project,
            'stripeCheckoutSessionId' => $document->getStripeCheckoutSessionId(),
            'status' => \App\Entity\ProjectCommercialPayment::STATUS_PAID,
        ]);
        if (!$payment instanceof \App\Entity\ProjectCommercialPayment
            || $payment->getStripeInvoiceId() === null
            || $payment->getStripeInvoiceId() !== $document->getStripeInvoiceId()) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    #[Route('/{id}/billing/{phase}/document/{documentId}/view', name: 'billing_document_view', methods: ['GET'], requirements: ['phase' => 'elaboration|implementation'])]
    public function viewInvoice(Project $project, CommercialPhase $phase, int $documentId): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $document = $this->getDocumentForProject($project, $phase, $documentId);
        $absolutePath = $this->invoiceStorageService->getLocalInvoiceAbsolutePath($document);

        if ($absolutePath === null) {
            $this->addFlash('warning', 'backend.billing.project.invoice_local_missing');

            return $this->redirectToBilling($phase);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($absolutePath));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    #[Route('/{id}/billing/{phase}/document/{documentId}/download', name: 'billing_document_download', methods: ['GET'], requirements: ['phase' => 'elaboration|implementation'])]
    public function downloadInvoice(Project $project, CommercialPhase $phase, int $documentId): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $document = $this->getDocumentForProject($project, $phase, $documentId);
        $absolutePath = $this->invoiceStorageService->getLocalInvoiceAbsolutePath($document);

        if ($absolutePath === null) {
            $this->addFlash('warning', 'backend.billing.project.invoice_local_missing');

            return $this->redirectToBilling($phase);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($absolutePath));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    #[Route('/{id}/billing/{phase}/document/{documentId}/sync', name: 'billing_document_sync', methods: ['POST'], requirements: ['phase' => 'elaboration|implementation'])]
    public function syncInvoice(Project $project, CommercialPhase $phase, int $documentId, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $document = $this->getDocumentForProject($project, $phase, $documentId);

        if (!$this->isCsrfTokenValid('project_billing_document_sync_'.$project->getId().'_'.$documentId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.subscription.flash.invalid_token');

            return $this->redirectToBilling($phase);
        }

        if ($this->invoiceStorageService->hasLocalCopy($document)) {
            $this->addFlash('info', 'backend.billing.project.invoice_already_downloaded');

            return $this->redirectToBilling($phase);
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

        return $this->redirectToBilling($phase);
    }

    private function resolveComparisonOrigin(Request $request): string
    {
        $origin = $request->query->getString('from');

        return in_array($origin, ['measures', 'elaboration_done', 'review', 'billing'], true)
            ? $origin
            : 'project';
    }

    private function resolveComparisonBackUrl(Project $project, CommercialPhase $phase, string $origin): string
    {
        return match ($origin) {
            'measures' => $this->generateUrl('backend_plan_measures'),
            'elaboration_done' => $this->generateUrl('backend_plan_done'),
            'review' => $this->generateUrl('backend_plan_review', ['state' => 'all']),
            'billing' => $this->generateUrl('backend_project_billing_overview', [
                '_fragment' => 'billing-project-'.$project->getId(),
            ]),
            default => $this->generateUrl('backend_project_edit', ['id' => $project->getId()]),
        };
    }

    private function redirectToBilling(CommercialPhase $phase = CommercialPhase::ELABORATION): RedirectResponse
    {
        return $this->redirectToRoute('backend_project_billing_overview');
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

    private function getDocumentForProject(Project $project, CommercialPhase $phase, int $documentId): ProjectBillingDocument
    {
        $document = $this->billingDocumentRepository->findOneByProjectAndId($project, $documentId);
        if (!$document instanceof ProjectBillingDocument || $document->getSubscription()?->getPhase() !== $phase) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    private function buildUpgradeCta(Project $project, CommercialPhase $phase, array $availableUpgradeTargets, CommercialPlanRepository $commercialPlanRepository): array
    {
        $projectTierCode = $this->resolveProjectTier($project, $phase);
        $commercialPlans = [];
        foreach ([ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO] as $tier) {
            $commercialPlan = $commercialPlanRepository->findActiveByPhaseAndCode($phase, $tier);
            if ($commercialPlan instanceof CommercialPlan) {
                $commercialPlans[$tier] = $commercialPlan;
            }
        }

        if ($projectTierCode === ProjectSubscription::TIER_PRO) {
            return [
                'mode' => 'none',
                'label' => $this->translator->trans('backend.plan.upgrade.active_title'),
                'title' => null,
                'options' => [],
                'phase' => $phase->value,
                'currentTier' => $projectTierCode,
                'commercialPlans' => $commercialPlans,
                'measureCounts' => [],
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

            $plan = $commercialPlans[$targetTier] ?? null;
            if (!$plan instanceof CommercialPlan) {
                continue;
            }

            $displayAmountCents = isset($targetData['amountCents']) && is_int($targetData['amountCents'])
                ? $targetData['amountCents']
                : $plan->getPriceAmount();
            $priceLabel = $this->formatPlanPrice($displayAmountCents, $plan->getPriceCurrency());
            $targetTierLabel = ucfirst($targetTier);
            $options[] = [
                'targetTier' => $targetTier,
                'phase' => $phase->value,
                'name' => $targetTierLabel,
                'description' => $plan->getDescription(),
                'priceAmount' => $displayAmountCents,
                'priceCurrency' => $plan->getPriceCurrency(),
                'priceLabel' => $priceLabel,
                'ctaLabel' => $this->translator->trans('backend.plan.upgrade.upgrade_to', [
                    '%name%' => $targetTierLabel,
                    '%price%' => $priceLabel,
                ]),
                'allowedScores' => $plan->getAllowedScores(),
            ];
        }

        if ($options === []) {
            return [
                'mode' => 'unavailable',
                'label' => $this->translator->trans('backend.subscription.flash.checkout_temporarily_unavailable'),
                'title' => $this->translator->trans('backend.plan.upgrade.select_title'),
                'options' => [],
                'phase' => $phase->value,
                'currentTier' => $projectTierCode,
                'commercialPlans' => $commercialPlans,
                'measureCounts' => [],
            ];
        }

        if (\count($options) === 1) {
            return [
                'mode' => 'single',
                'label' => $options[0]['ctaLabel'],
                'title' => $this->translator->trans('backend.plan.upgrade.select_title'),
                'options' => $options,
                'phase' => $phase->value,
                'currentTier' => $projectTierCode,
                'commercialPlans' => $commercialPlans,
                'measureCounts' => [],
            ];
        }

        return [
            'mode' => 'comparison',
            'label' => $this->translator->trans('backend.plan.upgrade.open_selector'),
            'title' => $this->translator->trans('backend.plan.upgrade.select_title'),
            'options' => $options,
            'phase' => $phase->value,
            'currentTier' => $projectTierCode,
            'commercialPlans' => $commercialPlans,
            'measureCounts' => [],
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

    private function attachMeasureCounts(array $upgradeCta, ?Plan $plan, CommercialPhase $phase): array
    {
        $protocol = $plan?->getProtocol();
        if (!$protocol) {
            return $upgradeCta;
        }

        foreach ($upgradeCta['options'] ?? [] as $index => $option) {
            $upgradeCta['options'][$index]['measureCount'] = $this->countMeasuresForTier(
                $plan,
                $phase,
                (string) ($option['targetTier'] ?? ''),
                $option['allowedScores'] ?? [],
            );
        }

        foreach ($upgradeCta['commercialPlans'] ?? [] as $tier => $commercialPlan) {
            if (!$commercialPlan instanceof CommercialPlan) {
                continue;
            }

            $upgradeCta['measureCounts'][$tier] = $this->countMeasuresForTier(
                $plan,
                $phase,
                (string) $tier,
                $commercialPlan->getAllowedScores(),
            );
        }

        return $upgradeCta;
    }

    /** @param int[] $allowedScores */
    private function countMeasuresForTier(Plan $plan, CommercialPhase $phase, string $tier, array $allowedScores): int
    {
        $project = $plan->getProject();
        if ($phase === CommercialPhase::ELABORATION
            && $project
            && $this->animationPlanSynchronizer?->supports($plan, $project)) {
            return count($this->animationPlanSynchronizer->resolveMeasuresForTier($plan, $project, $tier));
        }

        return $this->measureRepository->countCatalogMeasuresForProtocol($plan->getProtocol(), $allowedScores);
    }

    private function resolveProjectTier(Project $project, CommercialPhase $phase): string
    {
        return $project->getCommercialTier();
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
