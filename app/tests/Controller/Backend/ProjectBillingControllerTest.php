<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectBillingController;
use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectMembership;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Repository\ProjectBillingDocumentRepository;
use App\Service\ActiveProjectService;
use App\Service\StripeInvoiceStorageService;
use App\Tests\Support\CommercialPlanTestHelpers;
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
        $subscription = $project->getSubscription();
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(9900)
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
            ->setAmountCents(9900)
            ->setCurrency('EUR')
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

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId()], ['from' => 'index']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findByProjectOrdered')
            ->with($project)
            ->willReturn([$document]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('hasLocalCopy')
            ->with($document)
            ->willReturn(true);

        $controller = new ProjectBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Facturación del proyecto', $content);
        self::assertStringContainsString('Documentos de facturación', $content);
        self::assertStringContainsString('Ver factura', $content);
        self::assertStringContainsString('Descargar PDF', $content);
        self::assertStringContainsString('Actualizar referencias desde Stripe', $content);
        self::assertStringContainsString('billing/document/12/view?from=index', $content);
        self::assertStringContainsString('billing/document/12/download?from=index', $content);
        self::assertStringContainsString('billingDocumentModal12', $content);
        self::assertStringContainsString('Abrir en nueva pestaña', $content);
        self::assertStringContainsString('INV-TEST-86', $content);
        self::assertStringContainsString('cs_test_86', $content);
        self::assertStringContainsString('pi_test_86', $content);
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
        $entityManager->persist($project);
        $entityManager->flush();

        $membership = (new ProjectMembership())
            ->setUser($user)
            ->setProject($project)
            ->setProjectRole('member');
        $entityManager->persist($membership);
        $entityManager->flush();

        $subscription = (new ProjectSubscription())
            ->setTier(ProjectSubscription::TIER_STANDARD)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(9900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setStripeCheckoutSessionId('cs_test_89')
            ->setStripePaymentIntentId('pi_test_89')
            ->setStripeInvoiceId('in_test_89')
            ->setStripeCustomerId('cus_test_89')
            ->setPaymentReference('INV-TEST-89')
            ->setStripeHostedInvoiceUrl('https://invoice.test/view')
            ->setStripeInvoicePdfUrl('https://invoice.test/pdf');
        $project->setSubscription($subscription);

        $this->setUserToken($user);

        $document = $this->createDocument($project, $subscription, 13);
        $document
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(9900)
            ->setCurrency('EUR')
            ->setPaymentReference('INV-TEST-89')
            ->setStripeCheckoutSessionId('cs_test_89')
            ->setStripePaymentIntentId('pi_test_89')
            ->setStripeInvoiceId('in_test_89')
            ->setStripeCustomerId('cus_test_89')
            ->setHostedInvoiceUrl('https://invoice.test/view')
            ->setInvoicePdfUrl('https://invoice.test/pdf')
            ->setLocalPath('stripe-invoices/project-89/invoice-in_test_89.pdf')
            ->setDownloadedAt(new \DateTimeImmutable('2026-06-04 11:00:00'))
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'));

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId()], ['from' => 'index']);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findByProjectOrdered')
            ->with($project)
            ->willReturn([$document]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::once())
            ->method('hasLocalCopy')
            ->with($document)
            ->willReturn(true);

        $controller = new ProjectBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('Ver factura', $content);
        self::assertStringContainsString('Descargar PDF', $content);
        self::assertStringNotContainsString('Checkout Session ID', $content);
        self::assertStringNotContainsString('Payment Intent ID', $content);
        self::assertStringNotContainsString('Invoice ID', $content);
        self::assertStringNotContainsString('Customer ID', $content);
        self::assertStringNotContainsString('Referencia de pago', $content);
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
        $subscription = $project->getSubscription();
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(9900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid');

        $request = $this->pushRequest('backend_project_billing', ['id' => $project->getId()]);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', [$project]);
        $twig->addGlobal('activeProject', $project);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with($project);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::once())
            ->method('findByProjectOrdered')
            ->with($project)
            ->willReturn([]);

        $invoiceStorage = $this->createMock(StripeInvoiceStorageService::class);
        $invoiceStorage->expects(self::never())->method('hasLocalCopy');

        $controller = new ProjectBillingController($activeProjectService, $billingRepository, $invoiceStorage, $this->createMock(EntityManagerInterface::class));
        $controller->setContainer($container);

        $response = $controller($project, $request);
        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString('No hay documentos de facturación todavía', $content);
        self::assertStringNotContainsString('Ver factura', $content);
        self::assertStringNotContainsString('Descargar PDF', $content);
    }

    public function testDocumentRoutesServeLocalPdfAndSyncDocument(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->setAdminToken();

        $project = $this->createProject(88, ProjectSubscription::STATUS_ACTIVE, ProjectSubscription::TIER_STANDARD);
        $subscription = $project->getSubscription();
        $subscription
            ->setSource(ProjectSubscription::SOURCE_STRIPE)
            ->setPaidAmountCents(9900)
            ->setCurrency('EUR')
            ->setPaidAt(new \DateTimeImmutable('2026-06-04 10:00:00'))
            ->setLastPaymentStatus('paid')
            ->setStripeInvoicePdfUrl('https://invoice.test/pdf')
            ->setStripeHostedInvoiceUrl('https://invoice.test/view')
            ->setStripeInvoiceId('in_test_88');

        $document = $this->createDocument($project, $subscription, 12);
        $document
            ->setStatus(ProjectBillingDocument::STATUS_PAID)
            ->setAmountCents(9900)
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

        $viewRequest = $this->pushRequest('backend_project_billing_document_view', ['id' => $project->getId(), 'documentId' => 12], ['from' => 'index']);
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
            ->method('syncInvoicePdf')
            ->with($document, true)
            ->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $controller = new ProjectBillingController($activeProjectService, $billingRepository, $invoiceStorage, $entityManager);
        $controller->setContainer($container);

        $viewResponse = $controller->viewInvoice($project, 12, $viewRequest);
        self::assertInstanceOf(BinaryFileResponse::class, $viewResponse);
        self::assertStringContainsString('inline', (string) $viewResponse->headers->get('content-disposition'));

        $downloadResponse = $controller->downloadInvoice($project, 12, $viewRequest);
        self::assertInstanceOf(BinaryFileResponse::class, $downloadResponse);
        self::assertStringContainsString('attachment', (string) $downloadResponse->headers->get('content-disposition'));

        $syncRequest = $this->pushRequest('backend_project_billing_document_sync', ['id' => $project->getId(), 'documentId' => 12], ['from' => 'index']);
        $syncRequest->request->set('_token', $container->get('security.csrf.token_manager')->getToken('project_billing_document_sync_88_12')->getValue());
        $syncResponse = $controller->syncInvoice($project, 12, $syncRequest);

        self::assertInstanceOf(RedirectResponse::class, $syncResponse);
        self::assertStringContainsString('/backend/project/88/billing', $syncResponse->getTargetUrl());
        self::assertStringContainsString('from=index', $syncResponse->getTargetUrl());
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
            ->setTier($tier)
            ->setStatus($status)
            ->setSource(ProjectSubscription::SOURCE_STRIPE);

        $project->setSubscription($subscription);

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

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }
}
