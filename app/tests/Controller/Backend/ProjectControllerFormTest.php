<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectController;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Entity\User;
use App\Repository\ProjectBillingDocumentRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\ProjectFeatureGate;
use App\Service\StripeInvoiceStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ProjectControllerFormTest extends KernelTestCase
{
    public function testNewFormHidesCommercialTierAndBillingUi(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);

        $controller = $this->createController();
        $request = $this->createRequest('backend_project_new');

        $response = $controller->new(
            $request,
            $entityManager,
            $this->createMock(ActiveProjectService::class)
        );

        $content = (string) $response->getContent();

        self::assertStringContainsString('Datos básicos', $content);
        self::assertStringNotContainsString('Tier comercial', $content);
        self::assertStringNotContainsString('Facturación del proyecto', $content);
        self::assertStringNotContainsString('Gestionar facturación', $content);
        self::assertStringNotContainsString('Tier actual', $content);
    }

    public function testCreateRedirectsToConfirmationPage(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);

        $controller = $this->createController();
        $request = $this->createCreateRequest();
        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->expects(self::once())
            ->method('setActiveProject')
            ->with(self::callback(static fn(Project $project) => $project->getId() !== null));

        $response = $controller->new($request, $entityManager, $activeProjectService);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/project/', $response->getTargetUrl());
        self::assertStringContainsString('/created', $response->getTargetUrl());

        $project = $entityManager->getRepository(Project::class)->findOneBy(['name' => 'Proyecto wizard test']);
        self::assertInstanceOf(Project::class, $project);
        self::assertSame(ProjectSubscription::TIER_BASIC, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getStatus());
        self::assertSame(ProjectSubscription::TIER_BASIC, $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->getTier());
        self::assertSame(ProjectSubscription::STATUS_ACTIVE, $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->getStatus());
    }

    public function testEditFormShowsCurrentPlanOutsideWizard(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $project = $this->createProject($entityManager, $admin, 'Proyecto edición test');
        $this->setAdminToken($admin);

        $controller = $this->createController();
        $request = $this->createRequest('backend_project_edit', ['id' => $project->getId()]);

        $response = $controller->edit($project, $request, $entityManager);
        $content = (string) $response->getContent();

        self::assertStringContainsString('Plan actual', $content);
        self::assertStringContainsString('Mejorar plan', $content);
        self::assertStringNotContainsString('Tier comercial', $content);
        self::assertStringNotContainsString('Facturación del proyecto', $content);
        self::assertStringNotContainsString('Gestionar facturación', $content);
    }

    public function testEditDoesNotOverwriteImplementationSubscription(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $project = $this->createProject($entityManager, $admin, 'Proyecto edición implementación');
        $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->setTier(ProjectSubscription::TIER_STANDARD);
        $entityManager->flush();
        $this->setAdminToken($admin);

        $controller = $this->createController();
        $request = $this->createEditRequest($project);

        $response = $controller->edit($project, $request, $entityManager);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(ProjectSubscription::TIER_BASIC, $project->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertSame(ProjectSubscription::TIER_STANDARD, $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->getTier());
    }

    public function testCloneCreatesBothBasicSubscriptions(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $project = $this->createProject($entityManager, $admin, 'Proyecto clon test');
        $this->setAdminToken($admin);

        $controller = $this->createController();
        $request = $this->createRequest('backend_project_clone', ['id' => $project->getId()]);

        $response = $controller->clone($project, $entityManager);

        self::assertSame(302, $response->getStatusCode());

        $clonedProject = $entityManager->getRepository(Project::class)->findOneBy(['name' => 'Proyecto clon test (copia)']);
        self::assertInstanceOf(Project::class, $clonedProject);
        self::assertSame(ProjectSubscription::TIER_BASIC, $clonedProject->getSubscriptionForPhase(CommercialPhase::ELABORATION)?->getTier());
        self::assertSame(ProjectSubscription::TIER_BASIC, $clonedProject->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->getTier());
    }

    public function testCreatedPageShowsBasicPlanAndUpgradeCta(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $project = $this->createProject($entityManager, $admin, 'Proyecto post creación');
        $this->setAdminToken($admin);
        $this->createRequest('backend_project_created', ['id' => $project->getId()]);

        $controller = $this->createController();
        $projectRepository = $entityManager->getRepository(Project::class);

        $response = $controller->created($project->getId(), $projectRepository, $this->createMock(ActiveProjectService::class));
        $content = (string) $response->getContent();

        self::assertStringContainsString('Proyecto creado correctamente', $content);
        self::assertStringContainsString('Plan de Elaboración', $content);
        self::assertStringContainsString('Basic', $content);
        self::assertStringContainsString('Mejorar plan', $content);
        self::assertStringContainsString('Continuar con plan Basic', $content);
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing', $content);
    }

    public function testCreatedRedirectsWhenProjectDoesNotExist(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);
        $request = $this->createRequest('backend_project_created', ['id' => 552]);

        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->expects(self::once())
            ->method('find')
            ->with(552)
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->created(552, $projectRepository, $this->createMock(ActiveProjectService::class));

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/project/', $response->getTargetUrl());
        self::assertStringNotContainsString('/created', $response->getTargetUrl());
        self::assertSame(['backend.projects.flash.project_not_found'], $request->getSession()->getFlashBag()->peek('warning'));
    }

    public function testSelectProjectRedirectsWhenProjectDoesNotExist(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);
        $request = $this->createRequest('backend_project_select_project', ['id' => 552]);

        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->expects(self::once())
            ->method('find')
            ->with(552)
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->selectProject(552, $projectRepository, $this->createMock(ActiveProjectService::class), $request);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/project/', $response->getTargetUrl());
        self::assertSame(['backend.projects.flash.project_not_found'], $request->getSession()->getFlashBag()->peek('warning'));
    }

    public function testProjectSelectorRendersNumericActionWithoutPlaceholder(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);
        $request = $this->createRequest('backend_project_index');

        $projectOne = (new Project())->setName('Proyecto uno');
        $projectTwo = (new Project())->setName('Proyecto dos');
        $this->setEntityId($projectOne, 11);
        $this->setEntityId($projectTwo, 22);

        $twig = $container->get('twig');
        $twig->addGlobal('userProjects', [$projectOne, $projectTwo]);
        $twig->addGlobal('activeProject', $projectOne);

        $html = $twig->render('backend/_project_selector.html.twig', [
            'variant' => 'header',
            'userProjects' => [$projectOne, $projectTwo],
            'activeProject' => $projectOne,
        ]);

        self::assertStringNotContainsString('PROJECT_ID', $html);
        self::assertStringContainsString('/backend/project/select-project/11', $html);
        self::assertStringContainsString('data-action-base="/backend/project/select-project/"', $html);
        self::assertStringContainsString('value="22"', $html);
    }

    private function createController(): ProjectController
    {
        $container = self::getContainer();

        $controller = new ProjectController(
            $container->get('translator'),
            $container->get(ProjectFeatureGate::class),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(StripeInvoiceStorageService::class),
        );
        $controller->setContainer($container);

        return $controller;
    }

    private function createRequest(string $route, array $attributes = []): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_params', $attributes);
        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('_security_main', 'mock');

        self::getContainer()->get('request_stack')->push($request);
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', []);
        $twig->addGlobal('activeProject', null);

        return $request;
    }

    private function createCreateRequest(): Request
    {
        $request = $this->createRequest('backend_project_new');
        $request->setMethod(Request::METHOD_POST);
        $request->request->add([
            'project' => [
                '_token' => self::getContainer()->get('security.csrf.token_manager')->getToken('project')->getValue(),
                'name' => 'Proyecto wizard test',
                'country' => 'ES',
                'type' => 'rodaje',
                'emissionSourceName' => 'MITECO',
                'filmingType' => 'feature',
                'filmingGenre' => '',
                'distributionMedia' => ['cinema'],
                'eventTypePrimary' => '',
                'eventModality' => '',
                'eventAttendeesCount' => '',
                'eventOnlineConnections' => '',
                'mainLocation' => '',
                'presupuesto' => '',
                'ecoManagerStatus' => '',
                'projectCompanies' => [],
                'projectFundingSources' => [],
                'phaseDates' => [
                    ['phase' => 'preproduccion', 'startDate' => '2026-07-01', 'endDate' => '2026-07-02'],
                    ['phase' => 'actividad', 'startDate' => '2026-07-03', 'endDate' => '2026-07-04'],
                    ['phase' => 'postproduccion', 'startDate' => '2026-07-05', 'endDate' => '2026-07-06'],
                ],
            ],
        ]);

        return $request;
    }

    private function createEditRequest(Project $project): Request
    {
        $request = $this->createRequest('backend_project_edit', ['id' => $project->getId()]);
        $request->setMethod(Request::METHOD_POST);
        $request->request->add([
            'project' => [
                '_token' => self::getContainer()->get('security.csrf.token_manager')->getToken('project')->getValue(),
                'name' => $project->getName(),
                'country' => 'ES',
                'type' => 'rodaje',
                'emissionSourceName' => 'MITECO',
                'filmingType' => 'feature',
                'filmingGenre' => '',
                'distributionMedia' => ['cinema'],
                'eventTypePrimary' => '',
                'eventModality' => '',
                'eventAttendeesCount' => '',
                'eventOnlineConnections' => '',
                'mainLocation' => '',
                'presupuesto' => '',
                'ecoManagerStatus' => '',
                'projectCompanies' => [],
                'projectFundingSources' => [],
                'phaseDates' => [],
            ],
        ]);

        return $request;
    }

    private function createAdminUser(EntityManagerInterface $entityManager): User
    {
        $admin = (new User())
            ->setName('Admin')
            ->setSurnames('Wizard')
            ->setEmail(sprintf('admin.wizard.%s@example.test', uniqid()))
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);

        $entityManager->persist($admin);
        $entityManager->flush();

        return $admin;
    }

    private function createProject(EntityManagerInterface $entityManager, User $owner, string $name): Project
    {
        $project = (new Project())
            ->setName($name)
            ->setType('rodaje')
            ->setCountry('ES')
            ->setEmissionSourceName('MITECO')
            ->setFilmingType('feature')
            ->setDistributionMedia(['cinema'])
            ->setUser($owner);

        foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $phase) {
            $subscription = (new ProjectSubscription())
                ->setPhase($phase)
                ->setTier(ProjectSubscription::TIER_BASIC)
                ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                ->setSource(ProjectSubscription::SOURCE_SYSTEM);

            $project->addSubscription($subscription);
        }

        $entityManager->persist($project);
        $entityManager->flush();

        return $project;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        if (!$reflection->hasProperty('id')) {
            return;
        }

        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function setAdminToken(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );
    }
}
