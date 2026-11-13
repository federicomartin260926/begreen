<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectBillingController;
use App\Entity\ProjectCommercialPayment;
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
use App\Repository\ProjectCommercialPaymentRepository;
use App\Repository\ProjectMembershipRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\CommercialPlanComparisonBuilder;
use App\Service\ProjectCommercialCheckoutService;
use App\Service\ProjectCommercialInvoiceSyncService;
use App\Service\ProjectCommercialPricing;
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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ProjectBillingControllerTest extends KernelTestCase
{
    use CommercialPlanTestHelpers;

    public function testPlanComparisonPageKeepsSafeOriginAndCheckoutTargets(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(96, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_BASIC);
        $request = $this->pushRequest(
            'backend_project_plan_comparison',
            ['id' => $project->getId(), 'phase' => CommercialPhase::ELABORATION->value],
            ['from' => 'measures']
        );
        $twig = $container->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $controller = $this->createBillingController(
            $activeProjectService,
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(StripeInvoiceStorageService::class),
            $this->createMock(EntityManagerInterface::class)
        );
        $controller->setContainer($container);

        $response = $controller->comparison($project, CommercialPhase::ELABORATION, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('href="/backend/plan/measures"', $content);
        self::assertStringContainsString('La contratación online está temporalmente no disponible.', $content);
        self::assertStringNotContainsString('/backend/project/96/subscription/elaboration/checkout/standard', $content);
        self::assertStringNotContainsString('/backend/project/96/subscription/elaboration/checkout/pro', $content);
        self::assertStringNotContainsString('/backend/project/96/subscription/elaboration/checkout/basic', $content);
    }

    public function testOverviewShowsHistoricalDocumentsFromBothPhasesTogetherWithoutPhaseTabs(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(86, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD)
            ->setName('Proyecto histórico')
            ->setCommercialTier(Project::COMMERCIAL_TIER_PRO);
        $elaborationSubscription = $project->getSubscriptionForPhase(CommercialPhase::ELABORATION);
        $elaborationSubscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(2900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setPaymentReference('INV-ELAB-86');
        $implementationSubscription = (new ProjectSubscription())
            ->setPhase(CommercialPhase::IMPLEMENTATION)
            ->setTier(ProjectSubscription::TIER_PRO)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(4900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-05 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setPaymentReference('INV-IMPL-86');
        $project->addSubscription($implementationSubscription);

        $elaborationDocument = $this->createDocument($project, $elaborationSubscription, 12);
        $elaborationDocument
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(2900)
            ->setCurrency('EUR')
            ->setPurchaseLabel('Elaboración Standard')
            ->setPaymentReference('INV-ELAB-86')
            ->setLocalPath('stripe-invoices/project-86/invoice-elaboration.pdf')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'));
        $implementationDocument = $this->createDocument($project, $implementationSubscription, 21);
        $implementationDocument
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(4900)
            ->setCurrency('EUR')
            ->setPurchaseLabel('Implementación Pro')
            ->setPaymentReference('INV-IMPL-86')
            ->setLocalPath('stripe-invoices/project-86/invoice-implementation.pdf')
            ->setPaidAt(new \DateTimeImmutable('2026-06-05 10:00:00'));

        $this->pushRequest('backend_project_billing_overview');

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::never())->method('setActiveProject');

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::exactly(2))
            ->method('findBySubscriptionOrdered')
            ->willReturnCallback(static fn (ProjectSubscription $subscription): array => $subscription->getPhase() === CommercialPhase::ELABORATION
                ? [$elaborationDocument]
                : [$implementationDocument]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::exactly(2))
            ->method('hasLocalCopy')
            ->willReturn(true);

        $controller = $this->createBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller->overview(...$this->overviewRepositories([$project]));
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Elaboración Standard', $content);
        self::assertStringContainsString('Implementación Pro', $content);
        self::assertStringContainsString('/backend/project/86/billing/elaboration/document/12/view', $content);
        self::assertStringContainsString('/backend/project/86/billing/implementation/document/21/view', $content);
        self::assertStringContainsString('Plan comercial único del proyecto', $content);
        self::assertStringNotContainsString('nav-tabs', $content);
        self::assertStringNotContainsString('/backend/project/billing/implementation', $content);
    }

    public function testOverviewShowsNewProjectPaymentInvoiceAndProjectScopedActions(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $pdf = tempnam(sys_get_temp_dir(), 'bgmf_invoice_');
        self::assertIsString($pdf);
        file_put_contents($pdf, "%PDF-1.4\n% project payment test\n");

        try {
            $project = $this->newPersistableProject('Proyecto pago unificado');
            $entityManager->persist($project);
            $entityManager->flush();
            $payment = $this->newCommercialPayment($project);
            $payment->markCheckoutCreated('cs_project_86', 'https://checkout.stripe.com/c/pay/project-86')
                ->markPaid('pi_project_86', 'in_project_86', 'cus_project_86');
            $entityManager->persist($payment);
            $entityManager->flush();

            $document = $this->createDocument($project, null, 112);
            $document
                ->setStatus(ProjectBillingDocument::STATUS_PAID)
                ->setAmountCents(24900)
                ->setCurrency('EUR')
                ->setStripeCheckoutSessionId('cs_project_86')
                ->setStripeInvoiceId('in_project_86')
                ->setLocalPath('stripe-invoices/project-payment-86.pdf');

            $this->pushRequest('backend_project_billing_overview');
            $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
            $billingRepository->method('findOneBy')->willReturn($document);
            $billingRepository->method('findOneByProjectAndId')->with($project, 112)->willReturn($document);
            $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
            $invoiceStorage->expects(self::once())->method('hasLocalCopy')->with($document)->willReturn(true);
            $invoiceStorage->method('getLocalInvoiceAbsolutePath')->with($document)->willReturn($pdf);
            $controller = $this->createBillingController(
                $this->createMock(ActiveProjectService::class),
                $billingRepository,
                $invoiceStorage,
                $entityManager,
            );
            $controller->setContainer($container);

            $response = $controller->overview(...$this->overviewRepositories([$project]));
            $content = $response->getContent();
            self::assertIsString($content);
            self::assertStringContainsString('Pagos del plan único del proyecto', $content);
            self::assertStringContainsString('BASIC → STANDARD', $content);
            self::assertStringContainsString('249,00 EUR', $content);
            self::assertStringContainsString('Factura Stripe: in_project_86', $content);
            self::assertStringContainsString('/backend/project/'.$project->getId().'/billing/project-document/112/view', $content);
            self::assertStringContainsString('/backend/project/'.$project->getId().'/billing/project-document/112/download', $content);
            self::assertStringNotContainsString('/subscription/elaboration/checkout', $content);
            self::assertInstanceOf(BinaryFileResponse::class, $controller->viewProjectInvoice($project, 112));
            self::assertInstanceOf(BinaryFileResponse::class, $controller->downloadProjectInvoice($project, 112));

            $outsider = (new User())
                ->setName('Sin')
                ->setSurnames('Acceso')
                ->setEmail('outsider_'.uniqid().'@example.test')
                ->setPassword('password')
                ->setRoles(['ROLE_USER']);
            $entityManager->persist($outsider);
            $entityManager->flush();
            $this->setUserToken($outsider);
            try {
                $controller->viewProjectInvoice($project, 112);
                self::fail('A non-member accessed a private project invoice.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            @unlink($pdf);
        }
    }

    public function testOverviewHidesStripeTechnicalDetailsForNormalUsers(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $user = (new User())
                ->setName('User')
                ->setSurnames('Normal')
                ->setEmail('user89_'.uniqid().'@example.test')
                ->setPassword('password')
                ->setRoles(['ROLE_USER']);
            $project = $this->newPersistableProject('Proyecto normal')->setUser($user);
            $subscription = (new ProjectSubscription())
                ->setPhase(CommercialPhase::ELABORATION)
                ->setTier(ProjectSubscription::TIER_STANDARD)
                ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                ->setSource(ProjectSubscription::SOURCE_STRIPE)
                ->setPaymentReference('cs_test_a1LvgirVsq49dTzNPCjqSpsqVAaHQvHFo266VsnvqaVEDIhX8AiLUFxIcE')
                ->setStripeCheckoutSessionId('cs_test_89')
                ->setStripePaymentIntentId('pi_test_89')
                ->setStripeInvoiceId('in_test_89')
                ->setStripeCustomerId('cus_test_89');
            $project->addSubscription($subscription);
            $membership = (new ProjectMembership())->setUser($user)->setProject($project)->setProjectRole('member');
            $project->addProjectMembership($membership);
            $entityManager->persist($user);
            $entityManager->persist($project);
            $entityManager->persist($membership);
            $entityManager->flush();
            $this->setUserToken($user);

            $document = $this->createDocument($project, $subscription, 13);
            $document
                ->setStatus(ProjectBillingDocument::STATUS_PAID)
                ->setPaymentReference('cs_test_a1LvgirVsq49dTzNPCjqSpsqVAaHQvHFo266VsnvqaVEDIhX8AiLUFxIcE')
                ->setStripeCheckoutSessionId('cs_test_89')
                ->setStripePaymentIntentId('pi_test_89')
                ->setStripeInvoiceId('in_test_89')
                ->setStripeCustomerId('cus_test_89')
                ->setHostedInvoiceUrl('https://invoice.test/private')
                ->setLocalPath('stripe-invoices/project-89/invoice.pdf');
            $this->pushRequest('backend_project_billing_overview');
            $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
            $billingRepository->method('findBySubscriptionOrdered')->with($subscription)->willReturn([$document]);
            $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
            $invoiceStorage->method('hasLocalCopy')->with($document)->willReturn(true);
            $controller = $this->createBillingController(
                $this->createMock(ActiveProjectService::class),
                $billingRepository,
                $invoiceStorage,
                $entityManager,
            );
            $controller->setContainer($container);
            $projectRepository = $this->createMock(ProjectRepository::class);
            $membershipRepository = $this->createMock(ProjectMembershipRepository::class);
            $membershipRepository->expects(self::once())->method('projectsOf')->with($user)->willReturn([$project]);
            $container->get('twig')->addGlobal('userProjects', [$project]);
            $container->get('twig')->addGlobal('activeProject', $project);

            $content = $controller->overview($projectRepository, $membershipRepository)->getContent();
            self::assertIsString($content);
            self::assertStringNotContainsString(
                'cs_test_a1LvgirVsq49dTzNPCjqSpsqVAaHQvHFo266VsnvqaVEDIhX8AiLUFxIcE',
                $content,
                'The unified overview must not expose a full Stripe Checkout reference to a normal user.',
            );
            self::assertStringContainsString('cs_test_a1Lv...UFxIcE', $content);
            self::assertStringNotContainsString('pi_test_89', $content);
            self::assertStringNotContainsString('in_test_89', $content);
            self::assertStringNotContainsString('cus_test_89', $content);
            self::assertStringNotContainsString('https://invoice.test/private', $content);
            self::assertStringNotContainsString('Abrir factura en Stripe', $content);
            self::assertStringNotContainsString('Sincronizar factura de Stripe', $content);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testOverviewShowsNoDocumentsAndOmitsProjectsWithoutBillableActivity(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $billable = $this->createProject(87, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD)->setName('Proyecto con actividad');
        $inactive = $this->createProject(88, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_BASIC)->setName('Proyecto sin actividad');
        $this->pushRequest('backend_project_billing_overview');
        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::exactly(2))->method('findBySubscriptionOrdered')->willReturn([]);
        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::never())->method('hasLocalCopy');
        $controller = $this->createBillingController($this->createMock(ActiveProjectService::class), $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);
        $content = $controller->overview(...$this->overviewRepositories([$billable, $inactive]))->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('Proyecto con actividad', $content);
        self::assertStringNotContainsString('Proyecto sin actividad', $content);
        self::assertStringContainsString('No hay documentos de facturación todavía', $content);
        self::assertStringNotContainsString('Ver factura', $content);
        self::assertStringNotContainsString('Descargar PDF', $content);
    }

    public function testComparisonBlocksNewCheckoutWhileProjectPaymentIsPending(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $project = $this->newPersistableProject('Proyecto checkout pendiente');
            $entityManager->persist($project);
            $entityManager->flush();
            $payment = $this->newCommercialPayment($project);
            $payment->markCheckoutCreated('cs_pending_project', 'https://checkout.stripe.com/c/pay/pending', new \DateTimeImmutable('+1 hour'));
            $entityManager->persist($payment);
            $entityManager->flush();
            $request = $this->pushRequest('backend_project_plan_comparison', ['id' => $project->getId(), 'phase' => 'elaboration']);
            $container->get('twig')->addGlobal('userProjects', [$project]);
            $container->get('twig')->addGlobal('activeProject', $project);
            $activeProjectService = $this->createMock(ActiveProjectService::class);
            $activeProjectService->expects(self::once())->method('setActiveProject')->with($project);
            $controller = $this->createBillingController(
                $activeProjectService,
                $this->createMock(ProjectBillingDocumentRepository::class),
                $this->createMock(StripeInvoiceStorageService::class),
                $entityManager,
                checkoutEnabled: true,
            );
            $controller->setContainer($container);
            $content = $controller->comparison($project, CommercialPhase::ELABORATION, $request)->getContent();
            self::assertIsString($content);
            self::assertStringContainsString('intento de pago pendiente de revisar (pending)', $content);
            self::assertStringContainsString('/backend/project/'.$project->getId().'/commercial-checkout/verify', $content);
            self::assertStringNotContainsString('/commercial-checkout/start/', $content);
            self::assertStringNotContainsString('/subscription/elaboration/checkout', $content);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testReusableProjectCheckoutReturnsStoredUrlWithoutCallingStripe(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $project = $this->newPersistableProject('Proyecto checkout reutilizable');
            $entityManager->persist($project);
            $entityManager->flush();
            $payment = $this->newCommercialPayment($project);
            $payment->markCheckoutCreated('cs_reusable_project', 'https://checkout.stripe.com/c/pay/reusable', new \DateTimeImmutable('+1 hour'));
            $entityManager->persist($payment);
            $entityManager->flush();

            $stripe = $this->createFakeStripeClient();
            $service = new ProjectCommercialCheckoutService(
                $stripe,
                $this->createProjectQuoteService($stripe),
                $container->get(ProjectCommercialPaymentRepository::class),
                $entityManager,
            );
            $url = $service->startCheckout($project, Project::COMMERCIAL_TIER_STANDARD, 'https://example.test/success', 'https://example.test/cancel');
            self::assertSame('https://checkout.stripe.com/c/pay/reusable', $url);
            self::assertSame(ProjectCommercialPayment::STATUS_PENDING, $payment->getStatus());
            self::assertSame([], $stripe->checkout->sessions->createCalls);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testExpiredProjectPaymentAllowsFreshProjectCheckoutOffer(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $project = $this->newPersistableProject('Proyecto checkout expirado');
            $entityManager->persist($project);
            $entityManager->flush();
            $payment = $this->newCommercialPayment($project);
            $payment->markCheckoutCreated('cs_expired_project', 'https://checkout.stripe.com/c/pay/expired')
                ->markInterrupted(ProjectCommercialPayment::STATUS_EXPIRED);
            $entityManager->persist($payment);
            $entityManager->flush();
            $request = $this->pushRequest('backend_project_plan_comparison', ['id' => $project->getId(), 'phase' => 'elaboration']);
            $container->get('twig')->addGlobal('userProjects', [$project]);
            $container->get('twig')->addGlobal('activeProject', $project);
            $controller = $this->createBillingController(
                $this->createMock(ActiveProjectService::class),
                $this->createMock(ProjectBillingDocumentRepository::class),
                $this->createMock(StripeInvoiceStorageService::class),
                $entityManager,
                checkoutEnabled: true,
            );
            $controller->setContainer($container);
            $content = $controller->comparison($project, CommercialPhase::ELABORATION, $request)->getContent();
            self::assertIsString($content);
            self::assertStringContainsString('/backend/project/'.$project->getId().'/commercial-checkout/start/standard', $content);
            self::assertStringContainsString('/backend/project/'.$project->getId().'/commercial-checkout/start/pro', $content);
            self::assertStringNotContainsString('intento de pago pendiente de revisar', $content);
            self::assertStringNotContainsString('/subscription/elaboration/checkout', $content);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testHistoricalBillingUrlsRedirectToUnifiedOverview(): void
    {
        self::bootKernel();
        $this->setAdminToken();
        $controller = $this->createBillingController(
            $this->createMock(ActiveProjectService::class),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(StripeInvoiceStorageService::class),
            $this->createMock(EntityManagerInterface::class),
        );
        $controller->setContainer(self::getContainer());

        foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $phase) {
            $this->pushRequest('backend_project_billing', ['phase' => $phase->value]);
            $response = $controller($phase);
            self::assertSame('/backend/project/billing', $response->getTargetUrl());
        }
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
        $activeProjectService->expects(self::never())->method('setActiveProject');

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
        self::assertSame('/backend/project/billing', $syncResponse->getTargetUrl());
    }

    public function testOverviewOffersProjectInvoiceRetryAndRejectsInvalidCsrf(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $project = $this->newPersistableProject('Proyecto factura pendiente');
            $entityManager->persist($project);
            $entityManager->flush();
            $payment = $this->newCommercialPayment($project);
            $payment->markCheckoutCreated('cs_invoice_pending', 'https://checkout.stripe.com/c/pay/invoice-pending')
                ->markPaid('pi_invoice_pending');
            $entityManager->persist($payment);
            $entityManager->flush();

            $request = $this->pushRequest('backend_project_billing_overview');
            $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
            $billingRepository->method('findOneBy')->willReturn(null);
            $controller = $this->createBillingController(
                $this->createMock(ActiveProjectService::class),
                $billingRepository,
                $this->createMock(StripeInvoiceStorageService::class),
                $entityManager,
                checkoutEnabled: true,
            );
            $controller->setContainer($container);
            $content = $controller->overview(...$this->overviewRepositories([$project]))->getContent();
            self::assertIsString($content);
            self::assertStringContainsString('Factura Stripe: pendiente de sincronización', $content);
            self::assertStringContainsString('/backend/project/'.$project->getId().'/billing/project-payment/'.$payment->getId().'/sync-invoice', $content);
            self::assertStringContainsString('Sincronizar factura de Stripe', $content);
            self::assertStringNotContainsString('/billing/project-document/', $content);

            $request->request->set('_token', 'invalid-token');
            try {
                $controller->syncProjectInvoice(
                    $project,
                    (int) $payment->getId(),
                    $request,
                    new ProjectCommercialInvoiceSyncService(
                        $this->createFakeStripeClient(),
                        $billingRepository,
                        $this->createMock(StripeInvoiceStorageService::class),
                        $entityManager,
                        'sk_test_dummy',
                    ),
                );
                self::fail('Project invoice synchronization accepted an invalid CSRF token.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
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
        $activeProjectService->expects(self::never())->method('setActiveProject');

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
        ?StripeProjectCheckoutService $checkoutService = null,
        bool $checkoutEnabled = false,
        string $stripeSecretKey = 'sk_test_dummy',
    ): ProjectBillingController {
        $this->ensureTemporaryCommercialPaymentTable();
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
        $commercialPlanRepository->method('findByPhaseOrdered')->willReturnCallback(
            static function (CommercialPhase $phase) use ($plans): array {
                $prefix = $phase === CommercialPhase::IMPLEMENTATION ? 'implementation_' : '';

                return array_values(array_filter([
                    $plans[$prefix.'basic'] ?? null,
                    $plans[$prefix.'standard'] ?? null,
                    $plans[$prefix.'pro'] ?? null,
                ]));
            }
        );
        $planRepository = $this->createMock(PlanRepository::class);
        $planRepository->method('findOneBy')->willReturn(null);
        $measureRepository = self::getContainer()->get(MeasureRepository::class);
        $comparisonBuilder = new CommercialPlanComparisonBuilder(
            $commercialPlanRepository,
            $measureRepository,
            self::getContainer()->get('translator'),
        );

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
            self::getContainer()->get(\App\Service\SustainabilityPlanCompletionService::class),
            'prod_TESTSTANDARD',
            'prod_TESTPRO',
        );

        return new ProjectBillingController(
            $activeProjectService,
            $billingRepository,
            $invoiceStorage,
            $checkoutService,
            $comparisonBuilder,
            $commercialPlanRepository,
            $planRepository,
            $measureRepository,
            self::getContainer()->get('translator'),
            $entityManager,
            new ProjectCommercialPricing(),
            self::getContainer()->get(ProjectCommercialPaymentRepository::class),
            $stripeSecretKey,
            $checkoutEnabled,
        );
    }

    private function createProjectQuoteService(FakeStripeClient $client): StripeProjectCheckoutService
    {
        $plans = $this->makeDefaultCommercialPlans();
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
            self::getContainer()->get(\App\Service\SustainabilityPlanCompletionService::class),
            'prod_TESTSTANDARD',
            'prod_TESTPRO',
        );
    }

    /** @return array{ProjectRepository, ProjectMembershipRepository} */
    private function overviewRepositories(array $projects): array
    {
        self::getContainer()->get('twig')->addGlobal('userProjects', $projects);
        self::getContainer()->get('twig')->addGlobal('activeProject', $projects[0] ?? null);
        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->method('findBy')->with([], ['name' => 'ASC'])->willReturn($projects);

        return [$projectRepository, $this->createMock(ProjectMembershipRepository::class)];
    }

    private function newPersistableProject(string $name): Project
    {
        $this->ensureTemporaryCommercialPaymentTable();

        return (new Project())
            ->setName($name)
            ->setType('rodaje')
            ->setFilmingType('short')
            ->setCountry('ES')
            ->setCommercialTier(Project::COMMERCIAL_TIER_BASIC);
    }

    private function newCommercialPayment(Project $project): ProjectCommercialPayment
    {
        return new ProjectCommercialPayment(
            $project,
            Project::COMMERCIAL_TIER_BASIC,
            Project::COMMERCIAL_TIER_STANDARD,
            'low',
            24900,
            (new ProjectCommercialPricing())->getPricingFingerprint($project),
        );
    }

    private function ensureTemporaryCommercialPaymentTable(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE IF NOT EXISTS project_commercial_payment (
                id INT AUTO_INCREMENT NOT NULL,
                project_id INT NOT NULL,
                from_tier VARCHAR(20) NOT NULL,
                target_tier VARCHAR(20) NOT NULL,
                price_band VARCHAR(10) NOT NULL,
                amount_cents INT NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                pricing_fingerprint VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                stripe_checkout_session_id VARCHAR(255) DEFAULT NULL,
                stripe_checkout_url VARCHAR(2048) DEFAULT NULL,
                stripe_checkout_expires_at DATETIME DEFAULT NULL,
                stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
                stripe_invoice_id VARCHAR(255) DEFAULT NULL,
                stripe_customer_id VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                paid_at DATETIME DEFAULT NULL,
                review_reason VARCHAR(100) DEFAULT NULL,
                UNIQUE INDEX uniq_project_commercial_payment_session (stripe_checkout_session_id),
                INDEX idx_project_commercial_payment_project_status (project_id, status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
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
        $project = (new Project())
            ->setName('Proyecto '.$id)
            ->setType('rodaje')
            ->setFilmingType('short')
            ->setCountry('ES')
            ->setCommercialTier($tier);
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
