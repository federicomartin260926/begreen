<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\CrewCatalogController;
use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Entity\User;
use App\Form\CrewDepartmentType;
use App\Form\CrewPositionType;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CrewCatalogControllerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private FormFactoryInterface $formFactory;
    private CrewDepartmentRepository $departmentRepository;
    private CrewPositionRepository $positionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
        $this->formFactory = $container->get('form.factory');
        $this->departmentRepository = $container->get(CrewDepartmentRepository::class);
        $this->positionRepository = $container->get(CrewPositionRepository::class);

        $user = (new User())
            ->setName('Crew')
            ->setSurnames('Catalog Admin')
            ->setEmail('crew.catalog.admin@example.test')
            ->setPassword('not-used')
            ->setRoles(['ROLE_SUPER_ADMIN'])
            ->setIsVerified(true);
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $container->get('twig')->addGlobal('userProjects', []);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        self::ensureKernelShutdown();
    }

    public function testListsEachScopeInDocumentOrder(): void
    {
        $expectations = [
            CrewDepartment::SCOPE_FILMING => [22, 'ARTE'],
            CrewDepartment::SCOPE_EVENT => [22, 'PRODUCCIÓN'],
            CrewDepartment::SCOPE_ANIMATION => [25, 'DESARROLLO Y GUION'],
        ];
        $controller = $this->controller();

        foreach ($expectations as $scope => [$count, $firstDepartment]) {
            $request = Request::create('/admin/crew-catalog/?scope='.$scope);
            $request->setLocale('es');
            $request->setSession(new Session(new MockArraySessionStorage()));
            $request->attributes->set('_route', 'admin_crew_catalog_index');
            $request->attributes->set('_route_params', []);
            self::getContainer()->get('request_stack')->push($request);
            $departments = $this->departmentRepository->findByScope($scope);
            $response = $controller->index($request, $this->departmentRepository);
            self::getContainer()->get('request_stack')->pop();

            self::assertCount($count, $departments);
            self::assertSame(10, $departments[0]->getSortOrder());
            self::assertSame($firstDepartment, $departments[0]->getName());
            self::assertTrue(strpos((string) $response->getContent(), $firstDepartment) < strpos((string) $response->getContent(), $departments[1]->getName()));
        }
    }

    public function testCreatesDepartmentsForAllScopesWithContextualNextOrder(): void
    {
        foreach (CrewDepartment::SCOPES as $scope) {
            $expectedOrder = $this->departmentRepository->nextSortOrderForScope($scope);
            $department = new CrewDepartment();
            $form = $this->formFactory->create(CrewDepartmentType::class, $department, ['csrf_protection' => false]);
            $form->submit([
                'name' => 'TEMP '.$scope.' '.uniqid(),
                'name_en' => '',
                'scope' => $scope,
                'sortOrder' => '',
            ]);

            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            if ($department->getSortOrder() <= 0) {
                $department->setSortOrder($this->departmentRepository->nextSortOrderForScope($scope));
            }
            $this->entityManager->persist($department);
            $this->entityManager->flush();

            self::assertSame($expectedOrder, $department->getSortOrder());
        }
    }

    public function testDepartmentUniquenessIsScoped(): void
    {
        $name = 'TEMP SHARED '.uniqid();
        $this->createDepartment($name, CrewDepartment::SCOPE_FILMING);

        $duplicate = $this->departmentForm($name, CrewDepartment::SCOPE_FILMING);
        self::assertFalse($duplicate->isValid());
        self::assertStringContainsString('Ya existe un departamento', (string) $duplicate->getErrors(true));

        $otherScope = $this->departmentForm($name, CrewDepartment::SCOPE_EVENT);
        self::assertTrue($otherScope->isValid(), (string) $otherScope->getErrors(true));
    }

    public function testListsAndCreatesPositionsInDocumentOrderWithContextualNextOrder(): void
    {
        $department = $this->department(CrewDepartment::SCOPE_FILMING, 'ARTE');
        $positions = $this->positionRepository->findByCrewDepartment($department);
        $request = Request::create('/admin/crew-catalog/departments/'.$department->getId().'/positions');
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set('_route', 'admin_crew_catalog_positions');
        $request->attributes->set('_route_params', ['id' => $department->getId()]);
        self::getContainer()->get('request_stack')->push($request);
        $response = $this->controller()->positions($department, $this->positionRepository);
        self::getContainer()->get('request_stack')->pop();

        self::assertCount(10, $positions);
        self::assertSame(10, $positions[0]->getSortOrder());
        self::assertSame('Diseñador/a de producción', $positions[0]->getName());
        self::assertTrue(strpos((string) $response->getContent(), $positions[0]->getName()) < strpos((string) $response->getContent(), $positions[1]->getName()));

        $expectedOrder = $this->positionRepository->nextSortOrderForDepartment($department);
        $position = new CrewPosition();
        $form = $this->positionForm($position, 'Cargo temporal '.uniqid(), $department, '');
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        if ($position->getSortOrder() <= 0) {
            $position->setSortOrder($this->positionRepository->nextSortOrderForDepartment($department));
        }
        $this->entityManager->persist($position);
        $this->entityManager->flush();

        self::assertSame($expectedOrder, $position->getSortOrder());
    }

    public function testPositionRequiresDepartmentAndUniquenessIsScopedToDepartment(): void
    {
        $art = $this->department(CrewDepartment::SCOPE_FILMING, 'ARTE');
        $production = $this->department(CrewDepartment::SCOPE_FILMING, 'PRODUCCIÓN');
        $name = 'Cargo compartido '.uniqid();
        $this->createPosition($name, $art);

        $duplicate = $this->positionForm(new CrewPosition(), $name, $art, '10');
        self::assertFalse($duplicate->isValid());
        self::assertStringContainsString('Ya existe un cargo', (string) $duplicate->getErrors(true));

        $otherDepartment = $this->positionForm(new CrewPosition(), $name, $production, '10');
        self::assertTrue($otherDepartment->isValid(), (string) $otherDepartment->getErrors(true));

        $missingDepartment = $this->positionForm(new CrewPosition(), 'Sin departamento '.uniqid(), null, '10');
        self::assertFalse($missingDepartment->isValid());
    }

    public function testRoutesExposeCompleteCrewCrud(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();
        foreach ([
            'admin_crew_catalog_index',
            'admin_crew_catalog_department_new',
            'admin_crew_catalog_department_edit',
            'admin_crew_catalog_department_delete',
            'admin_crew_catalog_positions',
            'admin_crew_catalog_position_new',
            'admin_crew_catalog_position_edit',
            'admin_crew_catalog_position_delete',
        ] as $routeName) {
            self::assertNotNull($routes->get($routeName), $routeName);
        }
    }

    private function controller(): CrewCatalogController
    {
        $controller = new CrewCatalogController();
        $controller->setContainer(self::getContainer());

        return $controller;
    }

    private function departmentForm(string $name, string $scope): FormInterface
    {
        $form = $this->formFactory->create(CrewDepartmentType::class, new CrewDepartment(), ['csrf_protection' => false]);
        $form->submit(['name' => $name, 'name_en' => '', 'scope' => $scope, 'sortOrder' => '10']);

        return $form;
    }

    private function positionForm(CrewPosition $position, string $name, ?CrewDepartment $department, string $sortOrder): FormInterface
    {
        $form = $this->formFactory->create(CrewPositionType::class, $position, ['csrf_protection' => false]);
        $form->submit([
            'name' => $name,
            'name_en' => '',
            'description' => '',
            'description_en' => '',
            'crewDepartment' => $department?->getId() ?? '',
            'sortOrder' => $sortOrder,
        ]);

        return $form;
    }

    private function createDepartment(string $name, string $scope): CrewDepartment
    {
        $department = (new CrewDepartment())->setName($name)->setScope($scope)->setSortOrder(9990);
        $this->entityManager->persist($department);
        $this->entityManager->flush();

        return $department;
    }

    private function createPosition(string $name, CrewDepartment $department): CrewPosition
    {
        $position = (new CrewPosition())->setName($name)->setCrewDepartment($department)->setSortOrder(9990);
        $this->entityManager->persist($position);
        $this->entityManager->flush();

        return $position;
    }

    private function department(string $scope, string $name): CrewDepartment
    {
        $department = $this->departmentRepository->findOneBy(['scope' => $scope, 'name' => $name]);
        self::assertInstanceOf(CrewDepartment::class, $department);

        return $department;
    }
}
