<?php

namespace App\Controller\Backend;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Exception\PendingStripeCheckoutException;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\StripeCheckoutReconciliationResult;
use App\Service\StripeProjectCheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/project', name: 'backend_project_')]
#[IsGranted('ROLE_USER')]
final class ProjectSubscriptionCheckoutController extends AbstractController
{
    private const REVIEW_DEFAULTS = [
        'is_applicable' => '1',
        'will_implement' => '1',
    ];

    public function __construct(
        private readonly StripeProjectCheckoutService $checkoutService,
        private readonly ActiveProjectService $activeProjectService,
    ) {
    }

    #[Route('/{id}/subscription/checkout/{targetTier}', name: 'subscription_checkout', methods: ['POST'])]
    public function checkout(Project $project, string $targetTier, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        if (!$this->isCsrfTokenValid('project_subscription_checkout_'.$project->getId().'_'.$targetTier, (string) $request->request->get('_token'))) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('danger', 'backend.subscription.flash.invalid_token');
            return $this->redirectToRoute('backend_plan_review', self::REVIEW_DEFAULTS);
        }

        try {
            $checkoutUrl = $this->checkoutService->startCheckout($project, $targetTier, $this->getUser());
        } catch (\InvalidArgumentException) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('warning', 'backend.subscription.flash.upgrade_not_allowed');
            return $this->redirectToRoute('backend_plan_review', self::REVIEW_DEFAULTS);
        } catch (PendingStripeCheckoutException) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('warning', 'backend.subscription.flash.pending_payment_exists');
            return $this->redirectToRoute('backend_plan_review', self::REVIEW_DEFAULTS);
        } catch (\Throwable) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('danger', 'backend.subscription.flash.checkout_failed');
            return $this->redirectToRoute('backend_plan_review', self::REVIEW_DEFAULTS);
        }

        return new RedirectResponse($checkoutUrl);
    }

    #[Route('/{id}/subscription/success', name: 'subscription_success', methods: ['GET'])]
    public function success(Project $project, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $this->activeProjectService->setActiveProject($project);

        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $sessionId = (string) $request->query->get('session_id', '');
        if ($sessionId !== '') {
            $reconciliation = $this->checkoutService->reconcilePendingCheckout($project, $sessionId);
            if ($reconciliation->isConfirmed()) {
                $this->addFlash('success', 'backend.subscription.flash.success_confirmed');
            } elseif ($reconciliation->status === StripeCheckoutReconciliationResult::STATUS_PENDING) {
                $this->addFlash('info', 'backend.subscription.flash.success_pending');
            } elseif ($reconciliation->status === StripeCheckoutReconciliationResult::STATUS_MISMATCH) {
                $this->addFlash('danger', 'backend.subscription.flash.reconcile_mismatch');
            } elseif ($reconciliation->status === StripeCheckoutReconciliationResult::STATUS_ERROR) {
                $this->addFlash('danger', 'backend.subscription.flash.reconcile_failed');
            } else {
                $this->addFlash('info', 'backend.subscription.flash.success_received');
            }

            if ($reconciliation->planBecameIncompleteAfterUpgrade()) {
                $redirectParams = [];
                $pendingIndex = $reconciliation->firstPendingVisibleMeasureIndex();
                if ($pendingIndex !== null) {
                    $redirectParams['i'] = $pendingIndex;
                }

                return $this->redirectToRoute('backend_plan_measures', $redirectParams);
            }
        } else {
            if ($subscription && $subscription->getStatus() === ProjectSubscription::STATUS_ACTIVE && $subscription->getTier() !== ProjectSubscription::TIER_BASIC) {
                $this->addFlash('success', 'backend.subscription.flash.success_confirmed');
            } elseif ($subscription && $subscription->getStatus() === ProjectSubscription::STATUS_PENDING_PAYMENT) {
                $this->addFlash('info', 'backend.subscription.flash.success_pending');
            } else {
                $this->addFlash('info', 'backend.subscription.flash.success_received');
            }
        }

        return $this->redirectToRoute('backend_plan_review', self::REVIEW_DEFAULTS);
    }

    #[Route('/{id}/subscription/confirm-pending', name: 'subscription_confirm_pending', methods: ['POST'])]
    public function confirmPending(Project $project, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $returnFrom = $request->query->getString('from') === 'index' ? 'index' : 'project';

        if (!$this->isCsrfTokenValid('project_subscription_confirm_pending_'.$project->getId(), (string) $request->request->get('_token'))) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('danger', 'backend.subscription.flash.invalid_token');
            return $this->redirectToRoute('backend_project_billing', ['id' => $project->getId(), 'from' => $returnFrom]);
        }

        $reconciliation = $this->checkoutService->reconcilePendingCheckout($project);
        if ($reconciliation->isConfirmed()) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('success', 'backend.subscription.flash.success_confirmed');
            return $this->redirectToRoute('backend_project_billing', ['id' => $project->getId(), 'from' => $returnFrom]);
        }

        if ($reconciliation->status === StripeCheckoutReconciliationResult::STATUS_PENDING) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('info', 'backend.subscription.flash.success_pending');
            return $this->redirectToRoute('backend_project_billing', ['id' => $project->getId(), 'from' => $returnFrom]);
        }

        if ($reconciliation->status === StripeCheckoutReconciliationResult::STATUS_MISMATCH) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('danger', 'backend.subscription.flash.reconcile_mismatch');
            return $this->redirectToRoute('backend_project_billing', ['id' => $project->getId(), 'from' => $returnFrom]);
        }

        if ($reconciliation->status === StripeCheckoutReconciliationResult::STATUS_ERROR) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('danger', 'backend.subscription.flash.reconcile_failed');
            return $this->redirectToRoute('backend_project_billing', ['id' => $project->getId(), 'from' => $returnFrom]);
        }

        $this->activeProjectService->setActiveProject($project);
        $this->addFlash('info', 'backend.subscription.flash.success_received');

        return $this->redirectToRoute('backend_project_billing', ['id' => $project->getId(), 'from' => $returnFrom]);
    }

    #[Route('/{id}/subscription/cancel', name: 'subscription_cancel', methods: ['GET'])]
    public function cancel(Project $project, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $sessionId = (string) $request->query->get('session_id', '');
        if (
            $subscription
            && $subscription->getTargetTier() !== null
            && ($sessionId === '' || $subscription->getStripeCheckoutSessionId() === $sessionId)
        ) {
            $subscription
                ->setStripeCheckoutSessionId(null)
                ->setTargetTier(null);

            if ($subscription->getPaidAmountCents() !== null || $subscription->getPaidAt() !== null) {
                $subscription->setLastPaymentStatus('paid');
            } else {
                $subscription->setLastPaymentStatus(null);
            }

            $entityManager->flush();
        }

        $this->activeProjectService->setActiveProject($project);
        $this->addFlash('warning', 'backend.subscription.flash.cancelled');

        return $this->redirectToRoute('backend_plan_review', self::REVIEW_DEFAULTS);
    }
}
