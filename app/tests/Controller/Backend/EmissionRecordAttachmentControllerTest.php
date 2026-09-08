<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\EmissionRecordAttachmentController;
use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\EmissionRecordAttachment;
use App\Entity\Project;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class EmissionRecordAttachmentControllerTest extends KernelTestCase
{
    private string $directory;
    private EmissionRecordAttachmentStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bgfm-attachment-controller-'.bin2hex(random_bytes(8));
        $this->storage = new EmissionRecordAttachmentStorage($this->directory);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testAuthorizedDownloadReturnsPrivateFileWithOriginalFilename(): void
    {
        [$record, $attachment] = $this->fixture();

        $response = $this->controller()->download(7, 8, $this->active($record->getProject()), $this->entityManager($record, $attachment), $this->storage);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertStringContainsString('factura.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertSame($this->storage->absolutePath($attachment), $response->getFile()->getPathname());
    }

    public function testMismatchedRecordAndAttachmentReturns404(): void
    {
        [$record, $attachment] = $this->fixture();
        $other = (new EmissionRecord())->setProject($record->getProject());
        $this->setId($other, 99);
        $attachment->setEmissionRecord($other);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->download(7, 8, $this->active($record->getProject()), $this->entityManager($record, $attachment), $this->storage);
    }

    public function testMissingPhysicalFileReturns404(): void
    {
        [$record, $attachment] = $this->fixture();
        unlink($this->storage->absolutePath($attachment));

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->download(7, 8, $this->active($record->getProject()), $this->entityManager($record, $attachment), $this->storage);
    }

    public function testInvalidCsrfDoesNotDeleteDatabaseOrFile(): void
    {
        [$record, $attachment] = $this->fixture();
        $entityManager = $this->entityManager($record, $attachment);
        $entityManager->expects(self::never())->method('remove');
        $request = $this->request(['_token' => 'invalid']);

        $response = $this->controller()->delete(7, 8, $request, $this->active($record->getProject()), $entityManager, $this->storage);

        self::assertSame(302, $response->getStatusCode());
        self::assertFileExists($this->storage->absolutePath($attachment));
    }

    public function testValidDeleteRemovesDatabaseEntityAndPhysicalFile(): void
    {
        [$record, $attachment] = $this->fixture();
        $entityManager = $this->entityManager($record, $attachment);
        $entityManager->expects(self::once())->method('remove')->with($attachment);
        $entityManager->expects(self::once())->method('flush');
        $request = $this->request();
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')->getToken('delete_emission_attachment_8')->getValue());
        $path = $this->storage->absolutePath($attachment);

        $response = $this->controller()->delete(
            7,
            8,
            $request,
            $this->active($record->getProject()),
            $entityManager,
            $this->storage,
        );

        self::assertFileDoesNotExist($path);
        self::assertFalse($record->getAttachments()->contains($attachment));
        self::assertSame(
            self::getContainer()->get('router')->generate('backend_emission_edit_transport_v20', ['id' => 7]),
            $response->headers->get('Location')
        );
    }

    public function testValidDeleteRedirectsEnergyRecordBackToEnergyEditor(): void
    {
        [$record, $attachment] = $this->fixture();
        $record->setCategory((new Category())->setName('Energía'));

        $entityManager = $this->entityManager($record, $attachment);
        $entityManager->expects(self::once())->method('remove')->with($attachment);
        $entityManager->expects(self::once())->method('flush');

        $request = $this->request();
        $request->request->set(
            '_token',
            self::getContainer()->get('security.csrf.token_manager')
                ->getToken('delete_emission_attachment_8')
                ->getValue()
        );

        $response = $this->controller()->delete(
            7,
            8,
            $request,
            $this->active($record->getProject()),
            $entityManager,
            $this->storage,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            self::getContainer()->get('router')->generate('backend_emission_edit_energy_v1', ['id' => 7]),
            $response->headers->get('Location')
        );
    }

    public function testValidDeleteRedirectsWaterRecordBackToWaterEditor(): void
    {
        [$record, $attachment] = $this->fixture();
        $category = (new Category())->setName('Agua');
        $this->setId($category, 5);
        $record->setCategory($category);
        $entityManager = $this->entityManager($record, $attachment);
        $entityManager->expects(self::once())->method('remove')->with($attachment);
        $entityManager->expects(self::once())->method('flush');
        $request = $this->request();
        $request->request->set(
            '_token',
            self::getContainer()->get('security.csrf.token_manager')
                ->getToken('delete_emission_attachment_8')
                ->getValue()
        );

        $response = $this->controller()->delete(
            7,
            8,
            $request,
            $this->active($record->getProject()),
            $entityManager,
            $this->storage,
        );

        self::assertSame(
            self::getContainer()->get('router')->generate('backend_emission_edit_water_v1', ['id' => 7]),
            $response->headers->get('Location')
        );
    }

    public function testValidDeleteRedirectsAccommodationRecordBackToAccommodationEditor(): void
    {
        [$record, $attachment] = $this->fixture();
        $record->setCategory((new Category())->setName('Alojamientos'));
        $entityManager = $this->entityManager($record, $attachment);
        $entityManager->expects(self::once())->method('remove')->with($attachment);
        $entityManager->expects(self::once())->method('flush');
        $request = $this->request();
        $request->request->set(
            '_token',
            self::getContainer()->get('security.csrf.token_manager')
                ->getToken('delete_emission_attachment_8')
                ->getValue()
        );

        $response = $this->controller()->delete(
            7,
            8,
            $request,
            $this->active($record->getProject()),
            $entityManager,
            $this->storage,
        );

        self::assertSame(
            self::getContainer()->get('router')->generate('backend_emission_edit_accommodation_v1', ['id' => 7]),
            $response->headers->get('Location')
        );
    }

    public function testDifferentActiveProjectIsHidden(): void
    {
        [$record, $attachment] = $this->fixture();
        $otherProject = (new Project())->setName('Other')->setType('rodaje')->setCountry('ES');
        $this->setId($otherProject, 20);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->download(7, 8, $this->active($otherProject), $this->entityManager($record, $attachment), $this->storage);
    }

    public function testDownloadRequiresEmissionRecordViewPermission(): void
    {
        [$record, $attachment] = $this->fixture();
        self::getContainer()->get('security.token_storage')->setToken(null);
        $controller = new EmissionRecordAttachmentController();
        $controller->setContainer(self::getContainer());

        $this->expectException(AccessDeniedException::class);
        $controller->download(7, 8, $this->active($record->getProject()), $this->entityManager($record, $attachment), $this->storage);
    }

    /** @return array{EmissionRecord, EmissionRecordAttachment} */
    private function fixture(): array
    {
        $project = (new Project())->setName('Project')->setType('rodaje')->setCountry('ES');
        $this->setId($project, 10);
        $record = (new EmissionRecord())->setProject($project);
        $this->setId($record, 7);
        $path = tempnam(sys_get_temp_dir(), 'download-pdf-');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $attachment = $this->storage->store($record, new UploadedFile($path, 'factura.pdf', null, null, true));
        $this->setId($attachment, 8);

        return [$record, $attachment];
    }

    private function controller(): EmissionRecordAttachmentController
    {
        $user = (new \App\Entity\User())->setName('Admin')->setSurnames('User')->setEmail('admin@example.test')
            ->setPassword('password')->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $controller = new EmissionRecordAttachmentController();
        $controller->setContainer(self::getContainer());

        return $controller;
    }

    /** @return EntityManagerInterface&MockObject */
    private function entityManager(EmissionRecord $record, EmissionRecordAttachment $attachment): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(static fn (string $class, mixed $id): ?object => match ([$class, $id]) {
            [EmissionRecord::class, 7] => $record,
            [EmissionRecordAttachment::class, 8] => $attachment,
            default => null,
        });

        return $entityManager;
    }

    private function active(Project $project): ActiveProjectService
    {
        $active = $this->createMock(ActiveProjectService::class);
        $active->method('getActiveProject')->willReturn($project);

        return $active;
    }

    private function request(array $post = []): Request
    {
        $request = new Request([], $post, [], [], [], ['REQUEST_METHOD' => 'POST']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
