<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectController;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Entity\User;
use App\Repository\ProjectBillingDocumentRepository;
use App\Repository\ProjectRepository;
use App\Service\ActiveProjectService;
use App\Service\ProjectFeatureGate;
use App\Service\ProjectCompanyLogoStorage;
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
        self::assertStringContainsString('data-project-wizard-edit-mode-value="false"', $content);
        self::assertStringNotContainsString('Tier actual', $content);
        self::assertMatchesRegularExpression('/<select[^>]*id="project_country"[^>]*>[\s\S]*?<option value="" selected="selected">Selecciona un país<\/option>/', $content);
        self::assertMatchesRegularExpression('/<select[^>]*id="project_type"[^>]*>[\s\S]*?<option value="" selected="selected">Selecciona un tipo de proyecto<\/option>/', $content);
        self::assertNull((new Project())->getCountry());
        self::assertNull((new Project())->getType());
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

    public function testEventProjectCanBeCreatedWithConditionalFieldsAndAutomaticEmissionSource(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);

        $controller = $this->createController();
        $request = $this->createEventCreateRequest();

        $response = $controller->new(
            $request,
            $entityManager,
            $this->createMock(ActiveProjectService::class)
        );

        self::assertSame(302, $response->getStatusCode());

        $project = $entityManager->getRepository(Project::class)->findOneBy([
            'name' => 'Evento wizard test',
        ]);
        self::assertInstanceOf(Project::class, $project);
        self::assertSame('evento', $project->getType());
        self::assertSame('corporativo', $project->getEventTypePrimary());
        self::assertSame('hibrido', $project->getEventModality());
        self::assertSame(120, $project->getEventAttendeesCount());
        self::assertSame(80, $project->getEventOnlineConnections());
        self::assertSame(Project::DEFAULT_EMISSION_SOURCE_NAME, $project->getEmissionSourceName());
    }

    public function testDateStepUsesContextualActivityLabelForFilmingAndEvent(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $filmingProject = $this->createProject($entityManager, $admin, 'Rodaje etiqueta fechas');
        $eventProject = $this->createProject($entityManager, $admin, 'Evento etiqueta fechas')
            ->setType('evento')
            ->setFilmingType(null)
            ->setDistributionMedia([])
            ->setEventTypePrimary('corporativo')
            ->setEventModality('presencial')
            ->setEventAttendeesCount(50);
        $this->addPhaseDates($filmingProject);
        $this->addPhaseDates($eventProject);
        $entityManager->flush();
        $this->setAdminToken($admin);

        $controller = $this->createController();
        $filmingResponse = $controller->edit(
            $filmingProject,
            $this->createRequest('backend_project_edit', ['id' => $filmingProject->getId()]),
            $entityManager
        );
        $eventResponse = $controller->edit(
            $eventProject,
            $this->createRequest('backend_project_edit', ['id' => $eventProject->getId()]),
            $entityManager
        );

        $filmingContent = (string) $filmingResponse->getContent();
        $eventContent = (string) $eventResponse->getContent();

        self::assertMatchesRegularExpression('/data-phase="actividad"\s+value="Rodaje"/', $filmingContent);
        self::assertMatchesRegularExpression('/data-phase="actividad"\s+value="Actividad"/', $eventContent);
        self::assertStringContainsString('data-project-label-activity-filming-value="Rodaje"', $filmingContent);
        self::assertStringContainsString('data-project-label-activity-event-value="Actividad"', $filmingContent);
    }

    public function testWizardStepThreeRendersPlanningInSpanishAndEnglish(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);
        $controller = $this->createController();
        $translator = $container->get('translator');

        $translator->setLocale('es');
        $spanishResponse = $controller->new(
            $this->createRequest('backend_project_new'),
            $entityManager,
            $this->createMock(ActiveProjectService::class)
        );
        $translator->setLocale('en');
        $englishResponse = $controller->new(
            $this->createRequest('backend_project_new', [], 'en'),
            $entityManager,
            $this->createMock(ActiveProjectService::class)
        );

        self::assertSame(2, substr_count((string) $spanishResponse->getContent(), 'Planificación'));
        self::assertSame(2, substr_count((string) $englishResponse->getContent(), 'Planning'));
    }

    public function testEditFormShowsCurrentPlanOutsideWizard(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $project = $this->createProject($entityManager, $admin, 'Proyecto edición test')
            ->setCountry('FR');
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
        self::assertStringContainsString('data-project-wizard-edit-mode-value="true"', $content);
        self::assertMatchesRegularExpression('/<option value="FR" selected="selected">Francia<\/option>/', $content);
        self::assertMatchesRegularExpression('/<option value="rodaje" selected="selected">Rodaje<\/option>/', $content);
    }

    public function testCreateRejectsMissingCountryAndProjectTypeWithTranslatedErrors(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = $this->createAdminUser($entityManager);
        $this->setAdminToken($admin);
        $request = $this->createRequest('backend_project_new');
        $request->setMethod(Request::METHOD_POST);
        $request->request->set('project', [
            '_token' => $container->get('security.csrf.token_manager')->getToken('project')->getValue(),
            'name' => 'Proyecto sin clasificación',
            'country' => '',
            'type' => '',
        ]);

        $response = $this->createController()->new(
            $request,
            $entityManager,
            $this->createMock(ActiveProjectService::class)
        );
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Debes seleccionar un país.', $content);
        self::assertStringContainsString('Debes seleccionar un tipo de proyecto.', $content);
        self::assertNull($entityManager->getRepository(Project::class)->findOneBy([
            'name' => 'Proyecto sin clasificación',
        ]));
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
        self::assertStringContainsString('/backend/project/' . $project->getId() . '/billing/elaboration', $content);
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
            $container->get(\App\Service\SustainabilityPlanCollaborationService::class),
            $container->get(ProjectCompanyLogoStorage::class),
        );
        $controller->setContainer($container);

        return $controller;
    }

    private function createRequest(string $route, array $attributes = [], string $locale = 'es'): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_params', $attributes);
        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }
        $request->setLocale($locale);
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

    private function createEventCreateRequest(): Request
    {
        $request = $this->createRequest('backend_project_new');
        $request->setMethod(Request::METHOD_POST);
        $request->request->add([
            'project' => [
                '_token' => self::getContainer()->get('security.csrf.token_manager')->getToken('project')->getValue(),
                'name' => 'Evento wizard test',
                'country' => 'ES',
                'type' => 'evento',
                'filmingType' => '',
                'filmingGenre' => '',
                'distributionMedia' => [],
                'eventTypePrimary' => 'corporativo',
                'eventModality' => 'hibrido',
                'eventAttendeesCount' => '120',
                'eventOnlineConnections' => '80',
                'mainLocation' => '',
                'presupuesto' => '',
                'ecoManagerStatus' => '',
                'projectCompanies' => [],
                'projectFundingSources' => [],
                'phaseDates' => [
                    ['phase' => 'preproduccion', 'startDate' => '2026-08-01', 'endDate' => '2026-08-02'],
                    ['phase' => 'actividad', 'startDate' => '2026-08-03', 'endDate' => '2026-08-04'],
                    ['phase' => 'postproduccion', 'startDate' => '2026-08-05', 'endDate' => '2026-08-06'],
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

    private function addPhaseDates(Project $project): void
    {
        $timezone = new \DateTimeZone('Europe/Madrid');
        foreach ([
            ['preproduccion', '2026-09-01', '2026-09-02'],
            ['actividad', '2026-09-03', '2026-09-04'],
            ['postproduccion', '2026-09-05', '2026-09-06'],
        ] as [$phase, $startDate, $endDate]) {
            $project->addPhaseDate(
                (new ProjectPhaseDate())
                    ->setPhase($phase)
                    ->setStartDate(new \DateTimeImmutable($startDate, $timezone))
                    ->setEndDate(new \DateTimeImmutable($endDate, $timezone))
            );
        }
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
