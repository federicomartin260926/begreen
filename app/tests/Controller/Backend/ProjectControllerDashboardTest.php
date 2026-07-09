<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectController;
use App\Entity\Project;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Repository\EmissionRecordRepository;
use App\Repository\PlanRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectBillingDocumentRepository;
use App\Service\ActiveProjectService;
use App\Service\ProjectFeatureGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ProjectControllerDashboardTest extends KernelTestCase
{
    public function testDashboardActionsDropdownOnlyShowsExpectedActions(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $admin = (new User())
            ->setName('Admin')
            ->setSurnames('Dashboard')
            ->setEmail(sprintf('admin.dashboard.%s@example.test', uniqid()))
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $entityManager->persist($admin);

        $activeProject = $this->createProject($admin, 'Proyecto activo dashboard');
        $targetProject = $this->createProject($admin, 'Proyecto objetivo dashboard');

        $entityManager->flush();

        $this->setAdminToken($admin);

        $request = new Request();
        $request->attributes->set('_route', 'backend_project_index');
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('active_project_id', $activeProject->getId());
        $container->get('request_stack')->push($request);

        $twig = $container->get('twig');
        $twig->addGlobal('userProjects', [$activeProject]);
        $twig->addGlobal('activeProject', $activeProject);
        $twig->addGlobal('is_admin', true);

        $billingRepository = $this->createMock(ProjectBillingDocumentRepository::class);
        $billingRepository->expects(self::any())
            ->method('count')
            ->willReturn(0);

        $invoiceStorage = $this->createMock(\App\Service\StripeInvoiceStorageService::class);

        $controller = new ProjectController(
            $container->get('translator'),
            $container->get(ProjectFeatureGate::class),
            $billingRepository,
            $invoiceStorage
        );
        $controller->setContainer($container);

        $response = $controller->index(
            $container->get(ProjectRepository::class),
            $container->get(PlanRepository::class),
            $container->get(EmissionRecordRepository::class),
            $container->get(ActiveProjectService::class),
            $request
        );

        $content = (string) $response->getContent();
        $actionCell = $this->extractActionCell($content, $targetProject->getName());

        self::assertStringContainsString('Editar', $actionCell);
        self::assertStringContainsString('Equipo', $actionCell);
        self::assertStringContainsString('Facturación', $actionCell);
        self::assertStringContainsString('Duplicar', $actionCell);
        self::assertStringContainsString('Seleccionar activo', $actionCell);
        self::assertStringNotContainsString('Abrir', $actionCell);
        self::assertStringNotContainsString('Plan', $actionCell);
        self::assertStringNotContainsString('Eliminar', $actionCell);
    }

    private function createProject(User $owner, string $name): Project
    {
        $project = (new Project())
            ->setName($name)
            ->setType('rodaje')
            ->setCountry('ES')
            ->setUser($owner);

        $subscription = (new ProjectSubscription())
            ->setTier(ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_SYSTEM);

        $project->setSubscription($subscription);

        self::getContainer()->get(EntityManagerInterface::class)->persist($project);

        return $project;
    }

    private function setAdminToken(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );
    }

    private function extractActionCell(string $content, string $projectName): string
    {
        $projectPos = strpos($content, $projectName);
        self::assertNotFalse($projectPos, sprintf('No se encontró el proyecto "%s" en el HTML.', $projectName));

        $cellStart = strpos($content, '<td class="text-center dashboard-actions-cell">', $projectPos);
        self::assertNotFalse($cellStart, 'No se encontró la celda de acciones del proyecto.');

        $cellEnd = strpos($content, '</td>', $cellStart);
        self::assertNotFalse($cellEnd, 'No se pudo delimitar la celda de acciones del proyecto.');

        return substr($content, $cellStart, $cellEnd - $cellStart);
    }
}
