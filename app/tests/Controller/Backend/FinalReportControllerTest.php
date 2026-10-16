<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\FinalReportController;
use App\Entity\FinalReportContent;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Entity\User;
use App\Repository\EmissionRecordRepository;
use App\Service\ActiveProjectService;
use App\Service\FinalReport\FinalReportContentEditor;
use App\Service\FinalReport\FinalReportDataBuilder;
use App\Service\PlanMeasureOperationalStateResolver;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use App\Service\SustainabilityCommitmentLevelService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class FinalReportControllerTest extends KernelTestCase
{
    public function testBaseReportIsVisibleWithoutPreviousEditorialContent(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();
        $this->request('backend_final_report_index');
        [$entityManager] = $this->entityManager($project, $plan, null);
        $activeProject = $this->activeProject($project);
        $emissionRepository = $this->getMockBuilder(EmissionRecordRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByProjectOrderByPhaseAndDate'])
            ->getMock();
        $emissionRepository->method('findByProjectOrderByPhaseAndDate')->willReturn([]);
        $commitment = (new \ReflectionClass(SustainabilityCommitmentLevelService::class))->newInstanceWithoutConstructor();
        $builder = new FinalReportDataBuilder(
            $emissionRepository,
            new PlanMeasureOperationalStateResolver(),
            $commitment,
        );

        $response = $this->controller()->index(
            $activeProject,
            $entityManager,
            $builder,
            new FinalReportContentEditor(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Informe Base', (string) $response->getContent());
        self::assertStringContainsString('Proyecto final', (string) $response->getContent());
        self::assertStringContainsString('Sin datos disponibles', (string) $response->getContent());
    }

    public function testSavePersistsManualContentWithValidCsrf(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_save', [], [], 'POST');
        $token = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_save_100')->getValue();
        $request->request->replace([
            '_token' => $token,
            'manualTexts' => ['introduction' => 'Texto conservado'],
        ]);
        [$entityManager] = $this->entityManager($project, $plan, null);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static fn (object $entity): bool => $entity instanceof FinalReportContent
                && ($entity->getManualTexts()['introduction']['text'] ?? null) === 'Texto conservado',
        ));
        $entityManager->expects(self::once())->method('flush');

        $response = $this->controller()->save(
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/backend/final-report', $response->getTargetUrl());
    }

    public function testSaveRejectsInvalidCsrf(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_save', [], ['_token' => 'invalid'], 'POST');
        [$entityManager] = $this->entityManager($project, $plan, null);
        $entityManager->expects(self::never())->method('persist');

        $this->expectException(AccessDeniedException::class);
        $this->controller()->save(
            $request,
            $this->activeProject($project),
            $entityManager,
            new FinalReportContentEditor(),
        );
    }

    public function testUploadsSupportedImageAndAddsItToGallery(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();
        $request = $this->request('backend_final_report_photo_upload', [], [], 'POST');
        $token = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('final_report_upload_100')->getValue();
        $request->request->set('_token', $token);
        $sourcePath = tempnam(sys_get_temp_dir(), 'final-report-upload-');
        self::assertIsString($sourcePath);
        file_put_contents($sourcePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $request->files->set('photo', new UploadedFile($sourcePath, 'report.png', 'image/png', null, true));
        $storageDirectory = sys_get_temp_dir().'/final-report-storage-'.bin2hex(random_bytes(6));
        mkdir($storageDirectory, 0777, true);
        [$entityManager] = $this->entityManager($project, $plan, null);
        $savedContent = null;
        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            function (object $entity) use (&$savedContent): void {
                if ($entity instanceof ProjectDocument) {
                    (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($entity, 20);
                }
                if ($entity instanceof FinalReportContent) {
                    $savedContent = $entity;
                }
            },
        );
        $entityManager->expects(self::exactly(2))->method('flush');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $entityManager->method('getConnection')->willReturn($connection);

        try {
            $response = $this->controller()->uploadPhoto(
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage($storageDirectory),
                new FinalReportContentEditor(),
            );

            self::assertSame(302, $response->getStatusCode());
            self::assertInstanceOf(FinalReportContent::class, $savedContent);
            self::assertSame(20, $savedContent->getPhotos()[0]['documentId']);
            self::assertSame('final_report_photo', $project->getProjectDocuments()->first()->getType());
            self::assertFileExists($storageDirectory.'/100/'.$project->getProjectDocuments()->first()->getStoredName());
        } finally {
            foreach (glob($storageDirectory.'/100/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($storageDirectory.'/100');
            @rmdir($storageDirectory);
            @unlink($sourcePath);
        }
    }

    public function testPhotoUploadRollsBackWhenSecondFlushFails(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->authenticateAdmin();

        $request = $this->request('backend_final_report_photo_upload', [], [], 'POST');
        $request->request->set(
            '_token',
            self::getContainer()->get('security.csrf.token_manager')
                ->getToken('final_report_upload_100')->getValue()
        );

        $sourcePath = tempnam(sys_get_temp_dir(), 'final-report-rollback-');
        self::assertIsString($sourcePath);
        file_put_contents(
            $sourcePath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
        );
        $request->files->set('photo', new UploadedFile($sourcePath, 'rollback.png', 'image/png', null, true));

        $storageDirectory = sys_get_temp_dir().'/final-report-rollback-'.bin2hex(random_bytes(6));
        mkdir($storageDirectory, 0777, true);

        [$entityManager] = $this->entityManager($project, $plan, null);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack');
        $entityManager->method('getConnection')->willReturn($connection);

        $entityManager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity): void {
                if ($entity instanceof ProjectDocument) {
                    (new \ReflectionProperty(ProjectDocument::class, 'id'))->setValue($entity, 21);
                }
            }
        );
        $flushCount = 0;
        $entityManager->expects(self::exactly(2))->method('flush')->willReturnCallback(
            static function () use (&$flushCount): void {
                if (++$flushCount === 2) {
                    throw new \RuntimeException('Simulated second flush failure');
                }
            }
        );

        try {
            $this->controller()->uploadPhoto(
                $request,
                $this->activeProject($project),
                $entityManager,
                new ProjectDocumentStorage($storageDirectory),
                new FinalReportContentEditor(),
            );
            self::fail('Expected upload failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated second flush failure', $exception->getMessage());
            self::assertSame([], glob($storageDirectory.'/100/*') ?: []);
        } finally {
            foreach (glob($storageDirectory.'/100/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storageDirectory.'/100');
            @rmdir($storageDirectory);
            @unlink($sourcePath);
        }
    }

    public function testIndexRequiresProjectPermission(): void
    {
        self::bootKernel();
        $project = $this->project();
        $plan = (new Plan())->setProject($project);
        $this->request('backend_final_report_index');
        self::getContainer()->get('security.token_storage')->setToken(null);
        [$entityManager] = $this->entityManager($project, $plan, null);

        $this->expectException(AccessDeniedException::class);
        $this->controller()->index(
            $this->activeProject($project),
            $entityManager,
            (new \ReflectionClass(FinalReportDataBuilder::class))->newInstanceWithoutConstructor(),
            new FinalReportContentEditor(),
        );
    }

    private function controller(): FinalReportController
    {
        $controller = new FinalReportController();
        $controller->setContainer(self::getContainer());

        return $controller;
    }

    private function project(): Project
    {
        $project = (new Project())->setName('Proyecto final')->setType('rodaje')->setCountry('ES');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 100);

        return $project;
    }

    private function authenticateAdmin(): void
    {
        $user = (new User())
            ->setEmail('final-report-admin@example.test')
            ->setName('Admin')
            ->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }

    private function request(string $route, array $attributes = [], array $parameters = [], string $method = 'GET'): Request
    {
        $request = new Request([], $parameters, $attributes, [], [], ['REQUEST_METHOD' => $method]);
        $request->attributes->set('_route', $route);
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('userProjects', []);
        self::getContainer()->get('twig')->addGlobal('activeProject', null);

        return $request;
    }

    private function activeProject(Project $project): ActiveProjectService&MockObject
    {
        $service = $this->createMock(ActiveProjectService::class);
        $service->method('getActiveProject')->willReturn($project);

        return $service;
    }

    /** @return array{EntityManagerInterface&MockObject} */
    private function entityManager(Project $project, Plan $plan, ?FinalReportContent $content): array
    {
        $planRepository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $planRepository->method('findOneBy')->with(['project' => $project])->willReturn($plan);
        $contentRepository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $contentRepository->method('findOneBy')->with(['project' => $project])->willReturn($content);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => match ($class) {
                Plan::class => $planRepository,
                FinalReportContent::class => $contentRepository,
                default => throw new \LogicException('Unexpected repository '.$class),
            },
        );

        return [$entityManager];
    }
}
