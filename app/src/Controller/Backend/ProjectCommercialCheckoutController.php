<?php

namespace App\Controller\Backend;

use App\Entity\Project;
use App\Exception\PendingStripeCheckoutException;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\ProjectCommercialCheckoutService;
use App\Service\ProjectCommercialReturnService;
use App\Service\ProjectCommercialReturnUrlBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Project-level Stripe TEST Checkout; legacy phase-scoped routes remain unchanged. */
#[Route('/backend/project', name: 'backend_project_')]
#[IsGranted('ROLE_USER')]
final class ProjectCommercialCheckoutController extends AbstractController
{
    public function __construct(
        private readonly ProjectCommercialCheckoutService $checkoutService,
        private readonly ProjectCommercialReturnService $returnService,
        private readonly ProjectCommercialReturnUrlBuilder $returnUrls,
        private readonly ActiveProjectService $activeProjectService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private readonly string $stripeSecretKey,
        #[Autowire('%env(bool:BEGREEN_PROJECT_CHECKOUT_ENABLED)%')]
        private readonly bool $checkoutEnabled,
    ) {
    }

    #[Route('/{id}/commercial-checkout/start/{targetTier}', name: 'commercial_checkout_start', methods: ['POST'], requirements: ['targetTier' => 'standard|pro'])]
    public function start(Project $project, string $targetTier, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $phase = self::returnPhase($request);
        $this->activeProjectService->setActiveProject($project);
        if (!$this->isCsrfTokenValid('project_commercial_checkout_start_' . $project->getId() . '_' . $targetTier, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.subscription.flash.invalid_token');
            return $this->comparison($project, $phase);
        }
        if (!$this->checkoutEnabled || !str_starts_with($this->stripeSecretKey, 'sk_test_')) {
            $this->addFlash('danger', 'El Checkout de pruebas no está habilitado en este entorno.');
            return $this->comparison($project, $phase);
        }

        try {
            $urls = $this->returnUrls->build((int) $project->getId(), $phase);
            $checkoutUrl = $this->checkoutService->startCheckout(
                $project, $targetTier, $urls['success_url'], $urls['cancel_url']
            );
            $parts = parse_url($checkoutUrl);
            if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'checkout.stripe.com') {
                throw new \DomainException('Stripe did not return a trusted hosted Checkout URL.');
            }

            return new RedirectResponse($checkoutUrl, 303);
        } catch (PendingStripeCheckoutException) {
            $this->addFlash('warning', 'Ya hay un intento de pago que debe verificarse antes de iniciar otro.');
        } catch (\InvalidArgumentException | \DomainException) {
            $this->addFlash('warning', 'No se ha podido iniciar este cambio de plan. Comprueba el proyecto y los pagos pendientes.');
        } catch (\Throwable $error) {
            $this->logger->error('BEGREEN project Stripe Checkout failed', ['exception_type' => $error::class]);
            $this->addFlash('danger', 'Stripe no está disponible para iniciar el pago. No se ha confirmado ningún cambio de plan.');
        }

        return $this->comparison($project, $phase);
    }

    #[Route('/{id}/commercial-checkout/success', name: 'commercial_checkout_success', methods: ['GET'])]
    public function success(Project $project, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->activeProjectService->setActiveProject($project);
        $phase = self::returnPhase($request);
        $sessionId = $request->query->get('session_id');
        if (!is_string($sessionId) || $sessionId === '') {
            $this->addFlash('danger', 'Stripe no ha devuelto una sesión de pago válida. Verifica el pago manualmente.');
            return $this->comparison($project, $phase);
        }

        $this->reconcileAndFlash($project, $sessionId);
        return $this->comparison($project, $phase);
    }

    #[Route('/{id}/commercial-checkout/cancel', name: 'commercial_checkout_cancel', methods: ['GET'])]
    public function cancel(Project $project, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->activeProjectService->setActiveProject($project);
        $this->addFlash('info', 'backend.subscription.flash.cancel_return');

        // Stripe cancellation is not proof that an earlier payment has failed.
        return $this->comparison($project, self::returnPhase($request));
    }

    #[Route('/{id}/commercial-checkout/verify', name: 'commercial_checkout_verify', methods: ['POST'])]
    public function verify(Project $project, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->activeProjectService->setActiveProject($project);
        $phase = self::returnPhase($request);
        if (!$this->isCsrfTokenValid('project_commercial_checkout_verify_' . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'backend.subscription.flash.invalid_token');
            return $this->comparison($project, $phase);
        }
        if (!str_starts_with($this->stripeSecretKey, 'sk_test_')) {
            $this->addFlash('danger', 'La verificación de pruebas de Stripe no está habilitada en este entorno.');
            return $this->comparison($project, $phase);
        }

        $this->reconcileAndFlash($project, null);
        return $this->comparison($project, $phase);
    }

    private function reconcileAndFlash(Project $project, ?string $sessionId): void
    {
        try {
            $result = $this->returnService->reconcile($project, $sessionId);
            [$type, $message] = match ($result) {
                ProjectCommercialReturnService::RESULT_PAID => ['success', 'backend.subscription.flash.success_confirmed'],
                ProjectCommercialReturnService::RESULT_PAID_REVIEW => ['warning', 'Stripe ha confirmado el pago, pero la activación requiere revisión manual.'],
                ProjectCommercialReturnService::RESULT_EXPIRED => ['warning', 'backend.subscription.flash.checkout_expired'],
                ProjectCommercialReturnService::RESULT_PENDING => ['info', 'backend.subscription.flash.success_pending'],
                ProjectCommercialReturnService::RESULT_FAILED => ['danger', 'backend.subscription.flash.reconcile_failed'],
                default => ['info', 'No hay un pago pendiente de confirmar para este proyecto.'],
            };
            $this->addFlash($type, $message);
        } catch (\DomainException $error) {
            // Log only a whitelisted, non-sensitive cause; never log Stripe IDs or credentials.
            $reason = match ($error->getMessage()) {
                'The project must be persisted before payment verification.' => 'project_not_persisted',
                'The Stripe session is not associated with this project.' => 'session_not_associated',
                'The pending checkout has no persisted Stripe identity.' => 'missing_stripe_identity',
                'The returned Stripe session does not match the pending checkout.' => 'returned_session_mismatch',
                'Stripe session identity or TEST environment does not match the checkout.' => 'stripe_identity_mismatch',
                'Unexpected Stripe checkout status.' => 'unexpected_stripe_status',
                'Stripe checkout has no valid amount or currency.' => 'invalid_amount_or_currency',
                'Invalid Stripe project checkout identity.' => 'invalid_payment_identity',
                'Unknown Stripe project payment.' => 'unknown_payment',
                'Stripe checkout metadata does not match the project payment reservation.' => 'payment_metadata_mismatch',
                default => 'unclassified_domain_exception',
            };
            $this->logger->warning('BEGREEN project Stripe reconciliation rejected', [
                'project_id' => $project->getId(),
                'reason' => $reason,
            ]);
            $this->addFlash('danger', 'La sesión de Stripe no corresponde a este proyecto o contiene datos inconsistentes.');
        } catch (\Throwable $error) {
            $this->logger->error('BEGREEN project Stripe reconciliation failed', ['exception_type' => $error::class]);
            $this->addFlash('danger', 'No se pudo verificar el pago con Stripe. Inténtalo de nuevo desde BEGREEN.');
        }
    }

    private function comparison(Project $project, string $phase): RedirectResponse
    {
        return $this->redirectToRoute('backend_project_plan_comparison', [
            'id' => $project->getId(),
            'phase' => $phase,
        ]);
    }

    private static function returnPhase(Request $request): string
    {
        $input = $request->isMethod('POST') ? $request->request->get('phase') : $request->query->get('phase');
        return $input === 'implementation' ? 'implementation' : 'elaboration';
    }
}
