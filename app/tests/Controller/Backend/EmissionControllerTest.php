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
use App\Service\Emission\Accommodation\AccommodationEmissionInput;
use App\Service\Emission\Accommodation\AccommodationEmissionResult;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Energy\EnergyEmissionInput;
use App\Service\Emission\Energy\EnergyEmissionResult;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterEmissionResult;
use App\Service\Emission\Water\WaterEmissionSnapshot;
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
        self::assertStringContainsString('Alojamientos', $content);
        self::assertStringNotContainsString('Viajes', $content);
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

    public function testTransportKeepsModernCreateEditAndDuplicateRoutes(): void
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
    }

    public function testTripsIsDisabledAndLegacyRoutesAreRemoved(): void
    {
        $fixture = file_get_contents(__DIR__.'/../../../src/DataFixtures/AuxiliaryFixtures.php');
        self::assertIsString($fixture);
        self::assertStringContainsString(
            "['name' => 'Viajes', 'sortOrder' => 130, 'enabledInEmissionCalculator' => false]",
            $fixture,
        );

        $routes = self::getContainer()->get('router')->getRouteCollection();
        self::assertNull($routes->get('backend_emission_new_transport'));
        self::assertNull($routes->get('backend_emission_edit_transport'));
        self::assertNotNull($routes->get('backend_emission_new_transport_v20'));
        self::assertNotNull($routes->get('backend_emission_edit_transport_v20'));
        self::assertNotNull($routes->get('backend_emission_duplicate_transport_v20'));
    }

    public function testWaterUsesModernCreateEditAndDuplicateRoutes(): void
    {
        $payload = $this->buildPayload();
        $input = new WaterEmissionInput(
            new \DateTimeImmutable('2026-01-20'),
            new \DateTimeImmutable('2026-01-20'),
            'ES',
            WaterEmissionInput::USE_CLEANING,
            '1',
            'm3',
            WaterEmissionInput::DESTINATION_SEWER,
        );
        $result = new WaterEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '0.517',
            '1',
            'm3',
            2026,
            'ANNUAL',
        );
        $record = (new EmissionRecord())
            ->setProject($payload['project'])
            ->setPhase($payload['records'][0]->getPhase())
            ->setCategory($payload['categories'][3])
            ->setActivity(null)
            ->setAmount(1)
            ->setEmission(0.517)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-20'))
            ->setCalculationDetails((new WaterEmissionSnapshot())->encode($input, $result));
        $this->setEntityId($record, 996);

        $content = (string) $this->renderIndex(
            $payload['project'],
            [$record],
            $payload['categories'],
            ['categoryId' => 5],
        )->getContent();

        self::assertStringContainsString('/backend/emission/new-water-v1', $content);
        self::assertStringContainsString('Limpieza', $content);
        self::assertStringContainsString('/backend/emission/996/edit-water-v1', $content);
        self::assertStringContainsString('/backend/emission/996/duplicate-water-v1', $content);
    }

    public function testAccommodationUsesModernLabelCreateEditAndDuplicateRoutes(): void
    {
        $payload = $this->buildPayload();
        $input = new AccommodationEmissionInput(
            new \DateTimeImmutable('2026-01-20'),
            new \DateTimeImmutable('2026-01-20'),
            'ESP',
            AccommodationEmissionInput::TYPE_HOSTEL,
            null,
            null,
            '2',
            '3',
        );
        $result = new AccommodationEmissionResult(
            EmissionRecord::STATUS_CALCULATED,
            '9.5505',
            '6',
            'guest-night',
            2026,
            'ANNUAL',
        );
        $record = (new EmissionRecord())
            ->setProject($payload['project'])
            ->setPhase($payload['records'][0]->getPhase())
            ->setCategory($payload['categories'][4])
            ->setActivity(null)
            ->setAmount(6)
            ->setEmission(9.5505)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-20'))
            ->setCalculationDetails((new AccommodationEmissionSnapshot())->encode($input, $result));
        $this->setEntityId($record, 995);

        $content = (string) $this->renderIndex(
            $payload['project'],
            [$record],
            $payload['categories'],
            ['categoryId' => 3],
        )->getContent();

        self::assertStringContainsString('/backend/emission/new-accommodation-v1', $content);
        self::assertStringContainsString('Hostal / pensión', $content);
        self::assertStringContainsString('huésped-noche', $content);
        self::assertStringContainsString('/backend/emission/995/edit-accommodation-v1', $content);
        self::assertStringContainsString('/backend/emission/995/duplicate-accommodation-v1', $content);
    }

    public function testLegacyGenericCreateRouteRejectsWater(): void
    {
        $payload = $this->buildPayload();
        $project = $payload['project'];
        $water = $payload['categories'][3];
        $activeProject = $this->createMock(ActiveProjectService::class);
        $activeProject->method('getActiveProject')->willReturn($project);
        $categories = $this->createMock(CategoryRepository::class);
        $categories->method('find')->with(5)->willReturn($water);
        $controller = new EmissionController();
        $controller->setContainer(self::getContainer());
        $this->setAdminToken();

        $this->expectException(NotFoundHttpException::class);
        $controller->new(
            '5',
            new Request(),
            $activeProject,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ProjectRepository::class),
            $categories,
            $this->createMock(EmissionActivityRepository::class),
            self::getContainer()->get(\App\Service\Emission\WoodCatalog::class),
            self::getContainer()->get(\App\Service\Emission\WoodEmissionCalculator::class),
            self::getContainer()->get(TranslatorInterface::class),
        );
    }

    public function testLegacyGenericEditRouteRejectsWater(): void
    {
        $payload = $this->buildPayload();
        $water = $payload['categories'][3];
        $activity = (new EmissionActivity())
            ->setCategory($water)
            ->setName('Actividad residual')
            ->setUnit('litros')
            ->setEmissionFactor(0.1);
        $record = (new EmissionRecord())
            ->setProject($payload['project'])
            ->setPhase($payload['records'][0]->getPhase())
            ->setCategory($water)
            ->setActivity($activity)
            ->setAmount(10)
            ->setEmission(1)
            ->setRegisteredAt(new \DateTimeImmutable('2026-01-20'));
        $controller = new EmissionController();
        $controller->setContainer(self::getContainer());
        $this->setAdminToken();

        $this->expectException(NotFoundHttpException::class);
        $controller->edit(
            $record,
            new Request(),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ProjectRepository::class),
            $this->createMock(EmissionActivityRepository::class),
            self::getContainer()->get(\App\Service\Emission\WoodCatalog::class),
            self::getContainer()->get(\App\Service\Emission\WoodEmissionCalculator::class),
            self::getContainer()->get(TranslatorInterface::class),
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
            new WaterEmissionSnapshot(),
            new AccommodationEmissionSnapshot(),
            new CateringEmissionSnapshot(),
            $request
        );

        return $response;
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
        $empty = (new Category())->setName('Residuos');
        $generic = (new Category())->setName('Agua');
        $accommodation = (new Category())->setName('Alojamientos');
        $this->setEntityId($energy, 1);
        $this->setEntityId($transport, 2);
        $this->setEntityId($empty, 4);
        $this->setEntityId($generic, 5);
        $this->setEntityId($accommodation, 3);

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
            'categories' => [$energy, $transport, $empty, $generic, $accommodation],
            'records' => $records,
        ];
    }

    private function createEntityManagerMock(): EntityManagerInterface
    {
        $ids = [1, 2, 5, 3];

        $query = $this->createMock(Query::class);
        foreach (['setParameter', 'setMaxResults'] as $method) {
            $query->method($method)->willReturnSelf();
        }
        $query->method('getOneOrNullResult')->willReturnCallback(
            static function () use (&$ids): array {
                return ['id' => array_shift($ids)];
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
