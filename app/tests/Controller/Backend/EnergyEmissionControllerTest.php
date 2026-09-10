<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\EnergyEmissionController;
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
use App\Service\Emission\Energy\ElectricityFactorResolver;
use App\Service\Emission\Energy\EnergyEmissionCalculator;
use App\Service\Emission\Energy\EnergyEmissionRecordService;
use App\Service\Emission\Energy\EnergyEmissionRequestMapper;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Energy\EnergyUiCatalog;
use App\Service\Emission\Energy\StationaryCombustionFactorResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class EnergyEmissionControllerTest extends KernelTestCase
{
    private string $attachmentDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attachmentDirectory = sys_get_temp_dir().'/bgfm-energy-attachments-'.bin2hex(random_bytes(8));
    }

    public function testPreviewCalculatesElectricityWithoutPersistence(): void
    {
        $context = $this->context();
        $request = $this->request('POST', $this->validPost());
        $request->request->set('_preview_token', $this->csrfToken('energy_emission_v1_preview'));

        $response = $this->controller()->preview($request, $context['active'], new EnergyEmissionRequestMapper(), $this->calculator());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $data['status']);
        self::assertSame('2.58', $data['emissionKgCo2e']);
        self::assertSame(2025, $data['activityYear']);
        self::assertSame('ENE-TEST-001', $data['factorTraces'][0]['factorId']);
        self::assertSame(2025, $data['factorTraces'][0]['activityYear']);
        self::assertSame(2025, $data['factorTraces'][0]['factorActivityYear']);
        self::assertSame(2025, $data['factorTraces'][0]['factorYear']);
        self::assertNull($data['factorTraces'][0]['factorVersion']);
        self::assertSame('0.258', $data['factorTraces'][0]['factorValue']);
        self::assertSame('kgCO2e/kWh', $data['factorTraces'][0]['factorUnit']);
        self::assertSame('MITECO', $data['factorTraces'][0]['source']);
    }

    public function testPreviewReturnsCompositeMixedCalculation(): void
    {
        $context = $this->context();
        $post = $this->validPost() + ['gridKwh' => '6', 'solarKwh' => '4'];
        $post['origin'] = 'mixed';
        $request = $this->request('POST', $post);
        $request->request->set('_preview_token', $this->csrfToken('energy_emission_v1_preview'));

        $response = $this->controller()->preview($request, $context['active'], new EnergyEmissionRequestMapper(), $this->calculator());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('COMPOSITE', $data['temporalType']);
        self::assertCount(2, $data['factorTraces']);
        self::assertSame('1.548', $data['emissionKgCo2e']);
        self::assertSame('ENE-TEST-001', $data['factorTraces'][0]['factorId']);
        self::assertNull($data['factorTraces'][1]['factorId']);
        self::assertNull($data['factorTraces'][1]['factorActivityYear']);
        self::assertNull($data['factorTraces'][1]['factorYear']);
        self::assertNull($data['factorTraces'][1]['factorVersion']);
    }

    public function testPreviewRejectsRetiredDigitalFamily(): void
    {
        $context = $this->context();
        $post = $this->validPost();
        $post['family'] = 'digital';
        $post['digitalType'] = 'ai';
        $post['knownKwh'] = '';
        $post['hours'] = '3';
        $post['gpu'] = 'A100';
        $request = $this->request('POST', $post);
        $request->request->set('_preview_token', $this->csrfToken('energy_emission_v1_preview'));

        $response = $this->controller()->preview($request, $context['active'], new EnergyEmissionRequestMapper(), $this->calculator());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(['error' => 'invalid_input'], $data);
    }

    public function testGetCreateRendersModernFormWithoutAuthoritativeFields(): void
    {
        $context = $this->context();
        $response = $this->create($this->request('GET'), $context, 0);
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-controller="energy-v1-form"', $content);
        self::assertStringContainsString('name="attachments[]"', $content);
        foreach (['digital', 'digitalType', 'digitalLocation', 'digitalCountry', 'knownKwh', 'hours', 'units', 'gpu', 'service', 'model', 'provider', 'ownership'] as $field) {
            self::assertStringNotContainsString(sprintf('name="%s"', $field), $content);
        }
        self::assertStringContainsString('GDO COGENERACIÓN ALTA EFICIENCIA', $content);
        foreach (['factor', 'factorValue', 'factorYear', 'source', 'normalizedAmount', 'emission'] as $field) {
            self::assertStringNotContainsString(sprintf('name="%s"', $field), $content);
        }
    }

    public function testCreatePersistsModernAuthoritativeRecordAndIgnoresBrowserEmission(): void
    {
        $context = $this->context();
        $post = $this->validPost() + ['emission' => '999999', 'factorValue' => '999'];
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('energy_emission_v1_create'));
        $persisted = null;

        $response = $this->create($request, $context, 1, $persisted);

        self::assertSame(302, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        self::assertSame($context['category'], $persisted->getCategory());
        self::assertSame(10.0, $persisted->getAmount());
        self::assertSame(2.58, $persisted->getEmission());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $persisted->getStatus());
        self::assertSame('2025-06-01', $persisted->getRegisteredAt()->format('Y-m-d'));
        self::assertStringContainsString('"version":"energy-v1"', (string) $persisted->getCalculationDetails());
        self::assertStringNotContainsString('999999', (string) $persisted->getCalculationDetails());
        $snapshot = json_decode((string) $persisted->getCalculationDetails(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('energy-v1', $snapshot['calculatorVersion']);
        self::assertNull($snapshot['calculation']['factorTraces'][0]['factorVersion']);
    }

    public function testCreateRejectsRetiredDigitalFamilyWithoutPersistence(): void
    {
        $context = $this->context();
        $post = $this->validPost();
        $post['family'] = 'digital';
        $post['digitalType'] = 'ai';
        $post['amount'] = '';
        $post['origin'] = '';
        $post['hours'] = '2';
        $post['gpu'] = 'A100';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('energy_emission_v1_create'));
        $persisted = null;

        $response = $this->create($request, $context, 0, $persisted);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertNull($persisted);
    }

    public function testGetEditReconstructsFunctionalInputAndListsAttachment(): void
    {
        $context = $this->context();
        $record = $this->record($context, 'Nota original');
        $attachment = (new EmissionRecordAttachment())->setEmissionRecord($record)->setOriginalName('factura.pdf')
            ->setStoredName(str_repeat('a', 32).'.pdf')->setMimeType('application/pdf')->setSize(2048)->setCreatedAt(new \DateTimeImmutable());
        $this->setEntityId($attachment, 401);
        $record->addAttachment($attachment);

        $content = (string) $this->edit($record, $this->request('GET'), $context, 0)->getContent();

        self::assertStringContainsString('value="10"', $content);
        self::assertStringContainsString('value="2025-06-01"', $content);
        self::assertStringContainsString('Nota original', $content);
        self::assertStringContainsString('factura.pdf', $content);
        self::assertStringContainsString('/backend/emission/300/attachments/401/download', $content);
        self::assertStringContainsString('data-energy-v1-form-preview-url-value="/backend/emission/energy/preview"', $content);
        self::assertMatchesRegularExpression('/data-energy-v1-form-preview-token-value="[^"]+"/', $content);
        $this->assertInitialPreviewContext($content);
    }

    public function testRetiredDigitalFieldsFromSnapshotDoNotReappearInEdit(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $snapshot = json_decode((string) $record->getCalculationDetails(), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['family'] = 'digital';
        $snapshot['input']['family'] = 'digital';
        $snapshot['input']['digitalType'] = 'ai';
        $snapshot['input']['knownKwh'] = '10';
        $record->setCalculationDetails(json_encode($snapshot, JSON_THROW_ON_ERROR));

        $content = (string) $this->edit($record, $this->request('GET'), $context, 0)->getContent();

        self::assertStringNotContainsString('value="digital"', $content);
        self::assertStringNotContainsString('name="digitalType"', $content);
        self::assertStringNotContainsString('name="knownKwh"', $content);
        self::assertStringNotContainsString('value="ai"', $content);
    }

    public function testEditRecalculatesFromSubmittedInput(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $post = $this->validPost();
        $post['amount'] = '20';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('energy_emission_v1_edit_300'));

        $response = $this->edit($record, $request, $context, 1);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(20.0, $record->getAmount());
        self::assertSame(5.16, $record->getEmission());
        self::assertStringContainsString('"amount":"20"', (string) $record->getCalculationDetails());
    }

    public function testDuplicateUsesCreateFlowAndDoesNotCopyAttachments(): void
    {
        $context = $this->context();
        $record = $this->record($context, 'Duplicar nota');
        $attachment = (new EmissionRecordAttachment())->setEmissionRecord($record)->setOriginalName('no-copiar.pdf')
            ->setStoredName(str_repeat('b', 32).'.pdf')->setMimeType('application/pdf')->setSize(20)->setCreatedAt(new \DateTimeImmutable());
        $record->addAttachment($attachment);

        $response = $this->controller()->duplicate(
            $record,
            $this->request('GET', query: ['page' => '2']),
            $context['active'],
            $context['categories'],
            new EnergyEmissionSnapshot(),
            $this->uiCatalog(),
        );
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('action="/backend/emission/new-energy-v1?', $content);
        self::assertStringContainsString('Duplicar nota', $content);
        self::assertStringNotContainsString('no-copiar.pdf', $content);
        self::assertStringContainsString('value="10"', $content);
        $this->assertInitialPreviewContext($content);
    }

    public function testFrontendInitializesPreviewImmediatelyAfterReconstructingDependentFields(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../assets/controllers/energy_v1_form_controller.js');

        self::assertIsString($controller);
        self::assertMatchesRegularExpression(
            '/connect\(\)\s*\{\s*this\.populateFuels\(this\.initialValue\.fuel\);\s*this\.renderFields\(\);\s*this\.preview\(\);\s*\}/',
            $controller,
        );
        self::assertStringContainsString('if (!this.commonContextComplete)', $controller);
        self::assertStringContainsString("body.set('_preview_token', this.previewTokenValue)", $controller);
        self::assertStringContainsString('fetch(this.previewUrlValue', $controller);
        self::assertStringContainsString('trace.factorId', $controller);
        self::assertStringNotContainsString('digitalPanel', $controller);
    }

    /** @return array{project: Project, category: Category, phase: ProjectPhaseDate, active: ActiveProjectService&MockObject, categories: CategoryRepository&MockObject, projects: ProjectRepository&MockObject} */
    private function context(): array
    {
        $project = (new Project())->setName('Proyecto Energía')->setType('rodaje')->setCountry('ES');
        $this->setEntityId($project, 10);
        $category = (new Category())->setName('Energía');
        $this->setEntityId($category, 20);
        $phase = (new ProjectPhaseDate())->setProject($project)->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2025-01-01'))->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $project->addPhaseDate($phase);

        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($project);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('findOneBy')->willReturn($category);
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('findPhaseByDate')->willReturn($phase);

        return compact('project', 'category', 'phase', 'active', 'categories', 'projects');
    }

    /** @param array<string, mixed> $context */
    private function create(Request $request, array $context, int $persistCalls, ?EmissionRecord &$persisted = null): Response
    {
        return $this->controller()->create(
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new EnergyEmissionRequestMapper(),
            $this->recordService($persistCalls, $persisted),
            $this->uiCatalog(),
            new EmissionRecordAttachmentStorage($this->attachmentDirectory),
            $this->attachmentEntityManager(),
        );
    }

    /** @param array<string, mixed> $context */
    private function edit(EmissionRecord $record, Request $request, array $context, int $persistCalls): Response
    {
        $ignored = null;

        return $this->controller()->edit(
            $record,
            $request,
            $context['active'],
            $context['categories'],
            $context['projects'],
            new EnergyEmissionRequestMapper(),
            $this->recordService($persistCalls, $ignored),
            new EnergyEmissionSnapshot(),
            $this->uiCatalog(),
            new EmissionRecordAttachmentStorage($this->attachmentDirectory),
            $this->attachmentEntityManager(),
        );
    }

    private function controller(): EnergyEmissionController
    {
        $controller = new EnergyEmissionController();
        $controller->setContainer(self::getContainer());
        $this->setAdminToken();

        return $controller;
    }

    private function request(string $method, array $post = [], array $query = []): Request
    {
        $request = new Request($query, $post, [], [], [], ['REQUEST_METHOD' => $method]);
        $request->setLocale('es');
        $request->attributes->set('_route', 'backend_emission_new_energy_v1');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function calculator(): EnergyEmissionCalculator
    {
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturn($this->factor());
        $keys = new EmissionFactorKeyGenerator();
        $common = new EmissionFactorResolver($repository, $keys);

        return new EnergyEmissionCalculator(new ElectricityFactorResolver($common), new StationaryCombustionFactorResolver($common));
    }

    private function recordService(int $persistCalls, ?EmissionRecord &$persisted): EnergyEmissionRecordService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($persistCalls))->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            if ($entity instanceof EmissionRecord) {
                $persisted = $entity;
                if (null === $entity->getId()) {
                    $this->setEntityId($entity, 301);
                }
            }
        });
        $entityManager->expects(self::exactly($persistCalls))->method('flush');

        return new EnergyEmissionRecordService($this->calculator(), new EnergyEmissionSnapshot(), $entityManager);
    }

    private function uiCatalog(): EnergyUiCatalog
    {
        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findCriteriaByCategoryKey')->willReturn([
            ['category' => 'ELECTRICIDAD', 'supplier' => 'Proveedor', 'labeling' => 'GDO COGENERACIÓN ALTA EFICIENCIA'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'ESPAÑA', 'activity' => 'Diésel', 'unit' => 'litros'],
            ['category' => 'COMBUSTIÓN ESTACIONARIA', 'geography' => 'FUERA DE ESPAÑA', 'activity' => 'Diésel', 'unit' => 'litros'],
        ]);

        return new EnergyUiCatalog($repository);
    }

    private function attachmentEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        return $entityManager;
    }

    /** @param array<string, mixed> $context */
    private function record(array $context, ?string $notes = null): EmissionRecord
    {
        $input = (new EnergyEmissionRequestMapper())->map($this->request('POST', $this->validPost()));
        $result = $this->calculator()->calculate($input);
        $record = (new EmissionRecord())
            ->setProject($context['project'])
            ->setPhase($context['phase'])
            ->setCategory($context['category'])

            ->setAmount(10)
            ->setEmission(2.58)
            ->setStatus(EmissionRecord::STATUS_CALCULATED)
            ->setRegisteredAt(new \DateTimeImmutable('2025-06-01'))
            ->setNotes($notes)
            ->setCalculationDetails((new EnergyEmissionSnapshot())->encode($input, $result));
        $this->setEntityId($record, 300);

        return $record;
    }

    /** @return array<string, string> */
    private function validPost(): array
    {
        return [
            'family' => 'electricity',
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-30',
            'country' => 'ES',
            'origin' => 'grid',
            'amount' => '10',
            'unit' => 'kWh',
        ];
    }

    private function factor(): EmissionFactor
    {
        return (new EmissionFactor())
            ->setCategoryKey('energy')
            ->setFunctionalKey('server-key')
            ->setCriteria([])
            ->setFactorId('ENE-TEST-001')
            ->setActivityYear(2025)
            ->setYear(2025)
            ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL)
            ->setValue('0.258')
            ->setUnit('kgCO2e/kWh')
            ->setSource('MITECO')
            ->setSourceDetail('Backend')
            ->setMetadata(['factorVersion' => null, 'qualityStatus' => 'official']);
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
        (new \ReflectionClass($entity))->getProperty('id')->setValue($entity, $id);
    }

    private function assertInitialPreviewContext(string $content): void
    {
        self::assertSame(1, preg_match('/data-energy-v1-form-initial-value="([^"]+)"/', $content, $matches));
        $initial = json_decode(
            html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('electricity', $initial['family']);
        self::assertSame('ES', $initial['country']);
    }
}
