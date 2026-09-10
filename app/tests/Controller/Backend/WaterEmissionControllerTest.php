<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\WaterEmissionController;
use App\DataFixtures\WaterEmissionFactorFixtures;
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
use App\Service\Emission\Water\WaterEmissionCalculator;
use App\Service\Emission\Water\WaterEmissionRecordService;
use App\Service\Emission\Water\WaterEmissionRequestMapper;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use App\Service\Emission\Water\WaterFactorResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class WaterEmissionControllerTest extends KernelTestCase
{
    private string $attachmentDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attachmentDirectory = sys_get_temp_dir().'/bgfm-water-attachments-'.bin2hex(random_bytes(8));
    }

    public function testPreviewSpain2024SewerUsesOccc(): void
    {
        $response = $this->preview($this->validPost());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $data['status']);
        self::assertSame('1', $data['normalizedAmount']);
        self::assertSame('0.517', $data['emissionKgCo2e']);
        self::assertCount(1, $data['factorTraces']);
        self::assertSame('OCCC', $data['factorTraces'][0]['source']);
        self::assertSame('AGU_AAB6F0C556EADF', $data['factorTraces'][0]['factorId']);
        self::assertSame(2024, $data['factorTraces'][0]['activityYear']);
        self::assertSame(2024, $data['factorTraces'][0]['factorYear']);
        self::assertSame('VERSIONED', $data['factorTraces'][0]['temporalType']);
        self::assertSame('2025', $data['factorTraces'][0]['sourceEdition']);
        self::assertSame('2025', $data['factorTraces'][0]['factorVersion']);
        self::assertSame('España/ES', $data['factorTraces'][0]['targetGeography']);
        self::assertSame('Cataluña', $data['factorTraces'][0]['sourceGeography']);
        self::assertTrue($data['factorTraces'][0]['geographicProxy']);
    }

    public function testPreviewUnitedKingdomSewerReturnsTwoComponents(): void
    {
        $post = $this->validPost();
        $post['country'] = 'GBR';
        $response = $this->preview($post);
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('0.33885', $data['emissionKgCo2e']);
        self::assertCount(2, $data['factorTraces']);
        self::assertSame(['water_supply', 'water_treatment'], array_column($data['factorTraces'], 'component'));
    }

    public function testPreviewSpain2026ExposesTemporalFallback(): void
    {
        $post = $this->validPost();
        $post['startDate'] = '2026-01-01';
        $post['endDate'] = '2026-12-31';
        $data = json_decode((string) $this->preview($post)->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('0.517', $data['emissionKgCo2e']);
        self::assertSame(2025, $data['factorTraces'][0]['factorYear']);
        self::assertTrue($data['factorTraces'][0]['fallback']);
        self::assertSame('BAJA', $data['factorTraces'][0]['dataQuality']);
    }

    public function testPreviewIncompleteInputReturnsSafeValidationError(): void
    {
        $post = $this->validPost();
        unset($post['volumeInput']);
        $response = $this->preview($post);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(
            ['error' => 'invalid_input'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testPreviewIgnoresClientCalculationAuthority(): void
    {
        $post = $this->validPost() + [
            'emission' => '999999',
            'factor' => '999',
            'factorYear' => '1900',
            'functionalKey' => 'browser-key',
            'source' => 'browser-source',
        ];
        $data = json_decode((string) $this->preview($post)->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('0.517', $data['emissionKgCo2e']);
        self::assertSame(2024, $data['factorTraces'][0]['factorYear']);
        self::assertSame('OCCC', $data['factorTraces'][0]['source']);
    }

    public function testGetCreateRendersModernFormWithoutAuthoritativeFields(): void
    {
        $context = $this->context();
        $content = (string) $this->create($this->request('GET'), $context, 0)->getContent();

        self::assertStringContainsString('data-controller="water-v1-form"', $content);
        self::assertStringContainsString('name="attachments[]"', $content);
        self::assertStringContainsString('piscina/tanque', $content);
        foreach (['factor', 'factorValue', 'factorYear', 'functionalKey', 'normalizedAmount', 'emission', 'source'] as $field) {
            self::assertStringNotContainsString(sprintf('name="%s"', $field), $content);
        }
    }

    public function testFrontendShowsRealFactorIdAndEditionOnlyWhenPresent(): void
    {
        $source = file_get_contents(__DIR__.'/../../../assets/controllers/water_v1_form_controller.js');

        self::assertIsString($source);
        self::assertStringContainsString('if (trace.factorId)', $source);
        self::assertStringContainsString('trace.sourceEdition || trace.factorVersion', $source);
        self::assertStringContainsString('if (editionOrVersion)', $source);
        self::assertStringNotContainsString('water-v1', $source);
    }

    public function testCreatePersistsAuthoritativeModernWaterRecord(): void
    {
        $context = $this->context();
        $post = $this->validPost() + ['emission' => '999', 'factorValue' => '999', 'notes' => '  Lectura contador  '];
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('water_emission_v1_create'));
        $persisted = null;

        $response = $this->create($request, $context, 1, $persisted);

        self::assertSame(302, $response->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $persisted);
        self::assertSame($context['category'], $persisted->getCategory());
        self::assertSame(1.0, $persisted->getAmount());
        self::assertSame(0.517, $persisted->getEmission());
        self::assertSame(EmissionRecord::STATUS_CALCULATED, $persisted->getStatus());
        self::assertSame('Lectura contador', $persisted->getNotes());
        self::assertStringContainsString('"version":"water-v1"', (string) $persisted->getCalculationDetails());
        self::assertStringContainsString('"factorTraces"', (string) $persisted->getCalculationDetails());
        self::assertStringNotContainsString('"factorVersion":"water-v1"', (string) $persisted->getCalculationDetails());
        self::assertStringNotContainsString('999', (string) $persisted->getCalculationDetails());
    }

    public function testEditRecalculatesAndReplacesOldSnapshotAuthority(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $oldSnapshot = $record->getCalculationDetails();
        $post = $this->validPost();
        $post['volumeInput'] = '2';
        $post['emission'] = '777';
        $request = $this->request('POST', $post);
        $request->request->set('_token', $this->csrfToken('water_emission_v1_edit_300'));

        $response = $this->edit($record, $request, $context, 1);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(2.0, $record->getAmount());
        self::assertSame(1.034, $record->getEmission());
        self::assertNotSame($oldSnapshot, $record->getCalculationDetails());
        self::assertStringContainsString('"volumeInput":"2"', (string) $record->getCalculationDetails());
        self::assertStringNotContainsString('777', (string) $record->getCalculationDetails());
    }

    public function testDuplicateUsesCreateFlowAndDoesNotCopyAttachments(): void
    {
        $context = $this->context();
        $record = $this->record($context, 'Duplicar nota');
        $attachment = (new EmissionRecordAttachment())
            ->setEmissionRecord($record)
            ->setOriginalName('no-copiar.pdf')
            ->setStoredName(str_repeat('b', 32).'.pdf')
            ->setMimeType('application/pdf')
            ->setSize(20)
            ->setCreatedAt(new \DateTimeImmutable());
        $record->addAttachment($attachment);

        $response = $this->controller()->duplicate(
            $record,
            $this->request('GET', query: ['page' => '2']),
            $context['active'],
            $context['categories'],
            new WaterEmissionSnapshot(),
        );
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('action="/backend/emission/new-water-v1?', $content);
        self::assertStringContainsString('Duplicar nota', $content);
        self::assertStringContainsString('value="1"', $content);
        self::assertStringNotContainsString('no-copiar.pdf', $content);

        $post = $this->validPost() + ['emission' => '999', 'notes' => 'Duplicar nota'];
        $request = $this->request('POST', $post, ['page' => '2']);
        $request->request->set('_token', $this->csrfToken('water_emission_v1_create'));
        $duplicate = null;

        $createResponse = $this->create($request, $context, 1, $duplicate);

        self::assertSame(302, $createResponse->getStatusCode());
        self::assertInstanceOf(EmissionRecord::class, $duplicate);
        self::assertNotSame($record, $duplicate);
        self::assertSame(0.517, $duplicate->getEmission());
        self::assertSame('Duplicar nota', $duplicate->getNotes());
        self::assertCount(0, $duplicate->getAttachments());
        self::assertStringNotContainsString('999', (string) $duplicate->getCalculationDetails());
    }

    public function testEditListsExistingAttachment(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $attachment = (new EmissionRecordAttachment())
            ->setEmissionRecord($record)
            ->setOriginalName('contador.pdf')
            ->setStoredName(str_repeat('a', 32).'.pdf')
            ->setMimeType('application/pdf')
            ->setSize(2048)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->setEntityId($attachment, 401);
        $record->addAttachment($attachment);

        $content = (string) $this->edit($record, $this->request('GET'), $context, 0)->getContent();

        self::assertStringContainsString('contador.pdf', $content);
        self::assertStringContainsString('/backend/emission/300/attachments/401/download', $content);
    }

    public function testEditHidesRecordOwnedByAnotherProject(): void
    {
        $context = $this->context();
        $record = $this->record($context);
        $other = (new Project())->setName('Otro')->setType('rodaje')->setCountry('ES');
        $record->setProject($other);

        $this->expectException(NotFoundHttpException::class);
        $this->edit($record, $this->request('GET'), $context, 0);
    }

    public function testInvalidCreateCsrfDoesNotPersist(): void
    {
        $context = $this->context();
        $response = $this->create($this->request('POST', $this->validPost()), $context, 0);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('La sesión del formulario ha caducado', (string) $response->getContent());
    }

    /** @param array<string, string> $post */
    private function preview(array $post): Response
    {
        $context = $this->context();
        $request = $this->request('POST', $post);
        $request->request->set('_preview_token', $this->csrfToken('water_emission_v1_preview'));

        return $this->controller()->preview(
            $request,
            $context['active'],
            new WaterEmissionRequestMapper(),
            $this->calculator(),
        );
    }

    /** @return array{project: Project, category: Category, phase: ProjectPhaseDate, active: ActiveProjectService&MockObject, categories: CategoryRepository&MockObject, projects: ProjectRepository&MockObject} */
    private function context(): array
    {
        $project = (new Project())->setName('Proyecto Agua')->setType('rodaje')->setCountry('ES');
        $this->setEntityId($project, 10);
        $category = (new Category())->setName('Agua');
        $this->setEntityId($category, 20);
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2024-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-12-31'));
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
            new WaterEmissionRequestMapper(),
            $this->recordService($persistCalls, $persisted),
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
            new WaterEmissionRequestMapper(),
            $this->recordService($persistCalls, $ignored),
            new WaterEmissionSnapshot(),
            new EmissionRecordAttachmentStorage($this->attachmentDirectory),
            $this->attachmentEntityManager(),
        );
    }

    private function controller(): WaterEmissionController
    {
        $controller = new WaterEmissionController();
        $controller->setContainer(self::getContainer());
        $user = (new \App\Entity\User())
            ->setName('Admin')
            ->setSurnames('User')
            ->setEmail('admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);

        return $controller;
    }

    private function request(string $method, array $post = [], array $query = []): Request
    {
        $request = new Request($query, $post, [], [], [], ['REQUEST_METHOD' => $method]);
        $request->setLocale('es');
        $request->attributes->set('_route', 'backend_emission_new_water_v1');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrfToken(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    private function calculator(): WaterEmissionCalculator
    {
        $keyGenerator = new EmissionFactorKeyGenerator();
        $factors = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $factor) use (&$factors): void {
            if ($factor instanceof EmissionFactor) {
                $factors[] = $factor;
            }
        });
        (new WaterEmissionFactorFixtures($keyGenerator))->load($manager);

        $repository = $this->createMock(EmissionFactorRepository::class);
        $repository->method('findForActivityYear')->willReturnCallback(
            static function (string $categoryKey, string $functionalKey, int $activityYear) use (&$factors): ?EmissionFactor {
                self::assertSame('water', $categoryKey);
                $candidates = array_filter(
                    $factors,
                    static fn (EmissionFactor $factor): bool => $factor->getFunctionalKey() === $functionalKey
                        && $factor->getYear() <= $activityYear,
                );
                usort($candidates, static fn (EmissionFactor $left, EmissionFactor $right): int => $right->getYear() <=> $left->getYear());

                return $candidates[0] ?? null;
            },
        );

        return new WaterEmissionCalculator(
            new WaterFactorResolver(new EmissionFactorResolver($repository, $keyGenerator)),
        );
    }

    private function recordService(int $persistCalls, ?EmissionRecord &$persisted): WaterEmissionRecordService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly($persistCalls))->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                if ($entity instanceof EmissionRecord) {
                    $persisted = $entity;
                    if (null === $entity->getId()) {
                        $this->setEntityId($entity, 301);
                    }
                }
            },
        );
        $entityManager->expects(self::exactly($persistCalls))->method('flush');

        return new WaterEmissionRecordService($this->calculator(), new WaterEmissionSnapshot(), $entityManager);
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
        $input = (new WaterEmissionRequestMapper())->map($this->request('POST', $this->validPost()));
        $result = $this->calculator()->calculate($input);
        $record = (new EmissionRecord())
            ->setProject($context['project'])
            ->setPhase($context['phase'])
            ->setCategory($context['category'])

            ->setAmount(1)
            ->setEmission(0.517)
            ->setStatus(EmissionRecord::STATUS_CALCULATED)
            ->setRegisteredAt(new \DateTimeImmutable('2024-06-01'))
            ->setNotes($notes)
            ->setCalculationDetails((new WaterEmissionSnapshot())->encode($input, $result));
        $this->setEntityId($record, 300);

        return $record;
    }

    /** @return array<string, string> */
    private function validPost(): array
    {
        return [
            'startDate' => '2024-06-01',
            'endDate' => '2024-06-30',
            'country' => 'ESP',
            'waterUseType' => 'limpieza',
            'volumeInput' => '1',
            'volumeInputUnit' => 'm3',
            'destination' => 'sewer',
        ];
    }

    private function setEntityId(object $entity, int $id): void
    {
        (new \ReflectionClass($entity))->getProperty('id')->setValue($entity, $id);
    }
}
