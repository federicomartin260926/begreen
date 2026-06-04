<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectBillingDocument;
use App\Entity\ProjectSubscription;
use App\Repository\ProjectBillingDocumentRepository;
use App\Service\StripeInvoiceStorageService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\Stripe\FakeStripeClient;
use App\Tests\Support\Stripe\FakeStripeCheckoutFacade;
use App\Tests\Support\Stripe\FakeStripeCheckoutSessions;
use App\Tests\Support\Stripe\FakeStripeInvoicesFacade;

final class StripeInvoiceStorageServiceTest extends TestCase
{
    public function testUpsertFromStripeCheckoutCreatesDocumentAndStoresPdfLocally(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $documentRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $persistedDocument = null;
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $document) use (&$persistedDocument): bool {
                if (!$document instanceof ProjectBillingDocument) {
                    return false;
                }

                $persistedDocument = $document;

                return true;
            }));

        $entityManager->expects(self::never())->method('flush');
        $documentRepository->expects(self::once())
            ->method('findOneMatchingStripeIdentifiers')
            ->willReturn(null);

        $service = new StripeInvoiceStorageService(
            $this->createStripeClient(),
            new MockHttpClient(new MockResponse("%PDF-1.4\n%PDF test\n", [
                'http_code' => 200,
                'response_headers' => ['content-type: application/pdf'],
            ])),
            $documentRepository,
            $entityManager,
            $projectDir,
        );

        $subscription = $this->createSubscription();
        $session = (object) [
            'id' => 'cs_test_86',
            'payment_status' => 'paid',
            'amount_total' => 9900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_test_86'],
            'customer' => (object) ['id' => 'cus_test_86'],
            'invoice' => (object) [
                'id' => 'in_test_86',
                'number' => 'INV-TEST-86',
                'hosted_invoice_url' => 'https://stripe.test/invoice/view',
                'invoice_pdf' => 'https://stripe.test/invoice/pdf',
                'created' => 1717495200,
            ],
        ];

        $document = $service->upsertFromStripeCheckout($subscription, $session, $session->invoice);

        self::assertSame($persistedDocument, $document);
        self::assertSame('stripe', $document->getProvider());
        self::assertSame('invoice', $document->getType());
        self::assertSame('paid', $document->getStatus());
        self::assertSame('cs_test_86', $document->getStripeCheckoutSessionId());
        self::assertSame('pi_test_86', $document->getStripePaymentIntentId());
        self::assertSame('in_test_86', $document->getStripeInvoiceId());
        self::assertSame('cus_test_86', $document->getStripeCustomerId());
        self::assertSame('INV-TEST-86', $document->getPaymentReference());
        self::assertSame(9900, $document->getAmountCents());
        self::assertSame('EUR', $document->getCurrency());
        self::assertSame('https://stripe.test/invoice/view', $document->getHostedInvoiceUrl());
        self::assertSame('https://stripe.test/invoice/pdf', $document->getInvoicePdfUrl());
        self::assertSame('stripe-invoices/project-86/invoice-in_test_86.pdf', $document->getLocalPath());
        self::assertNotNull($document->getDownloadedAt());
        self::assertFileExists($projectDir.'/var/private/'.$document->getLocalPath());
        self::assertSame("%PDF-1.4\n%PDF test\n", file_get_contents($projectDir.'/var/private/'.$document->getLocalPath()));
    }

    public function testUpsertFromStripeCheckoutReusesExistingDocumentWithoutDuplicating(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $documentRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $existingDocument = null;
        $callCount = 0;
        $documentRepository->expects(self::exactly(2))
            ->method('findOneMatchingStripeIdentifiers')
            ->willReturnCallback(function () use (&$callCount, &$existingDocument) {
                $callCount++;

                return $callCount === 1 ? null : $existingDocument;
            });

        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (object $document) use (&$existingDocument): bool {
                if (!$document instanceof ProjectBillingDocument) {
                    return false;
                }

                $existingDocument = $document;
                $this->setEntityId($existingDocument, 12);
                $existingDocument->setStripeCheckoutSessionId('cs_test_86');
                $existingDocument->setStripeInvoiceId('in_test_86');
                $existingDocument->setStripePaymentIntentId('pi_test_86');
                $existingDocument->setInvoicePdfUrl('https://stripe.test/invoice/pdf');
                $existingDocument->setLocalPath('stripe-invoices/project-86/invoice-in_test_86.pdf');
                $existingDocument->setDownloadedAt(new \DateTimeImmutable('2026-06-04 10:00:00'));

                return true;
            }));
        $entityManager->expects(self::never())->method('flush');

        $filesystem = new Filesystem();
        $filesystem->mkdir($projectDir.'/var/private/stripe-invoices/project-86');
        file_put_contents($projectDir.'/var/private/stripe-invoices/project-86/invoice-in_test_86.pdf', "%PDF-1.4\n%existing\n");

        $service = new StripeInvoiceStorageService(
            $this->createStripeClient(),
            new MockHttpClient(static function (): never {
                throw new \RuntimeException('HTTP client should not be called when the local copy already exists.');
            }),
            $documentRepository,
            $entityManager,
            $projectDir,
        );

        $subscription = $this->createSubscription();
        $session = (object) [
            'id' => 'cs_test_86',
            'payment_status' => 'paid',
            'amount_total' => 9900,
            'currency' => 'eur',
            'payment_intent' => (object) ['id' => 'pi_test_86'],
            'customer' => (object) ['id' => 'cus_test_86'],
            'invoice' => (object) [
                'id' => 'in_test_86',
                'number' => 'INV-TEST-86',
                'hosted_invoice_url' => 'https://stripe.test/invoice/view',
                'invoice_pdf' => 'https://stripe.test/invoice/pdf',
            ],
        ];

        $firstDocument = $service->upsertFromStripeCheckout($subscription, $session, $session->invoice);
        $secondDocument = $service->upsertFromStripeCheckout($subscription, $session, $session->invoice);

        self::assertSame($firstDocument, $secondDocument);
        self::assertSame($existingDocument, $secondDocument);
        self::assertSame('stripe-invoices/project-86/invoice-in_test_86.pdf', $secondDocument->getLocalPath());
        self::assertSame('2026-06-04 10:00:00', $secondDocument->getDownloadedAt()?->format('Y-m-d H:i:s'));
    }

    public function testSyncRejectsNonPdfResponse(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $service = new StripeInvoiceStorageService(
            $this->createStripeClient(),
            new MockHttpClient(new MockResponse('<html>not pdf</html>', [
                'http_code' => 200,
                'response_headers' => ['content-type: text/html'],
            ])),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $projectDir,
        );

        $document = $this->createDocument();
        $document->setInvoicePdfUrl('https://stripe.test/invoice.pdf');

        self::assertFalse($service->syncInvoicePdf($document));
        self::assertNull($document->getLocalPath());
    }

    public function testSyncFollowsRedirectsWhenDownloadingPdf(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $documentRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $service = new StripeInvoiceStorageService(
            $this->createStripeClient(),
            new MockHttpClient(function (string $method, string $url): MockResponse {
                if ($url === 'https://stripe.test/invoice.pdf') {
                    return new MockResponse('', [
                        'http_code' => 302,
                        'response_headers' => ['location: https://stripe.test/invoice-final.pdf'],
                    ]);
                }

                return new MockResponse("%PDF-1.4\n%redirected\n", [
                    'http_code' => 200,
                    'response_headers' => ['content-type: application/pdf; charset=binary'],
                ]);
            }),
            $documentRepository,
            $entityManager,
            $projectDir,
        );

        $document = $this->createDocument();
        $document
            ->setInvoicePdfUrl('https://stripe.test/invoice.pdf')
            ->setStripeInvoiceId('in_test_86')
            ->setLocalPath(null);

        self::assertTrue($service->syncInvoicePdf($document));
        self::assertSame('stripe-invoices/project-86/invoice-in_test_86.pdf', $document->getLocalPath());
        self::assertFileExists($projectDir.'/var/private/'.$document->getLocalPath());
    }

    public function testSyncAcceptsOctetStreamWhenContentIsPdf(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $service = new StripeInvoiceStorageService(
            $this->createStripeClient(),
            new MockHttpClient(new MockResponse("%PDF-1.4\n%octet-stream\n", [
                'http_code' => 200,
                'response_headers' => ['content-type: application/octet-stream'],
            ])),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $projectDir,
        );

        $document = $this->createDocument();
        $document->setInvoicePdfUrl('https://stripe.test/invoice.pdf');

        self::assertTrue($service->syncInvoicePdf($document));
        self::assertNotNull($document->getLocalPath());
        self::assertFileExists($projectDir.'/var/private/'.$document->getLocalPath());
    }

    public function testSyncFailsWhenInvoiceFileCannotBeWritten(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $blocker = $projectDir.'/var/private/stripe-invoices/project-86';
        $filesystem = new Filesystem();
        $filesystem->mkdir($projectDir.'/var/private/stripe-invoices');
        file_put_contents($blocker, 'blocked');

        $service = new StripeInvoiceStorageService(
            $this->createStripeClient(),
            new MockHttpClient(new MockResponse("%PDF-1.4\n%write-failure\n", [
                'http_code' => 200,
                'response_headers' => ['content-type: application/pdf'],
            ])),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $projectDir,
        );

        $document = $this->createDocument();
        $document
            ->setStripeInvoiceId('in_test_86')
            ->setInvoicePdfUrl('https://stripe.test/invoice.pdf')
            ->setLocalPath(null);

        self::assertFalse($service->syncInvoicePdf($document));
        self::assertNull($document->getLocalPath());
    }

    public function testSyncDownloadsPdfWhenInvoicePdfUrlIsMissingButStripeInvoiceIdExists(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $documentRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $stripeClient = $this->createStripeClient();
        $stripeClient->invoices->retrieveReturn = (object) [
            'id' => 'in_test_86',
            'number' => 'INV-TEST-86',
            'hosted_invoice_url' => 'https://stripe.test/invoice/view',
            'invoice_pdf' => 'https://stripe.test/invoice/pdf',
            'created' => 1717495200,
        ];

        $service = new StripeInvoiceStorageService(
            $stripeClient,
            new MockHttpClient(new MockResponse("%PDF-1.4\n%PDF test\n", [
                'http_code' => 200,
                'response_headers' => ['content-type: application/pdf'],
            ])),
            $documentRepository,
            $entityManager,
            $projectDir,
        );

        $document = $this->createDocument();
        $document
            ->setStripeInvoiceId('in_test_86')
            ->setInvoicePdfUrl(null)
            ->setHostedInvoiceUrl(null)
            ->setLocalPath(null);

        self::assertTrue($service->syncInvoicePdf($document));
        self::assertSame('https://stripe.test/invoice/pdf', $document->getInvoicePdfUrl());
        self::assertSame('https://stripe.test/invoice/view', $document->getHostedInvoiceUrl());
        self::assertSame('stripe-invoices/project-86/invoice-in_test_86.pdf', $document->getLocalPath());
        self::assertFileExists($projectDir.'/var/private/stripe-invoices/project-86/invoice-in_test_86.pdf');
    }

    public function testSyncDownloadsPdfWhenCheckoutSessionNeedsInvoiceRefresh(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $documentRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $stripeClient = $this->createStripeClient();
        $stripeClient->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_test_86',
            'invoice' => (object) [
                'id' => 'in_test_86',
                'number' => 'INV-TEST-86',
                'hosted_invoice_url' => 'https://stripe.test/invoice/view',
                'invoice_pdf' => 'https://stripe.test/invoice/pdf',
            ],
        ];

        $service = new StripeInvoiceStorageService(
            $stripeClient,
            new MockHttpClient(new MockResponse("%PDF-1.4\n%PDF test\n", [
                'http_code' => 200,
                'response_headers' => ['content-type: application/pdf'],
            ])),
            $documentRepository,
            $entityManager,
            $projectDir,
        );

        $document = $this->createDocument();
        $document
            ->setStripeCheckoutSessionId('cs_test_86')
            ->setStripeInvoiceId(null)
            ->setInvoicePdfUrl(null)
            ->setHostedInvoiceUrl(null)
            ->setLocalPath(null);

        self::assertTrue($service->syncInvoicePdf($document));
        self::assertSame('https://stripe.test/invoice/pdf', $document->getInvoicePdfUrl());
        self::assertSame('https://stripe.test/invoice/view', $document->getHostedInvoiceUrl());
        self::assertSame('stripe-invoices/project-86/invoice-in_test_86.pdf', $document->getLocalPath());
        self::assertSame(['cs_test_86'], $stripeClient->checkout->sessions->retrieveCalls ? array_column($stripeClient->checkout->sessions->retrieveCalls, 'sessionId') : []);
        self::assertSame([], $stripeClient->invoices->retrieveCalls);
    }

    public function testSyncDownloadsPdfWhenPaymentReferenceLooksLikeCheckoutSessionId(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $documentRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $stripeClient = $this->createStripeClient();
        $stripeClient->checkout->sessions->retrieveReturn = (object) [
            'id' => 'cs_test_86',
            'invoice' => (object) [
                'id' => 'in_test_86',
                'number' => 'INV-TEST-86',
                'hosted_invoice_url' => 'https://stripe.test/invoice/view',
                'invoice_pdf' => 'https://stripe.test/invoice/pdf',
            ],
        ];

        $service = new StripeInvoiceStorageService(
            $stripeClient,
            new MockHttpClient(new MockResponse("%PDF-1.4\n%PDF test\n", [
                'http_code' => 200,
                'response_headers' => ['content-type: application/pdf'],
            ])),
            $documentRepository,
            $entityManager,
            $projectDir,
        );

        $document = $this->createDocument();
        $document
            ->setPaymentReference('cs_test_86')
            ->setStripeCheckoutSessionId(null)
            ->setStripeInvoiceId(null)
            ->setInvoicePdfUrl(null)
            ->setHostedInvoiceUrl(null)
            ->setLocalPath(null);

        self::assertTrue($service->syncInvoicePdf($document));
        self::assertSame('https://stripe.test/invoice/pdf', $document->getInvoicePdfUrl());
        self::assertSame('https://stripe.test/invoice/view', $document->getHostedInvoiceUrl());
        self::assertSame('stripe-invoices/project-86/invoice-in_test_86.pdf', $document->getLocalPath());
        self::assertSame(['cs_test_86'], array_column($stripeClient->checkout->sessions->retrieveCalls, 'sessionId'));
    }

    public function testSyncWithoutStripeReferencesDoesNotCrash(): void
    {
        $projectDir = sys_get_temp_dir().'/bgmf_invoice_storage_'.uniqid();
        $service = new StripeInvoiceStorageService(
            $this->createStripeClient(),
            new MockHttpClient(new MockResponse('', [
                'http_code' => 500,
            ])),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $projectDir,
        );

        $document = $this->createDocument();
        $document->setInvoicePdfUrl(null);
        $document->setStripeInvoiceId(null);
        $document->setStripeCheckoutSessionId(null);
        $document->setPaymentReference(null);

        self::assertFalse($service->syncInvoicePdf($document));
        self::assertNull($document->getLocalPath());
    }

    private function createSubscription(): ProjectSubscription
    {
        $project = new Project();
        $this->setEntityId($project, 86);

        $subscription = (new ProjectSubscription())
            ->setProject($project)
            ->setStripeInvoicePdfUrl('https://stripe.test/invoice.pdf')
            ->setStripeInvoiceId('in_test_86');

        $project->setSubscription($subscription);

        return $subscription;
    }

    private function createDocument(): ProjectBillingDocument
    {
        $project = new Project();
        $this->setEntityId($project, 86);

        $document = (new ProjectBillingDocument())
            ->setProject($project)
            ->setProvider(ProjectBillingDocument::PROVIDER_STRIPE)
            ->setType(ProjectBillingDocument::TYPE_INVOICE);
        $this->setEntityId($document, 12);

        return $document;
    }

    private function createStripeClient(): FakeStripeClient
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
