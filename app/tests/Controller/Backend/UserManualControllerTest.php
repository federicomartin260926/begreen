<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\UserManualController;
use App\Entity\User;
use App\Service\UserManualProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class UserManualControllerTest extends KernelTestCase
{
    private TokenStorageInterface $tokenStorage;
    private UserManualController $controller;
    private UserManualProvider $manualProvider;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->tokenStorage = $container->get('security.token_storage');
        $this->manualProvider = $container->get(UserManualProvider::class);
        $this->logger = $container->get(LoggerInterface::class);
        $this->controller = new UserManualController();
        $this->controller->setContainer($container);
    }

    protected function tearDown(): void
    {
        $this->tokenStorage->setToken(null);
        parent::tearDown();
    }

    public function testAnonymousUserCannotDownloadTheManual(): void
    {
        $this->tokenStorage->setToken(null);

        $this->expectException(AccessDeniedException::class);
        $this->controller->download($this->manualProvider, $this->logger);
    }

    #[DataProvider('allowedRoles')]
    public function testAllowedRoleDownloadsItsOwnPdf(string $role, string $expectedStoredName, string $expectedDownloadName): void
    {
        $this->authenticateAs($role);

        $response = $this->controller->download($this->manualProvider, $this->logger);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('application/pdf', $response->headers->get('content-type'));
        self::assertSame('nosniff', $response->headers->get('x-content-type-options'));
        self::assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        self::assertStringContainsString($expectedDownloadName, (string) $response->headers->get('content-disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        self::assertSame($expectedStoredName, $response->getFile()->getFilename());
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($response->getFile()->getPathname(), false, null, 0, 5));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function allowedRoles(): iterable
    {
        yield 'Administrador' => ['ROLE_ADMIN', 'manual-administrador.pdf', 'manual-de-usuario-administrador.pdf'];
        yield 'Super Administrador' => ['ROLE_SUPER_ADMIN', 'manual-super-administrador.pdf', 'manual-de-usuario-super-administrador.pdf'];
    }

    public function testSuperAdministratorManualTakesPrecedenceOverInheritedAdministratorRole(): void
    {
        $this->authenticateAs('ROLE_SUPER_ADMIN');

        $manual = $this->manualProvider->resolveForCurrentUser();

        self::assertNotNull($manual);
        self::assertSame('manual-super-administrador.pdf', basename($manual->path));
        self::assertSame('Super Administrador', $manual->profile);
    }

    public function testMissingSuperAdministratorManualDoesNotFallBackToAdministratorManual(): void
    {
        $directory = sys_get_temp_dir().'/begreen-manual-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        copy(dirname(__DIR__, 3).'/resources/manuales/manual-administrador.pdf', $directory.'/manual-administrador.pdf');
        try {
            $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
            $authorizationChecker->method('isGranted')->willReturnCallback(
                static fn (mixed $attribute): bool => in_array($attribute, ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], true),
            );
            $provider = new UserManualProvider($authorizationChecker, $directory);

            self::assertNull($provider->resolveForCurrentUser());
            self::assertFalse($provider->isAvailableForCurrentUser());
        } finally {
            unlink($directory.'/manual-administrador.pdf');
            rmdir($directory);
        }
    }

    public function testMissingAllowedManualReturnsControlledNotFoundError(): void
    {
        $this->authenticateAs('ROLE_ADMIN');
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => 'ROLE_ADMIN' === $attribute,
        );
        $provider = new UserManualProvider(
            $authorizationChecker,
            sys_get_temp_dir().'/begreen-manual-directory-that-does-not-exist',
        );

        $this->expectException(NotFoundHttpException::class);
        $this->controller->download($provider, $this->logger);
    }

    #[DataProvider('allowedRoles')]
    public function testResolvedPathIsFixedInsideConfiguredManualDirectory(
        string $role,
        string $expectedStoredName,
        string $expectedDownloadName,
    ): void {
        $this->authenticateAs($role);

        $manual = $this->manualProvider->resolveForCurrentUser();

        self::assertNotNull($manual);
        self::assertSame($expectedStoredName, basename($manual->path));
        self::assertSame(realpath(dirname(__DIR__, 3).'/resources/manuales'), realpath(dirname($manual->path)));
        self::assertSame($expectedDownloadName, $manual->downloadName);
    }

    #[DataProvider('deniedRoles')]
    public function testOtherAuthenticatedRolesCannotDownloadTheAdministratorPdf(string $role): void
    {
        $this->authenticateAs($role);

        $this->expectException(AccessDeniedException::class);
        $this->controller->download($this->manualProvider, $this->logger);
    }

    /** @return iterable<string, array{string}> */
    public static function deniedRoles(): iterable
    {
        yield 'Gestor' => ['ROLE_MANAGER'];
        yield 'Editor' => ['ROLE_USER'];
        yield 'Verificador' => ['ROLE_VERIFIER'];
    }

    private function authenticateAs(string $role): void
    {
        $user = (new User())
            ->setName('Manual')
            ->setSurnames('Test')
            ->setEmail('manual.'.strtolower($role).'@example.test')
            ->setPassword('not-used')
            ->setRoles([$role])
            ->setIsVerified(true);

        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
