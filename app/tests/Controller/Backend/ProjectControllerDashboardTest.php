<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectController;
use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Entity\User;
use App\Repository\EmissionRecordRepository;
use App\Repository\PlanRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectBillingDocumentRepository;
use App\Service\ActiveProjectService;
use App\Service\ProjectFeatureGate;
use App\Service\ProjectCompanyLogoStorage;
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
        $targetProject = $this->createProject($admin, 'Proyecto incompleto dashboard')
            ->setFilmingType('feature')
            ->setFilmingGenre('ficcion');
        $activityProject = $this->createProject($admin, 'Proyecto incompleto con actividad dashboard')
            ->setFilmingType('tv_series')
            ->setFilmingGenre('documental')
            ->setDistributionMedia(['tv'])
            ->setEpisodios(6)
            ->setDuracionEpisodio(50);
        $completedProject = $this->createProject($admin, 'Proyecto completo dashboard')
            ->setType('evento')
            ->setEventTypePrimary('corporativo')
            ->setEventModality('presencial')
            ->setEventAttendeesCount(100);
        $entityManager->persist(
            (new Plan())
                ->setProject($targetProject)
                ->setUser($admin)
                ->setStatus('incompleto')
        );
        $entityManager->persist(
            (new Plan())
                ->setProject($completedProject)
                ->setUser($admin)
                ->setStatus('completo')
        );
        $activityMeasure = (new Measure())->setName('Medida con actividad');
        $activityPlan = (new Plan())
            ->setProject($activityProject)
            ->setUser($admin)
            ->setStatus('incompleto');
        $activityPlan->addPlanMeasure(
            (new PlanMeasure())
                ->setMeasure($activityMeasure)
                ->setActionTaken('Acción ya registrada')
        );
        $entityManager->persist($activityMeasure);
        $entityManager->persist($activityPlan);

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
            $invoiceStorage,
            $container->get(\App\Service\SustainabilityPlanCollaborationService::class),
            $container->get(ProjectCompanyLogoStorage::class),
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
        $activityActionCell = $this->extractActionCell($content, $activityProject->getName());
        $completedActionCell = $this->extractActionCell($content, $completedProject->getName());

        self::assertStringContainsString('Editar', $actionCell);
        self::assertStringContainsString('Equipo', $actionCell);
        self::assertStringContainsString('Duplicar', $actionCell);
        self::assertStringNotContainsString('Abrir', $actionCell);
        self::assertStringContainsString('Eliminar plan', $actionCell);
        self::assertStringContainsString('name="_token"', $actionCell);
        self::assertStringNotContainsString('Eliminar plan', $activityActionCell);
        self::assertStringNotContainsString('Eliminar plan', $completedActionCell);
        self::assertStringContainsString('Largometraje Ficción', $content);
        self::assertStringContainsString('Serie Documental', $content);
        self::assertStringContainsString('Evento corporativo', $content);
    }

    public function testDashboardQueryDoesNotDuplicateProjectsWithMultipleSubscriptions(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $admin = (new User())
            ->setName('Admin')
            ->setSurnames('Dashboard')
            ->setEmail(sprintf('admin.dashboard.query.%s@example.test', uniqid()))
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $entityManager->persist($admin);

        $project = $this->createProject($admin, 'Proyecto con dos suscripciones');
        $project->getSubscriptionForPhase(CommercialPhase::IMPLEMENTATION)?->setTier(ProjectSubscription::TIER_STANDARD);
        $entityManager->flush();

        $projects = $container->get(ProjectRepository::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.projectMemberships', 'pm')
            ->leftJoin('pm.user', 'mu')
            ->leftJoin('p.user', 'cu')
            ->leftJoin('p.subscriptions', 'sub')
            ->addSelect('pm', 'mu', 'cu', 'sub')
            ->select('DISTINCT p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        $occurrences = array_filter(
            $projects,
            static fn (Project $candidate): bool => $candidate->getId() === $project->getId()
        );

        self::assertCount(1, $occurrences);
    }

    private function createProject(User $owner, string $name): Project
    {
        $project = (new Project())
            ->setName($name)
            ->setType('rodaje')
            ->setCountry('ES')
            ->setUser($owner);

        foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $phase) {
            $subscription = (new ProjectSubscription())
                ->setPhase($phase)
                ->setTier(ProjectSubscription::TIER_BASIC)
                ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                ->setSource(ProjectSubscription::SOURCE_SYSTEM);

            $project->addSubscription($subscription);
        }

        $membership = (new ProjectMembership())
            ->setUser($owner)
            ->setProject($project)
            ->setProjectRole('owner');
        $project->addProjectMembership($membership);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($project);
        $entityManager->persist($membership);

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
        $projectPos = strrpos($content, $projectName);
        self::assertNotFalse($projectPos, sprintf('No se encontró el proyecto "%s" en el HTML.', $projectName));

        $articleStart = strrpos(substr($content, 0, $projectPos), '<article');
        self::assertNotFalse($articleStart, 'No se encontró la tarjeta del proyecto.');

        $actionsStart = strpos($content, '<div class="dropdown backend-project-pill__actions">', $articleStart);
        self::assertNotFalse($actionsStart, 'No se encontró el menú de acciones del proyecto.');

        $actionsEnd = strpos($content, '</ul>', $actionsStart);
        self::assertNotFalse($actionsEnd, 'No se pudo delimitar el menú de acciones del proyecto.');

        return substr($content, $actionsStart, $actionsEnd - $actionsStart);
    }
}
