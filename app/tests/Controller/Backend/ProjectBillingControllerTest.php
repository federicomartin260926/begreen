<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectBillingController;
use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectMembership;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Entity\User;
use App\Repository\CommercialPlanRepository;
use App\Repository\MeasureRepository;
use App\Repository\PlanRepository;
use App\Repository\ProjectBillingDocumentRepository;
use App\Service\ActiveProjectService;
use App\Service\StripeProjectCheckoutService;
use App\Service\StripeInvoiceStorageService;
use App\Tests\Support\CommercialPlanTestHelpers;
use App\Tests\Support\Stripe\FakeStripeClient;
use App\Tests\Support\Stripe\FakeStripeCheckoutFacade;
use App\Tests\Support\Stripe\FakeStripeCheckoutSessions;
use App\Tests\Support\Stripe\FakeStripeInvoicesFacade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ProjectBillingControllerTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testBillingPageShowsDocumentsAndActions(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(86, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setStripeCheckoutSessionId('cs_test_86')
            ->setStripePaymentIntentId('pi_test_86')
            ->setStripeInvoiceId('in_test_86')
            ->setStripeCustomerId('cus_test_86')
            ->setPaymentReference('INV-TEST-86')
            ->setStripeHostedInvoiceUrl('https://invoice.test/view')
            ->setStripeInvoicePdfUrl('https://invoice.test/pdf');

        $document = $this->createDocument($project, $subscription, 12);
        $document
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPurchaseLabel('Elaboración Standard')
            ->setPaymentReference('INV-TEST-86')
            ->setStripeCheckoutSessionId('cs_test_86')
            ->setStripePaymentIntentId('pi_test_86')
            ->setStripeInvoiceId('in_test_86')
            ->setStripeCustomerId('cus_test_86')
            ->setHostedInvoiceUrl('https://invoice.test/view')
            ->setInvoicePdfUrl('https://invoice.test/pdf')
            ->setLocalPath('stripe-invoices/project-86/invoice-in_test_86.pdf')
            ->setDownloadedAt(new \DateTimeImmutable('2026-06-04 11:00:00'))
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'));

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'index']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findBySubscriptionOrdered')
            ->with($subscription)
            ->willReturn([$document]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('hasLocalCopy')
            ->with($document)
            ->willReturn(true);

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Plan de Elaboración contratado', $content);
        self::assertStringContainsString('Fases', $content);
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing/elaboration', $content);
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing/implementation', $content);
        self::assertStringContainsString('Documentos de facturación', $content);
        self::assertStringContainsString('Ver factura', $content);
        self::assertStringContainsString('Descargar PDF', $content);
        self::assertStringContainsString('Concepto', $content);
        self::assertStringContainsString('Elaboración Standard', $content);
        self::assertStringNotContainsString('Actualizar referencias desde Stripe', $content);
        self::assertStringContainsString('billing/elaboration/document/12/view?from=index', $content);
        self::assertStringContainsString('billing/elaboration/document/12/download?from=index', $content);
        self::assertStringContainsString('billingDocumentModal12', $content);
        self::assertStringContainsString('Abrir en nueva pestaña', $content);
        self::assertStringContainsString('INV-TEST-86', $content);
        self::assertStringContainsString('cs_test_86', $content);
        self::assertStringContainsString('pi_test_86', $content);
    }

    public function testBillingPageShowsImplementationTabAndKeepsDocumentsSeparatedByPhase(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(86, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $elaborationSubscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $elaborationSubscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid');

        $implementationSubscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_STANDARD)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-05 10:00:00'))
            ->setLastPaymentStatus('paid');
        $project->addSubscription($implementationSubscription);

        $elaborationDocument = $this->createDocument($project, $elaborationSubscription, 12);
        $elaborationDocument
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPurchaseLabel('Elaboración Standard')
            ->setPaymentReference('INV-ELAB-86')
            ->setStripeCheckoutSessionId('cs_elab_86')
            ->setStripePaymentIntentId('pi_elab_86')
            ->setStripeInvoiceId('in_elab_86')
            ->setStripeCustomerId('cus_elab_86')
            ->setHostedInvoiceUrl('https://invoice.test/elaboration')
            ->setInvoicePdfUrl('https://invoice.test/elaboration.pdf')
            ->setLocalPath('stripe-invoices/project-86/invoice-in_elab_86.pdf')
            ->setDownloadedAt(new \DateTimeImmutable('2026-06-04 11:00:00'))
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'));

        $implementationDocument = $this->createDocument($project, $implementationSubscription, 21);
        $implementationDocument
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPurchaseLabel('Implementación Standard')
            ->setPaymentReference('INV-IMPL-86')
            ->setStripeCheckoutSessionId('cs_impl_86')
            ->setStripePaymentIntentId('pi_impl_86')
            ->setStripeInvoiceId('in_impl_86')
            ->setStripeCustomerId('cus_impl_86')
            ->setHostedInvoiceUrl('https://invoice.test/implementation')
            ->setInvoicePdfUrl('https://invoice.test/implementation.pdf')
            ->setLocalPath('stripe-invoices/project-86/invoice-in_impl_86.pdf')
            ->setDownloadedAt(new \DateTimeImmutable('2026-06-05 11:00:00'))
            ->setPaidAt(new \DateTimeImmutable('2026-06-05 10:00:00'));

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'index']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::exactly(2))
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::exactly(2))
            ->method('findBySubscriptionOrdered')
            ->willReturnCallback(static function (ProjectSubscription $subscription) use ($elaborationDocument, $implementationDocument): array {
                return $subscription->getPhase() === CommercialPhase::ELABORATION
                    ? [$elaborationDocument]
                    : [$implementationDocument];
            });

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::exactly(2))
            ->method('hasLocalCopy')
            ->willReturnCallback(static function (ProjectBillingDocument $document): bool {
                return true;
            });

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $elaborationResponse = $controller($project, CommercialPhase::ELABORATION, $request);
        $elaborationContent = $elaborationResponse->getContent();

        self::assertIsString($elaborationContent);
        self::assertStringContainsString('Fases', $elaborationContent);
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing/elaboration', $elaborationContent);
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing/implementation', $elaborationContent);
        self::assertStringContainsString('Elaboración Standard', $elaborationContent);
        self::assertStringNotContainsString('Implementación Standard', $elaborationContent);

        $implementationRequest = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::IMPLEMENTATION->value], ['from' => 'index']);
        $implementationResponse = $controller($project, CommercialPhase::IMPLEMENTATION, $implementationRequest);
        $implementationContent = $implementationResponse->getContent();

        self::assertIsString($implementationContent);
        self::assertStringContainsString('Fases', $implementationContent);
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing/elaboration', $implementationContent);
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing/implementation', $implementationContent);
        self::assertStringContainsString('Implementación Standard', $implementationContent);
        self::assertStringNotContainsString('Elaboración Standard', $implementationContent);
    }

    public function testBillingPageHidesStripeTechnicalDetailsForNormalUsers(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $userEmail = sprintf('user89_%s@example.test', uniqid());

        $user = (new User())
            ->setName('User')
            ->setSurnames('Normal')
            ->setEmail($userEmail)
            ->setPassword('password')
            ->setRoles(['ROLE_USER']);
        $entityManager->persist($user);
        $entityManager->flush();

        $project = (new Project())
            ->setName('Proyecto normal')
            ->setType('rodaje')
            ->setCountry('ES')
            ->setUser($user);

        $subscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setTier(ProjectSubscription::TIER_STANDARD)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setStripeCheckoutSessionId('cs_test_89')
            ->setStripePaymentIntentId('pi_test_89')
            ->setStripeInvoiceId('in_test_89')
            ->setStripeCustomerId('cus_test_89')
            ->setPaymentReference('cs_test_a1LvgirVsq49dTzNPCjqSpsqVAaHQvHFo266VsnvqaVEDIhX8AiLUFxIcE')
            ->setStripeHostedInvoiceUrl('https://invoice.test/view')
            ->setStripeInvoicePdfUrl('https://invoice.test/pdf');
        $project->addSubscription($subscription);

        $membership = (new ProjectMembership())
            ->setUser($user)
            ->setProject($project)
            ->setProjectRole('member');
        $project->addProjectMembership($membership);

        $entityManager->persist($project);
        $entityManager->persist($membership);
        $entityManager->persist($subscription);
        $entityManager->flush();

        $this->setUserToken($user);

        $document = $this->createDocument($project, $subscription, 13);
        $document
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaymentReference('cs_test_a1LvgirVsq49dTzNPCjqSpsqVAaHQvHFo266VsnvqaVEDIhX8AiLUFxIcE')
            ->setStripeCheckoutSessionId('cs_test_89')
            ->setStripePaymentIntentId('pi_test_89')
            ->setStripeInvoiceId('in_test_89')
            ->setStripeCustomerId('cus_test_89')
            ->setHostedInvoiceUrl('https://invoice.test/view')
            ->setInvoicePdfUrl('https://invoice.test/pdf')
            ->setLocalPath('stripe-invoices/project-89/invoice-in_test_89.pdf')
            ->setDownloadedAt(new \DateTimeImmutable('2026-06-04 11:00:00'))
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'));

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'index']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findBySubscriptionOrdered')
            ->with($subscription)
            ->willReturn([$document]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('hasLocalCopy')
            ->with($document)
            ->willReturn(true);

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Ver factura', $content);
        self::assertStringContainsString('Descargar PDF', $content);
        self::assertStringContainsString('Referencia de pago', $content);
        self::assertStringContainsString('cs_test_a1Lv...UFxIcE', $content);
        self::assertStringNotContainsString('cs_test_a1LvgirVsq49dTzNPCjqSpsqVAaHQvHFo266VsnvqaVEDIhX8AiLUFxIcE', $content);
        self::assertStringNotContainsString('Checkout Session ID', $content);
        self::assertStringNotContainsString('Payment Intent ID', $content);
        self::assertStringNotContainsString('Invoice ID', $content);
        self::assertStringNotContainsString('Customer ID', $content);
        self::assertStringNotContainsString('Actualizar referencias desde Stripe', $content);
        self::assertStringNotContainsString('Verificar pago en Stripe', $content);
        self::assertStringNotContainsString('Abrir en Stripe', $content);
        self::assertStringNotContainsString('Sincronizar desde Stripe', $content);
    }

    public function testBillingPageShowsEmptyStateWhenThereAreNoDocuments(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(87, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid');

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value]);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findBySubscriptionOrdered')
            ->with($subscription)
            ->willReturn([]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::never())->method('hasLocalCopy');

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('No hay documentos de facturación todavía', $content);
        self::assertStringNotContainsString('Ver factura', $content);
        self::assertStringNotContainsString('Descargar PDF', $content);
    }

    public function testBillingPageShowsUpgradeCtaWhenThereIsNoActivePlan(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = (new Project())
            ->setName('Proyecto sin plan')
            ->setType('rodaje')
            ->setCountry('ES');
        $this->setEntityId($project, 90);

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'project']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::never())->method('findBySubscriptionOrdered');

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::never())->method('hasLocalCopy');

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('No hay un plan activo para este proyecto.', $content);
        self::assertStringContainsString('Stripe Price ID no configurado', $content);
        self::assertStringNotContainsString('projectBillingUpgradeModal', $content);
        self::assertStringNotContainsString('Actualizar a Standard', $content);
        self::assertStringNotContainsString('Actualizar a Pro', $content);
    }

    public function testBillingPageShowsPendingUpgradeWhenThereIsAnActiveUpgradeCheckout(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(91, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('checkout_created')
            ->setStripeCheckoutSessionId('cs_upgrade_91')
            ->setTargetTier(ProjectSubscription::TIER_PRO);

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'project']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findBySubscriptionOrdered')
            ->with($subscription)
            ->willReturn([]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::never())->method('hasLocalCopy');

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Actualización pendiente', $content);
        self::assertStringContainsString('20,00 €', $content);
    }

    public function testBillingPageShowsContinuePaymentForReusablePendingCheckout(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(94, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setLastPaymentStatus('checkout_created')
            ->setStripeCheckoutSessionId('cs_pending_open')
            ->setTargetTier(ProjectSubscription::TIER_PRO);

        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');
        $plans['implementation_standard']->setStripePriceId('price_implementation_standard');
        $plans['implementation_pro']->setStripePriceId('price_implementation_pro_full');
        $plans['implementation_pro']->setStripeUpgradeFromStandardPriceId('price_implementation_upgrade');

        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_pending_open',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'url' => 'https://stripe.test/checkout',
            'expires_at' => time() + 3600,
            'metadata' => (object) [
                'project_id' => (string) $project->getId(),
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_PRO,
            ],
        ];

        $checkoutService = $this->createCheckoutService($client, $plans);

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'project']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findBySubscriptionOrdered')
            ->with($subscription)
            ->willReturn([]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::never())->method('hasLocalCopy');

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class), $checkoutService);
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Continuar pago', $content);
        self::assertStringContainsString('Cancelar intento de pago', $content);
        self::assertStringContainsString('Esta sesión de pago sigue abierta', $content);
    }

    public function testBillingPageShowsRetryPaymentForExpiredPendingCheckout(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(95, ProjectSubscription::STATUS_PENDING_PAYMENT, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setLastPaymentStatus('expired')
            ->setStripeCheckoutSessionId('cs_pending_expired')
            ->setTargetTier(ProjectSubscription::TIER_PRO);

        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');
        $plans['implementation_standard']->setStripePriceId('price_implementation_standard');
        $plans['implementation_pro']->setStripePriceId('price_implementation_pro_full');
        $plans['implementation_pro']->setStripeUpgradeFromStandardPriceId('price_implementation_upgrade');

        $client = $this->createFakeStripeClient();
        $client->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_pending_expired',
            'status' => 'expired',
            'payment_status' => 'unpaid',
            'metadata' => (object) [
                'project_id' => (string) $project->getId(),
                'commercial_phase' => CommercialPhase::ELABORATION->value,
                'target_tier' => ProjectSubscription::TIER_PRO,
            ],
        ];

        $checkoutService = $this->createCheckoutService($client, $plans);

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'project']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findBySubscriptionOrdered')
            ->with($subscription)
            ->willReturn([]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::never())->method('hasLocalCopy');

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class), $checkoutService);
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Reintentar pago', $content);
        self::assertStringContainsString('Cancelar intento de pago', $content);
        self::assertStringContainsString('La sesión actual ya no es reutilizable', $content);
    }

    public function testDocumentRoutesServeLocalPdfAndSyncDocument(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(88, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setStripeInvoicePdfUrl('https://invoice.test/pdf')
            ->setStripeHostedInvoiceUrl('https://invoice.test/view')
            ->setStripeInvoiceId('in_test_88');

        $document = $this->createDocument($project, $subscription, 12);
        $document
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaymentReference('INV-TEST-88')
            ->setStripeCheckoutSessionId('cs_test_88')
            ->setStripePaymentIntentId('pi_test_88')
            ->setStripeInvoiceId('in_test_88')
            ->setStripeCustomerId('cus_test_88')
            ->setHostedInvoiceUrl('https://invoice.test/view')
            ->setInvoicePdfUrl('https://invoice.test/pdf')
            ->setLocalPath('stripe-invoices/project-88/invoice-in_test_88.pdf')
            ->setDownloadedAt(new \DateTimeImmutable('2026-06-04 11:00:00'));

        $projectDir = sys_get_temp_dir().'/bgmfbilling_'.uniqid();
        $privateDir = $projectDir.'/var/private/stripe-invoices/project-88';
        mkdir($privateDir, 0777, true);
        $absolutePdf = $privateDir.'/invoice-in_test_88.pdf';
        file_put_contents($absolutePdf, "%PDF-1.4\n% local test pdf\n");

        $viewRequest = $this->pushRequest('backend_project_billing_document_view', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value, 'documentId' => 12], ['from' => 'index']);
        $viewRequest->setSession(new Session(new MockArraySessionStorage()));

        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::exactly(3))
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->method('findOneByProjectAndId')->with($project, 12)->willReturn($document);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->method('getLocalInvoiceAbsolutePath')->willReturn($absolutePdf);
        $invoiceStorage->expects(self::once())
            ->method('hasLocalCopy')
            ->with($document)
            ->willReturn(true);
        $invoiceStorage->expects(self::never())
            ->method('syncInvoicePdf');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $entityManager);
        $controller->setContainer($container);

        $viewResponse = $controller->viewInvoice($project, CommercialPhase::ELABORATION, 12, $viewRequest);
        self::assertInstanceOf(BinaryFileResponse::class, $viewResponse);
        self::assertStringContainsString('inline', (string) $viewResponse->headers->get('content-disposition'));

        $downloadResponse = $controller->downloadInvoice($project, CommercialPhase::ELABORATION, 12, $viewRequest);
        self::assertInstanceOf(BinaryFileResponse::class, $downloadResponse);
        self::assertStringContainsString('attachment', (string) $downloadResponse->headers->get('content-disposition'));

        $syncRequest = $this->pushRequest('backend_project_billing_document_sync', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value, 'documentId' => 12], ['from' => 'index']);
        $syncRequest->request->set('_token', $container->get('security.csrf.token_manager')->getToken('project_billing_document_sync_88_12')->getValue());
        $syncResponse = $controller->syncInvoice($project, CommercialPhase::ELABORATION, 12, $syncRequest);

        self::assertInstanceOf(RedirectResponse::class, $syncResponse);
        self::assertStringContainsString('/backend/project/88/billing/elaboration', $syncResponse->getTargetUrl());
        self::assertStringContainsString('from=index', $syncResponse->getTargetUrl());
    }

    public function testBillingPageShowsRetryInvoiceActionWhenInvoiceIsNotLocallyAvailable(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(92, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setStripeCheckoutSessionId('cs_test_92')
            ->setStripeInvoiceId('in_test_92')
            ->setStripeHostedInvoiceUrl('https://invoice.test/view')
            ->setStripeInvoicePdfUrl(null);

        $document = $this->createDocument($project, $subscription, 14);
        $document
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaymentReference('INV-TEST-92')
            ->setStripeCheckoutSessionId('cs_test_92')
            ->setStripeInvoiceId('in_test_92')
            ->setStripeCustomerId('cus_test_92')
            ->setHostedInvoiceUrl('https://invoice.test/view')
            ->setInvoicePdfUrl(null)
            ->setLocalPath(null)
            ->setDownloadedAt(null);

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value], ['from' => 'project']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findBySubscriptionOrdered')
            ->with($subscription)
            ->willReturn([$document]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('hasLocalCopy')
            ->with($document)
            ->willReturn(false);

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Descargar factura', $content);
        self::assertStringContainsString('Abrir factura en Stripe', $content);
        self::assertStringContainsString('Sin factura disponible todavía', $content);
        self::assertStringNotContainsString('Ver factura', $content);
        self::assertStringNotContainsString('Descargar PDF', $content);
    }

    public function testSyncInvoiceShowsSpecificAdminReasonWhenInvoicePdfIsMissing(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(93, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setStripeCheckoutSessionId('cs_test_93')
            ->setStripeInvoiceId('in_test_93')
            ->setPaymentReference('cs_test_93')
            ->setStripeHostedInvoiceUrl('https://invoice.test/view')
            ->setStripeInvoicePdfUrl(null);

        $document = $this->createDocument($project, $subscription, 15);
        $document
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaymentReference('cs_test_93')
            ->setStripeCheckoutSessionId('cs_test_93')
            ->setStripeInvoiceId('in_test_93')
            ->setHostedInvoiceUrl('https://invoice.test/view')
            ->setInvoicePdfUrl(null)
            ->setLocalPath(null);

        $request = $this->pushRequest('backend_project_billing_document_sync', ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value, 'documentId' => 15], ['from' => 'project']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->request->set('_token', $container->get('security.csrf.token_manager')->getToken('project_billing_document_sync_93_15')->getValue());

        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->method('findOneByProjectAndId')->with($project, 15)->willReturn($document);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('hasLocalCopy')
            ->with($document)
            ->willReturn(false);
        $invoiceStorage->expects(self::once())
            ->method('syncInvoicePdf')
            ->with($document)
            ->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $entityManager);
        $controller->setContainer($container);

        $response = $controller->syncInvoice($project, CommercialPhase::ELABORATION, 15, $request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $request->getSession()->getFlashBag()->all();
        self::assertSame(['backend.billing.project.invoice_pdf_pending'], $flashes['warning'] ?? []);
    }

    private function setAdminToken(): void
    {
        $user = (new User())
            ->setName('Admin')
            ->setSurnames('User')
            ->setEmail('admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN']);

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function setUserToken(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function createBillingController(
        ActiveProjectService $activeProjectService,
        ProjectBillingDocumentRepository $billingRepository,
        StripeInvoiceStorageService $invoiceStorage,
        EntityManagerInterface $entityManager,
        ?StripeProjectCheckoutService $checkoutService = null
    ): ProjectBillingController {
        $plans = $this->makeDefaultCommercialPlans();
        $plans['standard']->setStripePriceId('price_standard');
        $plans['pro']->setStripePriceId('price_pro_full');
        $plans['pro']->setStripeUpgradeFromStandardPriceId('price_upgrade');
        $plans['implementation_standard']->setStripePriceId('price_implementation_standard');
        $plans['implementation_pro']->setStripePriceId('price_implementation_pro_full');
        $plans['implementation_pro']->setStripeUpgradeFromStandardPriceId('price_implementation_upgrade');

        $commercialPlanRepository = $this->createMock(CommercialPlanRepository::class);
        $commercialPlanRepository->method('findActiveByPhaseAndCode')->willReturnCallback(
            static function (CommercialPhase $phase, string $code) use ($plans): ?\App\Entity\CommercialPlan {
                $key = $phase === CommercialPhase::IMPLEMENTATION
                    ? 'implementation_'.strtolower(trim($code))
                    : strtolower(trim($code));

                return $plans[$key] ?? null;
            }
        );
        $planRepository = $this->createMock(PlanRepository::class);
        $planRepository->method('findOneBy')->willReturn(null);
        $measureRepository = self::getContainer()->get(MeasureRepository::class);

        $checkoutService ??= new StripeProjectCheckoutService(
            new \Stripe\StripeClient('sk_test_dummy'),
            $this->makeProjectFeatureGate($plans),
            $commercialPlanRepository,
            $planRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(StripeInvoiceStorageService::class),
            $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class),
            null,
            null,
            self::getContainer()->get(\App\Service\SustainabilityPlanCompletionService::class)
        );

        return new ProjectBillingController(
            $activeProjectService,
            $billingRepository,
            $invoiceStorage,
            $checkoutService,
            $commercialPlanRepository,
            $planRepository,
            $measureRepository,
            self::getContainer()->get('translator'),
            $entityManager
        );
    }

    private function createCheckoutService(FakeStripeClient $client, array $plans): StripeProjectCheckoutService
    {
        $commercialPlanRepository = $this->createMock(CommercialPlanRepository::class);
        $commercialPlanRepository->method('findActiveByPhaseAndCode')->willReturnCallback(
            static function (CommercialPhase $phase, string $code) use ($plans): ?\App\Entity\CommercialPlan {
                $key = $phase === CommercialPhase::IMPLEMENTATION
                    ? 'implementation_'.strtolower(trim($code))
                    : strtolower(trim($code));

                return $plans[$key] ?? null;
            }
        );

        $planRepository = $this->createMock(PlanRepository::class);
        $planRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $urlGenerator = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.test/backend/project/42/subscription/elaboration/success/standard?session_id={CHECKOUT_SESSION_ID}');

        return new StripeProjectCheckoutService(
            $client,
            $this->makeProjectFeatureGate($plans),
            $commercialPlanRepository,
            $planRepository,
            $entityManager,
            $invoiceStorage,
            $urlGenerator,
            'https://example.test/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/success/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
            'https://example.test/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/cancel/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
            self::getContainer()->get(\App\Service\SustainabilityPlanCompletionService::class)
        );
    }

    private function pushRequest(string $route, array $routeParams = [], array $queryParams = []): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_params', $routeParams);
        $request->query->replace($queryParams);
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function createProject(int $id, string $status, string $tier): Project
    {
        $project = new Project();
        $this->setEntityId($project, $id);

        $subscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::ELABORATION)
            ->setTier($tier)
            ->setStatus($status)
            ->setSource(ProjectSubscription::SOURCE_STRIPE);

        $project->addSubscription($subscription);

        return $project;
    }

    private function createDocument(Project $project, ?ProjectSubscription $subscription, int $id): ProjectBillingDocument
    {
        $document = (new ProjectBillingDocument())
            ->setProject($project)
            ->setSubscription($subscription)
            ->setProvider(ProjectBillingDocument::PROVIDER_STRIPE)
            ->setType(ProjectBillingDocument::TYPE_INVOICE);
        $this->setEntityId($document, $id);

        return $document;
    }

    private function createFakeStripeClient(): FakeStripeClient
    {
        return new FakeStripeClient(
            new FakeStripeCheckoutFacade(new FakeStripeCheckoutSessions()),
            new FakeStripeInvoicesFacade(),
        );
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }
}
