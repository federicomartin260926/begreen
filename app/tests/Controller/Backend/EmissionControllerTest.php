<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\EmissionController;
use App\Entity\Category;
use App\Entity\EmissionActivity;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Repository\CategoryRepository;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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
        self::assertStringContainsString('/backend/emission/new-energy?page=2', $content);
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
        $categoryRepository->method('findAll')->willReturn($categories);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->method('getActiveProject')->willReturn($project);

        $response = $controller->index(
            $recordRepository,
            $activeProjectService,
            $categoryRepository,
            $this->createEntityManagerMock(),
            self::getContainer()->get(TranslatorInterface::class),
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
        $this->setEntityId($energy, 1);
        $this->setEntityId($transport, 2);
        $this->setEntityId($empty, 3);
        $this->setEntityId($generic, 4);

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
            'categories' => [$energy, $transport, $empty, $generic],
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
