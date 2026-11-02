<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\HomeController;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMembershipRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class HomeControllerTest extends KernelTestCase
{
    #[DataProvider('globalRoles')]
    public function testAdminWithoutMembershipsRedirectsToProjectDashboard(string $role): void
    {
        $controller = $this->controllerAs($role);
        $repository = $this->createMock(ProjectMembershipRepository::class);
        $repository->expects(self::never())->method('projectsOf');

        $response = $controller($repository);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/backend/project/', parse_url($response->getTargetUrl(), PHP_URL_PATH));
    }

    /** @return iterable<string, array{string}> */
    public static function globalRoles(): iterable
    {
        yield 'Administrador' => ['ROLE_ADMIN'];
        yield 'Super Administrador' => ['ROLE_SUPER_ADMIN'];
    }

    public function testEditorWithMembershipKeepsProjectDashboardRedirect(): void
    {
        $controller = $this->controllerAs('ROLE_USER');
        $repository = $this->createMock(ProjectMembershipRepository::class);
        $repository->expects(self::once())->method('projectsOf')->willReturn([new Project()]);

        $response = $controller($repository);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/backend/project/', parse_url($response->getTargetUrl(), PHP_URL_PATH));
    }

    private function controllerAs(string $role): HomeController
    {
        self::bootKernel();
        $container = self::getContainer();
        $user = (new User())
            ->setName('Dashboard')
            ->setSurnames('QA')
            ->setEmail('dashboard.'.strtolower($role).'@example.test')
            ->setPassword('not-used')
            ->setRoles([$role])
            ->setIsVerified(true);

        $storage = $container->get('security.token_storage');
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $controller = new HomeController();
        $controller->setContainer($container);
        return $controller;
    }

    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            self::getContainer()->get('security.token_storage')->setToken(null);
        }
        parent::tearDown();
    }
}
