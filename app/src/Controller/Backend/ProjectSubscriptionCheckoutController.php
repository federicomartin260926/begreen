<?php

namespace App\Controller\Backend;

use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
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
            return $this->redirectToRoute('backend_plan_review');
        }

        try {
            $checkoutUrl = $this->checkoutService->startCheckout($project, $targetTier, $this->getUser());
        } catch (\InvalidArgumentException) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('warning', 'backend.subscription.flash.upgrade_not_allowed');
            return $this->redirectToRoute('backend_plan_review');
        } catch (\Throwable) {
            $this->activeProjectService->setActiveProject($project);
            $this->addFlash('danger', 'backend.subscription.flash.checkout_failed');
            return $this->redirectToRoute('backend_plan_review');
        }

        return new RedirectResponse($checkoutUrl);
    }

    #[Route('/{id}/subscription/success', name: 'subscription_success', methods: ['GET'])]
    public function success(Project $project, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $this->activeProjectService->setActiveProject($project);

        $subscription = $project->getSubscription();
        $sessionId = (string) $request->query->get('session_id', '');
        if ($subscription && $subscription->getStatus() === ProjectSubscription::STATUS_ACTIVE && $subscription->getTier() !== ProjectSubscription::TIER_BASIC) {
            $this->addFlash('success', 'backend.subscription.flash.success_confirmed');
        } elseif ($subscription && $subscription->getStatus() === ProjectSubscription::STATUS_PENDING_PAYMENT) {
            if ($sessionId !== '' && $subscription->getStripeCheckoutSessionId() === $sessionId) {
                $this->addFlash('info', 'backend.subscription.flash.success_pending');
            } else {
                $this->addFlash('info', 'backend.subscription.flash.success_received');
            }
        } elseif ($sessionId !== '' && $subscription && $subscription->getStripeCheckoutSessionId() === $sessionId) {
            $this->addFlash('info', 'backend.subscription.flash.success_pending');
        } else {
            $this->addFlash('info', 'backend.subscription.flash.success_received');
        }

        return $this->redirectToRoute('backend_plan_review');
    }

    #[Route('/{id}/subscription/cancel', name: 'subscription_cancel', methods: ['GET'])]
    public function cancel(Project $project, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $subscription = $project->getSubscription();
        $sessionId = (string) $request->query->get('session_id', '');
        if (
            $subscription
            && $subscription->getStatus() === ProjectSubscription::STATUS_PENDING_PAYMENT
            && ($sessionId === '' || $subscription->getStripeCheckoutSessionId() === $sessionId)
        ) {
            $subscription
                ->setStatus(ProjectSubscription::STATUS_CANCELLED)
                ->setLastPaymentStatus('cancelled')
                ->setTargetTier(null);

            $entityManager->flush();
        }

        $this->activeProjectService->setActiveProject($project);
        $this->addFlash('warning', 'backend.subscription.flash.cancelled');

        return $this->redirectToRoute('backend_plan_review');
    }
}
