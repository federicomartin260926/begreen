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
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\CommercialPlanComparisonBuilder;
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
        private readonly CommercialPlanComparisonBuilder $commercialPlanComparisonBuilder,
        private readonly CommercialPlanRepository $commercialPlanRepository,
        private readonly PlanRepository $planRepository,
        private readonly MeasureRepository $measureRepository,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/billing/{phase}', name: 'billing', methods: ['GET'], requirements: ['phase' => 'elaboration|implementation'])]
    public function __invoke(
        CommercialPhase $phase,
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
            fn (Project $project): array => $this->buildProjectBilling($project, $phase),
            $projects,
        );
        $projectBillings = array_values(array_filter(
            $projectBillings,
            fn (array $billing): bool => $this->hasBillingActivity($billing),
        ));

        return $this->render('backend/project/billing.html.twig', [
            'phase' => $phase,
            'phaseLabel' => $this->translator->trans('backend.commercial_phases.'.$phase->value),
            'projectBillings' => $projectBillings,
            'backUrl' => $this->generateUrl('backend_project_index'),
        ]);
    }

    private function buildProjectBilling(Project $project, CommercialPhase $phase): array
    {
        $subscription = $project->getSubscriptionForPhase($phase);
        $plan = $this->planRepository->findOneBy(['project' => $project]);
        $pendingCheckoutInspection = $this->checkoutService->inspectPendingCheckout($project, $phase);
        $availableUpgradeTargets = $subscription instanceof ProjectSubscription
            ? $this->checkoutService->getAvailableUpgradeTargets($project, $phase)
            : [];
        $hasPendingUpgrade = $subscription
            && $subscription->getTargetTier() !== null
            && $subscription->getStripeCheckoutSessionId() !== null;
        $canVerify = $hasPendingUpgrade;
        $upgradeCta = $this->buildUpgradeCta($project, $phase, $availableUpgradeTargets, $this->commercialPlanRepository);
        $upgradeCta = $this->attachMeasureCounts($upgradeCta, $plan);
        $showUpgradeCta = $subscription === null || $subscription->getStatus() === ProjectSubscription::STATUS_CANCELLED;
        $pendingUpgrade = $this->buildPendingUpgrade($subscription, $upgradeCta);
        $canManageBilling = $this->isGranted(ProjectVoter::EDIT, $project);
        $billingDocuments = [];
        $hasLocalBillingDocument = false;
        $documents = $subscription instanceof ProjectSubscription
            ? $this->billingDocumentRepository->findBySubscriptionOrdered($subscription)
            : [];
        foreach ($documents as $document) {
            $hasLocalCopy = $this->invoiceStorageService->hasLocalCopy($document);
            $hasLocalBillingDocument = $hasLocalBillingDocument || $hasLocalCopy;

            $billingDocuments[] = [
                'document' => $document,
                'hasLocalCopy' => $hasLocalCopy,
            ];
        }

        return [
            'project' => $project,
            'subscription' => $subscription,
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
        if (($billing['billingDocuments'] ?? []) !== []) {
            return true;
        }

        $subscription = $billing['subscription'] ?? null;
        if (!$subscription instanceof ProjectSubscription) {
            return false;
        }

        return $subscription->getTier() !== ProjectSubscription::TIER_BASIC
            || $subscription->getPaidAmountCents() !== null
            || $subscription->getPaidAt() !== null
            || $subscription->getPaymentReference() !== null
            || $subscription->getStripeCheckoutSessionId() !== null
            || $subscription->getStripePaymentIntentId() !== null
            || $subscription->getStripeInvoiceId() !== null
            || $subscription->getLastPaymentStatus() !== null
            || $subscription->getTargetTier() !== null;
    }

    #[Route('/{id}/plans/{phase}', name: 'plan_comparison', methods: ['GET'], requirements: ['phase' => 'elaboration|implementation'])]
    public function comparison(Project $project, CommercialPhase $phase, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->activeProjectService->setActiveProject($project);

        $subscription = $project->getSubscriptionForPhase($phase);
        $availableUpgradeTargets = $subscription instanceof ProjectSubscription
            ? $this->checkoutService->getAvailableUpgradeTargets($project, $phase)
            : [];
        $comparison = $this->commercialPlanComparisonBuilder->build(
            $phase,
            $this->resolveProjectTier($project, $phase),
            $this->planRepository->findOneBy(['project' => $project]),
            $availableUpgradeTargets,
        );
        $origin = $this->resolveComparisonOrigin($request);

        return $this->render('backend/commercial_plan/comparison.html.twig', [
            'project' => $project,
            'phase' => $phase,
            'phaseLabel' => $this->translator->trans('backend.commercial_phases.'.$phase->value),
            'comparison' => $comparison,
            'origin' => $origin,
            'backUrl' => $this->resolveComparisonBackUrl($project, $phase, $origin),
        ]);
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
            'review' => $this->generateUrl('backend_plan_review', ['state' => 'implement']),
            'billing' => $this->generateUrl('backend_project_billing', [
                'phase' => $phase->value,
                '_fragment' => 'billing-project-'.$project->getId(),
            ]),
            default => $this->generateUrl('backend_project_edit', ['id' => $project->getId()]),
        };
    }

    private function redirectToBilling(CommercialPhase $phase = CommercialPhase::ELABORATION): RedirectResponse
    {
        return $this->redirectToRoute('backend_project_billing', [
            'phase' => $phase->value,
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
                'label' => $this->translator->trans('backend.plan.upgrade.price_id_missing'),
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

    private function attachMeasureCounts(array $upgradeCta, ?Plan $plan): array
    {
        $protocol = $plan?->getProtocol();
        if (!$protocol) {
            return $upgradeCta;
        }

        foreach ($upgradeCta['options'] ?? [] as $index => $option) {
            $upgradeCta['options'][$index]['measureCount'] = $this->measureRepository->countCatalogMeasuresForProtocol(
                $protocol,
                $option['allowedScores'] ?? []
            );
        }

        foreach ($upgradeCta['commercialPlans'] ?? [] as $tier => $commercialPlan) {
            if (!$commercialPlan instanceof CommercialPlan) {
                continue;
            }

            $upgradeCta['measureCounts'][$tier] = $this->measureRepository->countCatalogMeasuresForProtocol(
                $protocol,
                $commercialPlan->getAllowedScores()
            );
        }

        return $upgradeCta;
    }

    private function resolveProjectTier(Project $project, CommercialPhase $phase): string
    {
        return $project->getSubscriptionForPhase($phase)?->getTier() ?? ProjectSubscription::TIER_BASIC;
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
