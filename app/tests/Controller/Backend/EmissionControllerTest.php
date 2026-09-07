<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\EmissionController;
use App\Entity\Category;
use App\Entity\EmissionActivity;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\CategoryRepository;
use App\Repository\EmissionActivityRepository;
use App\Repository\EmissionRecordRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionInput;
use App\Service\Emission\Energy\EnergyEmissionResult;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmissionControllerTest extends KernelTestCase
{
    public function testIndexRendersFirstPageOfAllRecordsWithFullFooterTotal(): void
    {
        $payload = $this->buildPayload();

        $response = $this->renderIndex($payload['project'], $payload['records'], $payload['categories'], []);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/backend/emission/records?categoryId=1', $response->headers->get('Location'));
    }

    public function testIndexKeepsCategoryPagingAndFullCategoryTotal(): void
    {
        $payload = $this->buildPayload();

        $response = $this->renderIndex($payload['project'], $payload['records'], $payload['categories'], [
            'categoryId' => 1,
            'page' => 2,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();

        self::assertStringContainsString('emissions-pagination', $content);
        self::assertSame(2, substr_count($content, 'emissions-record-row'));
        self::assertStringContainsString('27,60', $content);
        self::assertStringContainsString('/backend/emission/new-energy-v1?page=2', $content);
        self::assertStringNotContainsString('/edit-energy?', $content);
        self::assertStringContainsString('categoryId=1', $content);
        self::assertStringContainsString('data-emission-target="chart"', $content);
        self::assertStringContainsString('data-chart-category="Energía"', $content);
        self::assertStringContainsString('emissions-chart', $content);
        self::assertStringNotContainsString('Todas', $content);
    }

    public function testIndexShowsCategoriesNormallyWhenThereAreNoRecords(): void
    {
        $payload = $this->buildPayload();

        $response = $this->renderIndex($payload['project'], [], $payload['categories'], [
            'categoryId' => 1,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();

        self::assertStringContainsString('emissions-category-panel', $content);
        self::assertStringContainsString('Energía', $content);
        self::assertStringContainsString('Transporte', $content);
        self::assertStringContainsString('Residuos', $content);
        self::assertStringContainsString('Agua', $content);
        self::assertStringNotContainsString('emissions-category-item--empty', $content);
        self::assertStringNotContainsString('Todas', $content);
    }

    public function testIndexRendersModernRecordWithoutActivity(): void
    {
        $payload = $this->buildPayload();
        $phase = $payload['records'][0]->getPhase();
        $record = (new EmissionRecord())
            ->setProject($payload['project'])
            ->setPhase($phase)
            ->setCategory($payload['categories'][1])
            ->setAmount(12)
            ->setEmission(3.5)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-20'));
        $this->setEntityId($record, 999);

        $response = $this->renderIndex($payload['project'], [$record], $payload['categories'], ['categoryId' => 2]);

        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringContainsString('—', $content);
        self::assertStringContainsString('/backend/emission/999/delete', $content);
        self::assertStringNotContainsString('/backend/emission/999/edit-transport-travel', $content);
        self::assertStringNotContainsString('/backend/emission/999/edit-transport', $content);
        self::assertStringNotContainsString('/backend/emission/999/duplicate-transport', $content);
    }

    public function testIndexRendersModernEnergyPendingStatusAndActions(): void
    {
        $payload = $this->buildPayload();
        $phase = $payload['records'][0]->getPhase();
        $input = new EnergyEmissionInput(
            'digital',
            new \DateTimeImmutable('2026-01-20'),
            new \DateTimeImmutable('2026-01-20'),
            'ES',
            digitalType: 'ai',
        );
        $result = new EnergyEmissionResult(
            EmissionRecord::STATUS_PENDING_DATA,
            null,
            null,
            null,
            2026,
            null,
            messages: ['digital_activity_data_required'],
        );
        $record = (new EmissionRecord())
            ->setProject($payload['project'])
            ->setPhase($phase)
            ->setCategory($payload['categories'][0])
            ->setActivity(null)
            ->setAmount(null)
            ->setEmission(null)
            ->setStatus(EmissionRecord::STATUS_PENDING_DATA)
            ->setCalculationDetails((new EnergyEmissionSnapshot())->encode($input, $result))
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-20'));
        $this->setEntityId($record, 998);

        $content = (string) $this->renderIndex($payload['project'], [$record], $payload['categories'], ['categoryId' => 1])->getContent();

        self::assertStringContainsString('Tecnología digital', $content);
        self::assertStringContainsString('Pendiente', $content);
        self::assertStringContainsString('/backend/emission/998/edit-energy-v1', $content);
        self::assertStringContainsString('/backend/emission/998/duplicate-energy-v1', $content);
    }

    public function testTransportAndTripsKeepSeparateCreateAndEditRoutes(): void
    {
        $payload = $this->buildPayload();
        $phase = $payload['records'][0]->getPhase();
        $modern = (new EmissionRecord())
            ->setProject($payload['project'])
            ->setPhase($phase)
            ->setCategory($payload['categories'][1])
            ->setAmount(10)
            ->setEmission(2)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-20'))
            ->setCalculationDetails('{"version":"transport-v20"}');
        $this->setEntityId($modern, 998);

        $transportResponse = $this->renderIndex(
            $payload['project'],
            [$modern],
            $payload['categories'],
            ['categoryId' => 2],
        );
        $transportContent = (string) $transportResponse->getContent();
        self::assertStringContainsString('/backend/emission/new-transport', $transportContent);
        self::assertStringContainsString('/backend/emission/998/edit-transport', $transportContent);
        self::assertStringContainsString('/backend/emission/998/duplicate-transport', $transportContent);

        $tripsActivity = (new EmissionActivity())
            ->setName('Avión')
            ->setUnit('km')
            ->setEmissionFactor(0.2)
            ->setCategory($payload['categories'][2]);
        $tripsRecord = (new EmissionRecord())
            ->setProject($payload['project'])
            ->setPhase($phase)
            ->setCategory($payload['categories'][2])
            ->setActivity($tripsActivity)
            ->setAmount(10)
            ->setEmission(2)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-20'));
        $this->setEntityId($tripsRecord, 997);
        $tripsResponse = $this->renderIndex($payload['project'], [$tripsRecord], $payload['categories'], ['categoryId' => 3]);
        $tripsContent = (string) $tripsResponse->getContent();
        self::assertStringContainsString('/backend/emission/new-transport-travel/3', $tripsContent);
        self::assertStringContainsString('/backend/emission/997/edit-transport-travel', $tripsContent);
        self::assertStringNotContainsString('/backend/emission/997/duplicate-transport', $tripsContent);
    }

    public function testLegacyTransportCreateRouteStillRendersForTrips(): void
    {
        $context = $this->legacyTransportRouteContext();

        $response = $context['controller']->newTransport(
            '3',
            $context['request'],
            $context['activeProject'],
            $context['activities'],
            $context['entityManager'],
            $context['categories'],
            $context['projects'],
            $context['translator'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-controller="transport-form"', (string) $response->getContent());
    }

    public function testLegacyTransportCreateRouteRejectsTransport(): void
    {
        $context = $this->legacyTransportRouteContext();

        $this->expectException(NotFoundHttpException::class);
        $context['controller']->newTransport(
            '2',
            $context['request'],
            $context['activeProject'],
            $context['activities'],
            $context['entityManager'],
            $context['categories'],
            $context['projects'],
            $context['translator'],
        );
    }

    public function testLegacyTransportEditRouteStillRendersForTrips(): void
    {
        $context = $this->legacyTransportRouteContext();
        $record = $this->legacyTransportRecord(
            $context['project'],
            $context['phase'],
            $context['trips'],
            301,
        );

        $response = $context['controller']->editTransport(
            $context['request'],
            $record,
            $context['activeProject'],
            $context['activities'],
            $context['projects'],
            $context['categories'],
            $context['entityManager'],
            $context['translator'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-controller="transport-form"', (string) $response->getContent());
    }

    public function testLegacyTransportEditRouteRejectsTransportRecord(): void
    {
        $context = $this->legacyTransportRouteContext();
        $record = $this->legacyTransportRecord(
            $context['project'],
            $context['phase'],
            $context['transport'],
            302,
        );

        $this->expectException(NotFoundHttpException::class);
        $context['controller']->editTransport(
            $context['request'],
            $record,
            $context['activeProject'],
            $context['activities'],
            $context['projects'],
            $context['categories'],
            $context['entityManager'],
            $context['translator'],
        );
    }

    public function testDeleteRecordRemovesAttachmentFileBeforeEntity(): void
    {
        $payload = $this->buildPayload();
        $record = $payload['records'][0];
        $directory = sys_get_temp_dir().'/bgfm-delete-record-'.bin2hex(random_bytes(8));
        $storage = new EmissionRecordAttachmentStorage($directory);
        $path = tempnam(sys_get_temp_dir(), 'record-pdf-');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $attachment = $storage->store($record, new UploadedFile($path, 'record.pdf', null, null, true));
        $this->setEntityId($attachment, 700);
        $physicalPath = $storage->absolutePath($attachment);

        $controller = new EmissionController();
        $controller->setContainer(self::getContainer());
        $this->setAdminToken();
        $request = new Request([], ['category' => 'Energía'], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')->getToken('delete'.$record->getId())->getValue());
        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($payload['project']);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('findOneBy')->willReturn($payload['categories'][0]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($record);
        $entityManager->expects(self::once())->method('flush');

        $response = $controller->delete($record, $request, $entityManager, $categories, $active, $storage, self::getContainer()->get(TranslatorInterface::class));

        self::assertSame(302, $response->getStatusCode());
        self::assertFileDoesNotExist($physicalPath);
        rmdir($directory.'/'.$payload['project']->getId());
        rmdir($directory);
    }

    private function renderIndex(Project $project, array $records, array $categories, array $query): \Symfony\Component\HttpFoundation\Response
    {
        $controller = new EmissionController();
        $controller->setContainer(self::getContainer());
        $this->setAdminToken();
        $this->ensureTwigGlobals($project);

        $request = new Request($query);
        $request->attributes->set('_route', 'backend_emission_index');
        $request->attributes->set('_route_params', []);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        $recordRepository = $this->createMock(EmissionRecordRepository::class);
        $recordRepository->method('findByProjectOrderByPhaseAndDate')->willReturn($records);

        $categoryRepository = $this->createMock(CategoryRepository::class);
        $categoryRepository->method('findEnabledInEmissionCalculator')->willReturn($categories);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->method('getActiveProject')->willReturn($project);

        $response = $controller->index(
            $recordRepository,
            $activeProjectService,
            $categoryRepository,
            $this->createEntityManagerMock(),
            self::getContainer()->get(TranslatorInterface::class),
            new TransportEmissionSnapshot(),
            new EnergyEmissionSnapshot(),
            $request
        );

        return $response;
    }

    /** @return array<string, mixed> */
    private function legacyTransportRouteContext(): array
    {
        $project = (new Project())->setName('Proyecto')->setType('rodaje')->setCountry('ES');
        $this->setEntityId($project, 99);
        $transport = (new Category())->setName('Transporte');
        $trips = (new Category())->setName('Viajes');
        $this->setEntityId($transport, 2);
        $this->setEntityId($trips, 3);
        $phase = (new ProjectPhaseDate())
            ->setProject($project)
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-01-31'));

        $activities = $this->createMock(EmissionActivityRepository::class);
        $activities->method('getSubcategoriesByCategoryId')->willReturn(['aereo']);
        self::getContainer()->set(EmissionActivityRepository::class, $activities);

        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('find')->willReturnMap([
            [2, $transport],
            [3, $trips],
        ]);
        $categories->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?Category => ['name' => 'Viajes'] === $criteria ? $trips : null,
        );
        $activeProject = $this->createMock(ActiveProjectService::class);
        $activeProject->method('getActiveProject')->willReturn($project);

        $controller = new EmissionController();
        $controller->setContainer(self::getContainer());
        $this->setAdminToken();
        $this->ensureTwigGlobals($project);
        $request = new Request();
        $request->attributes->set('_route', 'backend_emission_new_transport');
        $request->attributes->set('_route_params', ['category' => '3']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return [
            'controller' => $controller,
            'request' => $request,
            'project' => $project,
            'phase' => $phase,
            'transport' => $transport,
            'trips' => $trips,
            'activities' => $activities,
            'categories' => $categories,
            'activeProject' => $activeProject,
            'projects' => $this->createMock(ProjectRepository::class),
            'entityManager' => $this->createMock(EntityManagerInterface::class),
            'translator' => self::getContainer()->get(TranslatorInterface::class),
        ];
    }

    private function legacyTransportRecord(
        Project $project,
        ProjectPhaseDate $phase,
        Category $category,
        int $id,
    ): EmissionRecord {
        $activity = (new EmissionActivity())
            ->setCategory($category)
            ->setName('Actividad')
            ->setUnit('km')
            ->setEmissionFactor(0.1)
            ->setSubcategory('aereo');
        $record = (new EmissionRecord())
            ->setProject($project)
            ->setPhase($phase)
            ->setCategory($category)
            ->setActivity($activity)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-10'))
            ->setAmount(10)
            ->setEmission(1);
        $this->setEntityId($record, $id);

        return $record;
    }

    private function buildPayload(): array
    {
        $project = (new Project())
            ->setName('Proyecto Fede')
            ->setType('rodaje')
            ->setCountry('ES');
        $this->setEntityId($project, 99);

        $energy = (new Category())->setName('Energía');
        $transport = (new Category())->setName('Transporte');
        $trips = (new Category())->setName('Viajes');
        $empty = (new Category())->setName('Residuos');
        $generic = (new Category())->setName('Agua');
        $this->setEntityId($energy, 1);
        $this->setEntityId($transport, 2);
        $this->setEntityId($trips, 3);
        $this->setEntityId($empty, 4);
        $this->setEntityId($generic, 5);

        $energyActivity = (new EmissionActivity())
            ->setName('Electricidad')
            ->setUnit('kWh')
            ->setEmissionFactor(0.23)
            ->setCategory($energy);
        $this->setEntityId($energyActivity, 11);

        $transportActivity = (new EmissionActivity())
            ->setName('Furgoneta')
            ->setUnit('km')
            ->setEmissionFactor(0.45)
            ->setCategory($transport);
        $this->setEntityId($transportActivity, 12);

        $phase = (new ProjectPhaseDate())
            ->setPhase('actividad')
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))
            ->setEndDate(new \DateTimeImmutable('2026-01-31'))
            ->setProject($project);
        $this->setEntityId($phase, 21);

        $records = [];
        foreach (range(1, 12) as $i) {
            $record = (new EmissionRecord())
                ->setProject($project)
                ->setPhase($phase)
                ->setActivity($energyActivity)
                ->setAmount(10)
                ->setEmission(2.3)
                ->setRegisteredAt(new \DateTimeImmutable(sprintf('2026-01-%02d', 9 + $i)));
            $this->setEntityId($record, 100 + $i);
            $records[] = $record;
        }

        foreach (range(1, 3) as $i) {
            $record = (new EmissionRecord())
                ->setProject($project)
                ->setPhase($phase)
                ->setActivity($transportActivity)
                ->setAmount(20)
                ->setEmission(9.0)
                ->setRegisteredAt(new \DateTimeImmutable(sprintf('2026-02-%02d', $i)));
            $this->setEntityId($record, 200 + $i);
            $records[] = $record;
        }

        return [
            'project' => $project,
            'categories' => [$energy, $transport, $trips, $empty, $generic],
            'records' => $records,
        ];
    }

    private function createEntityManagerMock(): EntityManagerInterface
    {
        $call = 0;

        $query = $this->createMock(Query::class);
        foreach (['setParameter', 'setMaxResults'] as $method) {
            $query->method($method)->willReturnSelf();
        }
        $query->method('getOneOrNullResult')->willReturnCallback(
            static function () use (&$call): array {
                $call++;

                return ['id' => $call];
            }
        );

        /** @var EntityManagerInterface&MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturn($query);

        return $entityManager;
    }

    private function ensureTwigGlobals(Project $project): void
    {
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', []);
        $twig->addGlobal('activeProject', $project);
    }

    private function setAdminToken(): void
    {
        $user = new \App\Entity\User();
        $user
            ->setName('Admin')
            ->setSurnames('User')
            ->setEmail('admin@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN']);

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionClass($entity);
        while ($ref && !$ref->hasProperty('id')) {
            $ref = $ref->getParentClass();
        }

        if (!$ref) {
            throw new \RuntimeException('Entity does not have an id property.');
        }

        $property = $ref->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
