<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\TransportEmissionController;
use App\Entity\Category;
use App\Entity\EmissionFactor;
use App\Entity\EmissionRecord;
use App\Entity\EmissionRecordAttachment;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\CategoryRepository;
use App\Repository\EmissionFactorRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\EmissionFactorResolver;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\Transport\TransportEmissionCalculator;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionPresentationMapper;
use App\Service\Emission\Transport\TransportEmissionRecordService;
use App\Service\Emission\Transport\TransportEmissionRequestMapper;
use App\Service\Emission\Transport\TransportEmissionResult;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use App\Service\Emission\Transport\TransportUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class TransportEmissionControllerTest extends KernelTestCase
{
    private string $attachmentDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attachmentDirectory = sys_get_temp_dir().'/bgfm-transport-attachments-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->attachmentDirectory)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->attachmentDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->attachmentDirectory);
        }
        parent::tearDown();
    }

    public function testGetCreateRendersHtmlFormWithCsrfAndNoAuthoritativeFields(): void
    {
        $context = $this->context();
        $request = $this->request('GET');

        $response = $this->create($request, $context, persistCalls: 0);
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<form method="post"', $content);
        self::assertStringContainsString('enctype="multipart/form-data"', $content);
        self::assertStringContainsString('name="attachments[]"', $content);
        self::assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $content);
        self::assertStringContainsString('data-controller="transport-v20-form"', $content);
        self::assertStringContainsString('data-transport-v20-form-config-value=', $content);
        foreach (['factor', 'factorValue', 'factorYear', 'source', 'functionalKey', 'amount', 'emission', 'generatedKgCo2e'] as $field) {
            self::assertStringNotContainsString(sprintf('name="%s"', $field), $content);
        }
    }

    public function testGetEditPreloadsSnapshotInputAndNotes(): void
    {
        $context = $this->context();
        $record = $this->record($context, notes: 'Nota conservada');
        $request = $this->request('GET');

        $response = $this->edit($record, $request, $context, persistCalls: 0);
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="17"', $content);
        self::assertStringContainsString('value="2026-06-01"', $content);
        self::assertStringContainsString('name="repetitions" value="2"', $content);
        self::assertStringContainsString('Nota conservada', $content);
        self::assertStringContainsString('value="Madrid"', $content);
        self::assertStringContainsString('value="Toledo"', $content);
        self::assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $content);
    }

    public function testGetEditListsExistingAttachment(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $attachment = (new EmissionRecordAttachment())->setEmissionRecord($record)->setOriginalName('factura.pdf')
            ->setStoredName(str_repeat('a', 32).'.pdf')->setMimeType('application/pdf')->setSize(2048)->setCreatedAt(new \DateTimeImmutable());
        $this->setEntityId($attachment, 401);
        $record->addAttachment($attachment);

        $content = (string) $this->edit($record, $this->request('GET'), $context, persistCalls: 0)->getContent();

        self::assertStringContainsString('factura.pdf', $content);
        self::assertStringContainsString('/backend/emission/300/attachments/401/download', $content);
        self::assertStringContainsString('/backend/emission/300/attachments/401/delete', $content);
    }

    public function testGetEditRejectsNonV20Record(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $record->setCalculationDetails('{}');

        $this->expectException(NotFoundHttpException::class);
        $this->edit($record, $this->request('GET'), $context, persistCalls: 0);
    }

    public function testInvalidCreateCsrfReturnsErrorAndDoesNotPersist(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->validPost() + ['_token' => 'invalid']);

        $response = $this->create($request, $context, persistCalls: 0, factor: $this->factor());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('sesión del formulario', (string) $response->getContent());
    }

    public function testInvalidAttachmentReturns422BeforeCreatingRecord(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->validPost(), [], ['attachments' => [$this->upload('bad.txt', 'plain text')]]);
        $request->request->set('_token', $this->csrfToken('transport_emission_v20_create'));

        $response = $this->create($request, $context, persistCalls: 0, factor: $this->factor());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Formato de justificante no permitido', (string) $response->getContent());
    }

    public function testValidCreateStoresAttachmentEntityAndFile(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->validPost(), [], ['attachments' => [$this->upload('factura.pdf', "%PDF-1.4\n%%EOF\n")]]);
        $request->request->set('_token', $this->csrfToken('transport_emission_v20_create'));

        $response = $this->create($request, $context, persistCalls: 1, factor: $this->factor(), attachmentPersistCalls: 1);

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, glob($this->attachmentDirectory.'/10/301/*.pdf'));
    }

    public function testInvalidEditCsrfReturnsErrorAndDoesNotPersist(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $request = $this->request('POST', $this->validPost() + ['_token' => 'invalid']);

        $response = $this->edit($record, $request, $context, persistCalls: 0, factor: $this->factor());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('sesión del formulario', (string) $response->getContent());
    }

    public function testValidCalculatedPostRedirectsToTransportIndexAndAddsSuccessFlash(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->validPost(), ['page' => '2']);
        $request->request->set('_token', $this->csrfToken('transport_emission_v20_create'));

        $response = $this->create($request, $context, persistCalls: 1, factor: $this->factor());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/backend/emission/records?page=2&categoryId=20', $response->headers->get('Location'));
        self::assertSame(['backend.emission.transport_v20.flash.created'], $request->getSession()->getFlashBag()->peek('success'));
    }

    public function testEditAddsAttachmentWithoutRemovingExistingOne(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $existing = (new EmissionRecordAttachment())->setEmissionRecord($record)->setOriginalName('existing.pdf')
            ->setStoredName(str_repeat('b', 32).'.pdf')->setMimeType('application/pdf')->setSize(20)->setCreatedAt(new \DateTimeImmutable());
        $record->addAttachment($existing);
        $request = $this->request('POST', $this->validPost(), [], ['attachments' => [$this->upload('new.pdf', "%PDF-1.4\n%%EOF\n")]]);
        $request->request->set('_token', $this->csrfToken('transport_emission_v20_edit_300'));

        $response = $this->edit($record, $request, $context, persistCalls: 1, factor: $this->factor(), attachmentPersistCalls: 1);

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(2, $record->getAttachments());
        self::assertSame('existing.pdf', $record->getAttachments()->first()->getOriginalName());
    }

    public function testNonPersistiblePostReturns422AndKeepsSubmittedValues(): void
    {
        $context = $this->context();
        $post = $this->validPost();
        $post['method'] = 'distance_consumption';
        $post['activityValue'] = '44.5';
        $post['notes'] = 'Valor del usuario';
        $post['secondaryActivityValue'] = '7.2';
        $post['secondaryActivityUnit'] = 'L/100_km';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('transport_emission_v20_create'));

        $response = $this->create($request, $context, persistCalls: 0);
        $content = (string) $response->getContent();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('value="44.5"', $content);
        self::assertStringContainsString('Valor del usuario', $content);
        self::assertStringContainsString('value="7.2"', $content);
        self::assertStringContainsString('todavía no está soportada', $content);
    }

    public function testDateOutsideProjectPhasesReturns422WithoutPersisting(): void
    {
        $context = $this->context(phaseAvailable: false);
        $request = $this->request('POST', $this->validPost());
        $request->request->set('_token', $this->csrfToken('transport_emission_v20_create'));

        $response = $this->create($request, $context, persistCalls: 0, factor: $this->factor());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('no corresponde a ninguna fase', (string) $response->getContent());
    }

    /** @return array{project: Project, category: Category, phase: ProjectPhaseDate, active: ActiveProjectService&MockObject, categories: CategoryRepository&MockObject, projects: ProjectRepository&MockObject} */
    private function context(bool $phaseAvailable = true): array
    {
        $project = (new Project())->setName('Proyecto Transporte')->setType('rodaje')->setCountry('ES');
        $this->setEntityId($project, 10);
        $category = (new Category())->setName('Transporte');
        $this->setEntityId($category, 20);
        $phase = (new ProjectPhaseDate())->setProject($project)->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))->setEndDate(new \DateTimeImmutable('2026-12-31'));
        $project->addPhaseDate($phase);

        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($project);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('findOneBy')->willReturn($category);
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findPhaseByDate')->willReturn($phaseAvailable ? $phase : null);

        return compact('project', 'category', 'phase', 'active', 'categories', 'projects');
    }

    /** @param array<string, mixed> $context */
    private function create(Request $request, array $context, int $persistCalls, ?EmissionFactor $factor = null, int $attachmentPersistCalls = 0): \Symfony\Component\HttpFoundation\Response
    {
        return $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new TransportEmissionRequestMapper(),
            $this->recordService($persistCalls, $factor),
            new TransportEmissionPresentationMapper(),
            new TransportUiCatalog(),
            new EmissionRecordAttachmentStorage($this->attachmentDirectory),
            $this->attachmentEntityManager($attachmentPersistCalls),
        );
    }

    /** @param array<string, mixed> $context */
    private function edit(EmissionRecord $record, Request $request, array $context, int $persistCalls, ?EmissionFactor $factor = null, int $attachmentPersistCalls = 0): \Symfony\Component\HttpFoundation\Response
    {
        return $this->controller()->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new TransportEmissionRequestMapper(),
            $this->recordService($persistCalls, $factor),
            new TransportEmissionSnapshot(),
            new TransportEmissionPresentationMapper(),
            new TransportUiCatalog(),
            new EmissionRecordAttachmentStorage($this->attachmentDirectory),
            $this->attachmentEntityManager($attachmentPersistCalls),
        );
    }

    private function controller(): TransportEmissionController
    {
        $controller = new TransportEmissionController();
        $controller->setContainer(self::getContainer());
        $this->setAdminToken();

        return $controller;
    }

    private function request(string $method, array $post = [], array $query = [], array $files = []): Request
    {
        $request = new Request($query, $post, [], [], $files, ['REQUEST_METHOD' => $method]);
        $request->setLocale('es');
        $request->attributes->set('_route', 'backend_emission_new_transport_v20');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function recordService(int $persistCalls, ?EmissionFactor $factor): TransportEmissionRecordService
    {
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturn($factor);
        $keyGenerator = new EmissionFactorKeyGenerator();
        $calculator = new TransportEmissionCalculator(
            new TransportFactorCriteriaMapper(),
            new EmissionFactorResolver($repository, $keyGenerator),
            $keyGenerator,
        );
        /** @var EntityManagerInterface&MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($persistCalls))->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof EmissionRecord && null === $entity->getId()) {
                $this->setEntityId($entity, 301);
            }
        });
        $entityManager->expects(self::exactly($persistCalls))->method('flush');

        return new TransportEmissionRecordService($calculator, new TransportEmissionSnapshot(), $entityManager);
    }

    private function attachmentEntityManager(int $persistCalls): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($persistCalls))->method('persist');
        $entityManager->expects(self::exactly($persistCalls > 0 ? 1 : 0))->method('flush');

        return $entityManager;
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'transport-upload-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param array<string, mixed> $context */
    private function record(array $context, ?string $notes = null): EmissionRecord
    {
        $input = new TransportEmissionInput(
            'local', 'taxi', 'route', 'ES', new \DateTimeImmutable('2026-06-01'), '17', 'km', '2',
        );
        $result = new TransportEmissionResult(
            TransportEmissionResult::STATUS_CALCULATED, '34', 'km', '4', [], 'server-key', 2026, 2026, '0.1', 'km', 'MITECO',
        );
        $record = (new EmissionRecord())->setProject($context['project'])->setPhase($context['phase'])
            ->setCategory($context['category'])->setAmount(34)->setEmission(4)->setRegisteredAt(new \DateTimeImmutable('2026-06-01'))
            ->setNotes($notes)->setCalculationDetails((new TransportEmissionSnapshot())->encode($input, $result, [
                'origin' => 'Madrid',
                'destination' => 'Toledo',
                'tripType' => 'one_way',
            ]));
        $this->setEntityId($record, 300);

        return $record;
    }

    /** @return array<string, string> */
    private function validPost(): array
    {
        return [
            'category' => 'local', 'mode' => 'car', 'method' => 'distance', 'country' => 'ES',
            'startedAt' => '2026-06-01', 'activityValue' => '10', 'activityUnit' => 'km',
            'repetitions' => '1', 'vehicleType' => 'petrol', 'notes' => 'Nota nueva',
        ];
    }

    private function factor(): EmissionFactor
    {
        return (new EmissionFactor())->setCategoryKey('transport')->setFunctionalKey('server-key')->setCriteria([])
            ->setYear(2026)->setValue('0.5')->setUnit('km')->setSource('MITECO')->setSourceDetail('Backend');
    }

    private function setAdminToken(): void
    {
        $user = (new \App\Entity\User())->setName('Admin')->setSurnames('User')->setEmail('admin@example.test')
            ->setPassword('password')->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = (new \ReflectionClass($entity))->getProperty('id');
        $property->setValue($entity, $id);
    }

}
