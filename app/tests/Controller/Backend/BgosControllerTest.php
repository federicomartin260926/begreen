<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\BgosController;
use App\Entity\BgosSubcategoryConfig;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Repository\BgosSubcategoryConfigRepository;
use App\Service\ActiveProjectService;
use App\Service\Bgos\BgosPeriodService;
use App\Service\Bgos\BgosPeriodWindowResolver;
use App\Service\Bgos\BgosSubcategoryCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class BgosControllerTest extends KernelTestCase
{
    public function testConfigShowsSevenModernCategoriesAndBaseSubcategoriesWithoutPersistingRows(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository, $catalog] = $this->context();

        self::assertSame(0, $repository->count(['project' => $project]));

        $response = $controller->config($activeProjectService, $repository, $catalog, $this->configRequest());
        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, substr_count($content, 'data-bgos-category='));
        self::assertSame(75, substr_count($content, 'data-bgos-subcategory='));
        foreach (['Transporte', 'Energía y tecnología digital', 'Agua', 'Alojamiento', 'Catering', 'Materiales y Productos', 'Residuos'] as $label) {
            self::assertStringContainsString($label, $content);
        }
        self::assertStringContainsString('Transporte de equipo y personas', $content);
        self::assertStringContainsString('Electricidad', $content);
        self::assertStringContainsString('Madera', $content);
        self::assertStringNotContainsString('Viajes', $content);
        self::assertStringNotContainsString('Añadir subcategoría', $content);
        self::assertStringNotContainsString('<code>', $content);
        self::assertMatchesRegularExpression(
            '/id="bgos-category-transport"\s+class="accordion-collapse collapse show"/',
            $content,
        );
        self::assertSame(0, $repository->count(['project' => $project]));

        $entityManager->clear();
    }

    public function testAgendaShowsSevenCategoriesAndTemporalViewsWithoutPersistingConfig(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository] = $this->context();

        $project->addPhaseDate(
            (new ProjectPhaseDate())
                ->setPhase('actividad')
                ->setStartDate(new \DateTimeImmutable('2026-09-10'))
                ->setEndDate(new \DateTimeImmutable('2026-09-20'))
        );
        $entityManager->flush();

        self::assertSame(0, $repository->count(['project' => $project]));

        $request = new Request([
            'view' => 'day',
            'date' => '2026-09-10',
        ], [], [
            '_route' => 'backend_bgos_index',
        ]);
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        $response = $controller->index(
            $activeProjectService,
            self::getContainer()->get(BgosPeriodWindowResolver::class),
            self::getContainer()->get(BgosPeriodService::class),
            $request,
        );

        $content = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, substr_count($content, 'data-bgos-agenda-category='));
        self::assertStringContainsString('Día', $content);
        self::assertStringContainsString('Semana', $content);
        self::assertStringContainsString('Mes', $content);
        self::assertStringContainsString('Total', $content);
        self::assertStringContainsString('Configurar seguimiento', $content);
        self::assertStringContainsString('Transporte', $content);
        self::assertStringContainsString('Energía y tecnología digital', $content);
        self::assertStringNotContainsString('Viajes', $content);
        self::assertSame(0, $repository->count(['project' => $project]));
    }

    public function testDayAgendaShowsAddRecordOnlyForConfiguredApplicableSubcategory(): void
    {
        [$controller, $entityManager, $project, $activeProjectService] = $this->context();

        $project->addPhaseDate(
            (new ProjectPhaseDate())
                ->setPhase('actividad')
                ->setStartDate(new \DateTimeImmutable('2026-09-10'))
                ->setEndDate(new \DateTimeImmutable('2026-09-20'))
        );
        $this->persistConfig(
            $entityManager,
            $project,
            'transport',
            'freight',
            'Transporte de materiales',
        )->setActivityFrequency(BgosSubcategoryConfig::FREQUENCY_DAILY);
        $entityManager->flush();

        $request = new Request([
            'view' => 'day',
            'date' => '2026-09-17',
        ], [], [
            '_route' => 'backend_bgos_index',
        ]);
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        $content = (string) $controller->index(
            $activeProjectService,
            self::getContainer()->get(BgosPeriodWindowResolver::class),
            self::getContainer()->get(BgosPeriodService::class),
            $request,
        )->getContent();

        self::assertSame(1, substr_count($content, 'Añadir registro'));
        self::assertStringContainsString(
            '/backend/emission/new-transport?bgosDate=2026-09-17&amp;bgosView=day&amp;bgosCategory=transport&amp;bgosSubcategory=freight',
            $content,
        );
    }

    public function testSaveCreatesThenUpdatesTheSameConfigWithAllFrequenciesAndActiveState(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository, $catalog] = $this->context();

        $createRequest = $this->saveRequest('energy', 'electricity', false, [
            BgosSubcategoryConfig::FREQUENCY_PUNCTUAL,
            BgosSubcategoryConfig::FREQUENCY_DAILY,
            BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE,
        ]);
        $createResponse = $controller->save(
            'energy',
            'electricity',
            $createRequest,
            $activeProjectService,
            $repository,
            $catalog,
            $entityManager,
        );

        self::assertStringEndsWith(
            '/backend/bgos/config?open=energy#bgos-energy-electricity',
            $createResponse->getTargetUrl(),
        );
        self::assertSame(
            ['backend.bgos.flash.saved'],
            $createRequest->getSession()->getFlashBag()->peek('success'),
        );
        self::assertSame(
            'Configuración guardada correctamente.',
            self::getContainer()->get('translator')->trans('backend.bgos.flash.saved', locale: 'es'),
        );

        $config = $repository->findOneBy([
            'project' => $project,
            'categoryKey' => 'energy',
            'subcategoryKey' => 'electricity',
        ]);
        self::assertInstanceOf(BgosSubcategoryConfig::class, $config);
        $id = $config->getId();
        self::assertSame('Electricidad', $config->getLabel());
        self::assertFalse($config->isActive());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_PUNCTUAL, $config->getPreproductionFrequency());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_DAILY, $config->getActivityFrequency());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE, $config->getPostproductionFrequency());

        $updateRequest = $this->saveRequest('energy', 'electricity', true, [
            BgosSubcategoryConfig::FREQUENCY_DAILY,
            BgosSubcategoryConfig::FREQUENCY_PUNCTUAL,
            BgosSubcategoryConfig::FREQUENCY_DAILY,
        ]);
        $updateResponse = $controller->save(
            'energy',
            'electricity',
            $updateRequest,
            $activeProjectService,
            $repository,
            $catalog,
            $entityManager,
        );

        self::assertSame($createResponse->getTargetUrl(), $updateResponse->getTargetUrl());
        self::assertSame(
            ['backend.bgos.flash.saved'],
            $updateRequest->getSession()->getFlashBag()->peek('success'),
        );

        self::assertSame(1, $repository->count([
            'project' => $project,
            'categoryKey' => 'energy',
            'subcategoryKey' => 'electricity',
        ]));
        self::assertSame($id, $config->getId());
        self::assertTrue($config->isActive());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_DAILY, $config->getPreproductionFrequency());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_PUNCTUAL, $config->getActivityFrequency());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_DAILY, $config->getPostproductionFrequency());
    }

    public function testConfigOpensRequestedCategoryAndProvidesStableRowAnchor(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository, $catalog] = $this->context();

        $content = (string) $controller->config(
            $activeProjectService,
            $repository,
            $catalog,
            $this->configRequest('waste'),
        )->getContent();

        self::assertMatchesRegularExpression(
            '/id="bgos-category-waste"\s+class="accordion-collapse collapse show"/',
            $content,
        );
        self::assertDoesNotMatchRegularExpression(
            '/id="bgos-category-transport"\s+class="accordion-collapse collapse show"/',
            $content,
        );
        self::assertStringContainsString('id="bgos-waste-aceites-usados"', $content);
    }

    public function testConfigDefaultsToTransportWhenOpenIsMissingOrInvalid(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository, $catalog] = $this->context();

        foreach ([null, 'invented'] as $open) {
            $content = (string) $controller->config(
                $activeProjectService,
                $repository,
                $catalog,
                $this->configRequest($open),
            )->getContent();

            self::assertMatchesRegularExpression(
                '/id="bgos-category-transport"\s+class="accordion-collapse collapse show"/',
                $content,
            );
            self::assertSame(1, substr_count($content, 'accordion-collapse collapse show'));
            self::assertStringNotContainsString('bgos-category-invented', $content);
        }
    }

    public function testSaveRejectsUnknownCategory(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository, $catalog] = $this->context();

        $this->expectException(NotFoundHttpException::class);
        $controller->save(
            'travel',
            'plane',
            $this->postRequest('backend_bgos_subcategory_save'),
            $activeProjectService,
            $repository,
            $catalog,
            $entityManager,
        );
    }

    public function testSaveRejectsUnknownSubcategory(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository, $catalog] = $this->context();

        $this->expectException(NotFoundHttpException::class);
        $controller->save(
            'energy',
            'invented',
            $this->postRequest('backend_bgos_subcategory_save'),
            $activeProjectService,
            $repository,
            $catalog,
            $entityManager,
        );
    }

    public function testSaveNeverModifiesAnotherProjectsConfig(): void
    {
        [$controller, $entityManager, $project, $activeProjectService, $repository, $catalog, $admin] = $this->context();
        $otherProject = $this->persistProject($entityManager, $admin, 'Otro proyecto BGoS');
        $otherConfig = $this->persistConfig($entityManager, $otherProject, 'energy', 'electricity', 'Etiqueta previa');

        $controller->save(
            'energy',
            'electricity',
            $this->saveRequest('energy', 'electricity', false, [
                BgosSubcategoryConfig::FREQUENCY_DAILY,
                BgosSubcategoryConfig::FREQUENCY_DAILY,
                BgosSubcategoryConfig::FREQUENCY_DAILY,
            ]),
            $activeProjectService,
            $repository,
            $catalog,
            $entityManager,
        );

        self::assertTrue($otherConfig->isActive());
        self::assertSame('Etiqueta previa', $otherConfig->getLabel());
        self::assertSame(1, $repository->count(['project' => $otherProject]));
        self::assertSame(1, $repository->count(['project' => $project]));
    }

    /** @return array{BgosController, EntityManagerInterface, Project, ActiveProjectService, BgosSubcategoryConfigRepository, BgosSubcategoryCatalog, User} */
    private function context(): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $admin = (new User())
            ->setName('Admin')
            ->setSurnames('BGoS')
            ->setEmail(sprintf('admin.bgos.%s@example.test', uniqid()))
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $entityManager->persist($admin);
        $project = $this->persistProject($entityManager, $admin, 'Proyecto BGoS');

        $container->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles())
        );

        $request = new Request();
        $request->attributes->set('_route', 'backend_bgos_index');
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get('twig')->addGlobal('userProjects', [$project]);
        $container->get('twig')->addGlobal('activeProject', $project);
        $container->get('twig')->addGlobal('is_admin', true);

        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->method('getActiveProject')->willReturn($project);

        $controller = new BgosController();
        $controller->setContainer($container);

        return [
            $controller,
            $entityManager,
            $project,
            $activeProjectService,
            $container->get(BgosSubcategoryConfigRepository::class),
            $container->get(BgosSubcategoryCatalog::class),
            $admin,
        ];
    }

    private function persistProject(EntityManagerInterface $entityManager, User $owner, string $name): Project
    {
        $project = (new Project())
            ->setName($name.' '.uniqid())
            ->setType('rodaje')
            ->setCountry('ES')
            ->setUser($owner);
        foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $phase) {
            $project->addSubscription(
                (new ProjectSubscription())
                    ->setPhase($phase)
                    ->setTier(ProjectSubscription::TIER_BASIC)
                    ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                    ->setSource(ProjectSubscription::SOURCE_SYSTEM)
            );
        }
        $entityManager->persist($project);
        $entityManager->flush();

        return $project;
    }

    private function persistConfig(
        EntityManagerInterface $entityManager,
        Project $project,
        string $categoryKey,
        string $subcategoryKey,
        string $label,
    ): BgosSubcategoryConfig {
        $config = (new BgosSubcategoryConfig())
            ->setProject($project)
            ->setCategoryKey($categoryKey)
            ->setSubcategoryKey($subcategoryKey)
            ->setLabel($label)
            ->setActive(true);
        $entityManager->persist($config);
        $entityManager->flush();

        return $config;
    }

    /** @param array{0: string, 1: string, 2: string} $frequencies */
    private function saveRequest(
        string $categoryKey,
        string $subcategoryKey,
        bool $active,
        array $frequencies,
    ): Request {
        $request = $this->postRequest('backend_bgos_subcategory_save');
        $tokenId = sprintf('bgos_subcategory_save_%s_%s', $categoryKey, $subcategoryKey);
        $request->request->add([
            '_token' => self::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue(),
            'label' => 'Browser override',
            'active' => $active ? '1' : '0',
            'preproductionFrequency' => $frequencies[0],
            'activityFrequency' => $frequencies[1],
            'postproductionFrequency' => $frequencies[2],
        ]);

        return $request;
    }

    private function postRequest(string $route): Request
    {
        $request = new Request([], [], ['_route' => $route]);
        $request->setMethod(Request::METHOD_POST);
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function configRequest(?string $open = null): Request
    {
        $request = new Request(null === $open ? [] : ['open' => $open], [], [
            '_route' => 'backend_bgos_config',
        ]);
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }
}
