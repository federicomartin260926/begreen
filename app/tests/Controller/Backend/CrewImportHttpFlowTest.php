<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectController;
use App\Entity\CrewDepartment;
use App\Entity\Project;
use App\Entity\User;
use App\Exception\CrewImport\CrewImportProposalStorageException;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewMemberRepository;
use App\Repository\CrewPositionRepository;
use App\Repository\ProjectBillingDocumentRepository;
use App\Service\CrewImport\CrewCatalogContextProvider;
use App\Service\CrewImport\CrewImportApplier;
use App\Service\CrewImport\CrewImportConfirmationBuilder;
use App\Service\CrewImport\CrewImportProposalBuilder;
use App\Service\CrewImport\CrewImportProposalStorage;
use App\Service\CrewImport\CrewImportSpreadsheetExtractor;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;
use App\Service\ProjectCompanyLogoStorage;
use App\Service\ProjectFeatureGate;
use App\Service\StripeInvoiceStorageService;
use App\Service\SustainabilityPlanCollaborationService;
use App\Service\SustainabilityPlanImplementationPhaseService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CrewImportHttpFlowTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ProjectController $controller;
    private CrewImportProposalStorage $storage;
    private User $user;
    private Project $project;
    private Session $session;
    /** @var list<string> */
    private array $temporaryFiles = [];
    /** @var list<string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        self::assertSame('begreenmyfriend_test', $this->connection->getDatabase());
        $this->connection->beginTransaction();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->storage = $container->get(CrewImportProposalStorage::class);
        $this->user = (new User())
            ->setName('Crew')
            ->setSurnames('Reviewer')
            ->setEmail('crew.reviewer.'.uniqid().'@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $this->project = (new Project())
            ->setName('Crew HTTP '.uniqid('', true))
            ->setCountry('ES')
            ->setType('rodaje')
            ->setUser($this->user);
        $this->entityManager->persist($this->user);
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
        $container->get('security.token_storage')->setToken(
            new UsernamePasswordToken($this->user, 'main', $this->user->getRoles())
        );

        $this->controller = new ProjectController(
            $container->get('translator'),
            $container->get(ProjectFeatureGate::class),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(StripeInvoiceStorageService::class),
            $container->get(SustainabilityPlanCollaborationService::class),
            $container->get(SustainabilityPlanImplementationPhaseService::class),
            $container->get(ProjectCompanyLogoStorage::class),
            $container->get(\App\Service\Animation\AnimationProjectConfigurationUpdater::class),
        );
        $this->controller->setContainer($container);
        $container->get('twig')->addGlobal('userProjects', []);
        $container->get('twig')->addGlobal('activeProject', null);
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
    }

    protected function tearDown(): void
    {
        foreach ($this->tokens as $token) {
            try {
                $this->storage->delete($token, (int) $this->project->getId(), (int) $this->user->getId(), $this->session->getId());
            } catch (CrewImportProposalStorageException) {
            }
        }
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testOfficialImportAlwaysReviewsThenConfirmPersistsAndDeletesStorage(): void
    {
        $container = self::getContainer();
        $request = $this->request('backend_project_import_crew', Request::METHOD_POST);
        $request->request->set('_token', $this->csrf('crew_import_upload_'.$this->project->getId()));
        $request->files->set('crewFile', $this->spreadsheet([
            ['Ana', 'López', 'Director/a de arte', 'ARTE', 'ana@example.test', '600111222'],
        ]));

        $response = $this->controller->importCrew(
            $this->project,
            $request,
            $container->get(CrewImportSpreadsheetExtractor::class),
            $container->get(CrewImportProposalBuilder::class),
            $this->storage,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertMatchesRegularExpression('~/crew/import/[a-f0-9]{64}/review$~', $response->getTargetUrl());
        preg_match('~/crew/import/([a-f0-9]{64})/review$~', $response->getTargetUrl(), $matches);
        $token = $matches[1];
        $this->tokens[] = $token;
        self::assertCount(0, self::getContainer()->get(CrewMemberRepository::class)->findByProject($this->project));
        self::assertCount(0, $this->project->getCrewMembers());
        $stored = $this->storage->load($token, (int) $this->project->getId(), (int) $this->user->getId(), $this->session->getId());
        self::assertTrue($stored->isApplicable());

        $reviewRequest = $this->request('backend_project_crew_import_review');
        $reviewRequest->attributes->set('token', $token);
        $reviewRequest->attributes->set('_route_params', ['id' => $this->project->getId(), 'token' => $token]);
        $review = $this->controller->reviewCrewImport(
            $this->project,
            $token,
            $reviewRequest,
            $this->storage,
            $container->get(CrewCatalogContextProvider::class),
            $container->get(CrewMemberRepository::class),
        );
        self::assertSame(200, $review->getStatusCode());
        self::assertStringContainsString('Ana López', (string) $review->getContent());
        self::assertStringContainsString('Director/a de arte', (string) $review->getContent());
        self::assertStringContainsString('CAMERA AND CINEMATOGRAPHY', (string) $review->getContent());
        self::assertCount(0, self::getContainer()->get(CrewMemberRepository::class)->findByProject($this->project));

        $department = $container->get(CrewDepartmentRepository::class)->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'ARTE',
        ]);
        $position = $container->get(CrewPositionRepository::class)->findOneBy([
            'crewDepartment' => $department,
            'name' => 'Director/a de arte',
        ]);
        self::assertNotNull($department);
        self::assertNotNull($position);
        $confirm = $this->request('backend_project_crew_import_confirm', Request::METHOD_POST);
        $confirm->request->replace([
            '_token' => $this->csrf('crew_import_review_'.$this->project->getId().'_'.$token),
            'people' => [[
                'include' => '1',
                'name' => 'Ana',
                'lastName' => 'López',
                'email' => 'ana@example.test',
                'phone' => '600111222',
                'action' => 'create',
                'existingCrewMemberId' => '',
                'assignments' => [[
                    'departmentId' => (string) $department->getId(),
                    'positionId' => (string) $position->getId(),
                ]],
            ]],
        ]);

        $response = $this->controller->confirmCrewImport(
            $this->project,
            $token,
            $confirm,
            $this->entityManager,
            $this->storage,
            $container->get(CrewImportConfirmationBuilder::class),
            $container->get(CrewImportApplier::class),
            $container->get(CrewCatalogContextProvider::class),
            $container->get(CrewMemberRepository::class),
        );

        self::assertSame(302, $response->getStatusCode());
        $members = $container->get(CrewMemberRepository::class)->findByProject($this->project);
        self::assertCount(1, $members);
        self::assertSame($position->getId(), $members[0]->getAssignments()->first()?->getCrewPosition()?->getId());
        $this->assertStorageMissing($token);
    }

    public function testCancelDeletesStorageWithoutPersistingCrew(): void
    {
        $proposal = new CrewImportProposal((int) $this->project->getId(), CrewImportExtraction::OFFICIAL_TEMPLATE, [
            new CrewImportPersonProposal([2], 'Ana', 'Ana', '', '', '', null, CrewImportPersonProposal::CREATE, false, [], []),
        ]);
        $token = $this->storage->store($proposal, (int) $this->user->getId(), $this->session->getId());
        $this->tokens[] = $token;
        $request = $this->request('backend_project_crew_import_cancel', Request::METHOD_POST);
        $request->request->set('_token', $this->csrf('crew_import_review_'.$this->project->getId().'_'.$token));

        $response = $this->controller->cancelCrewImport($this->project, $token, $request, $this->storage);

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(0, self::getContainer()->get(CrewMemberRepository::class)->findByProject($this->project));
        $this->assertStorageMissing($token);
    }

    public function testManipulatedCatalogIsRejectedAndStorageIsPreserved(): void
    {
        $container = self::getContainer();
        $department = $container->get(CrewDepartmentRepository::class)->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'ARTE',
        ]);
        $position = $container->get(CrewPositionRepository::class)->findOneBy([
            'crewDepartment' => $department,
            'name' => 'Director/a de arte',
        ]);
        $otherDepartment = $container->get(CrewDepartmentRepository::class)->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'PRODUCCIÓN',
        ]);
        self::assertNotNull($department);
        self::assertNotNull($position);
        self::assertNotNull($otherDepartment);
        $proposal = new CrewImportProposal((int) $this->project->getId(), CrewImportExtraction::OFFICIAL_TEMPLATE, [
            new CrewImportPersonProposal(
                [2], 'Ana', 'Ana', '', '', '', null, CrewImportPersonProposal::CREATE, false, [],
                [new CrewImportAssignmentProposal(
                    2, 'ARTE', 'Director/a de arte', $department->getId(), $position->getId(), CrewImportAssignmentProposal::RESOLVED
                )]
            ),
        ]);
        $token = $this->storage->store($proposal, (int) $this->user->getId(), $this->session->getId());
        $this->tokens[] = $token;
        $request = $this->request('backend_project_crew_import_confirm', Request::METHOD_POST);
        $request->attributes->set('token', $token);
        $request->attributes->set('_route_params', ['id' => $this->project->getId(), 'token' => $token]);
        $request->request->replace([
            '_token' => $this->csrf('crew_import_review_'.$this->project->getId().'_'.$token),
            'people' => [[
                'include' => '1',
                'name' => 'Ana',
                'lastName' => '',
                'email' => '',
                'phone' => '',
                'action' => 'create',
                'existingCrewMemberId' => '',
                'assignments' => [[
                    'departmentId' => (string) $otherDepartment->getId(),
                    'positionId' => (string) $position->getId(),
                ]],
            ]],
        ]);

        $response = $this->controller->confirmCrewImport(
            $this->project,
            $token,
            $request,
            $this->entityManager,
            $this->storage,
            $container->get(CrewImportConfirmationBuilder::class),
            $container->get(CrewImportApplier::class),
            $container->get(CrewCatalogContextProvider::class),
            $container->get(CrewMemberRepository::class),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('El cargo seleccionado no pertenece al departamento', (string) $response->getContent());
        self::assertCount(0, $container->get(CrewMemberRepository::class)->findByProject($this->project));
        self::assertInstanceOf(
            CrewImportProposal::class,
            $this->storage->load($token, (int) $this->project->getId(), (int) $this->user->getId(), $this->session->getId())
        );
    }

    public function testTokenFromAnotherSessionIsHiddenAsNotFound(): void
    {
        $proposal = new CrewImportProposal((int) $this->project->getId(), CrewImportExtraction::OFFICIAL_TEMPLATE, []);
        $token = $this->storage->store($proposal, (int) $this->user->getId(), $this->session->getId());
        $this->tokens[] = $token;
        $request = $this->request('backend_project_crew_import_review');
        $otherSession = new Session(new MockArraySessionStorage());
        $otherSession->start();
        $request->setSession($otherSession);

        $this->expectException(NotFoundHttpException::class);
        $this->controller->reviewCrewImport(
            $this->project,
            $token,
            $request,
            $this->storage,
            self::getContainer()->get(CrewCatalogContextProvider::class),
            self::getContainer()->get(CrewMemberRepository::class),
        );
    }

    public function testUnsupportedTemplateDoesNotCreateReviewOrCrew(): void
    {
        $request = $this->request('backend_project_import_crew', Request::METHOD_POST);
        $request->request->set('_token', $this->csrf('crew_import_upload_'.$this->project->getId()));
        $request->files->set('crewFile', $this->spreadsheetWithHeaders(
            ['Persona', 'Trabajo', 'Contacto'],
            [['Ana López', 'Arte', 'ana@example.test']]
        ));

        $response = $this->controller->importCrew(
            $this->project,
            $request,
            self::getContainer()->get(CrewImportSpreadsheetExtractor::class),
            self::getContainer()->get(CrewImportProposalBuilder::class),
            $this->storage,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringNotContainsString('/review', $response->getTargetUrl());
        self::assertCount(0, self::getContainer()->get(CrewMemberRepository::class)->findByProject($this->project));
    }

    public function testIdentityConflictReachesReviewAndIsNeverAutoApplied(): void
    {
        $request = $this->request('backend_project_import_crew', Request::METHOD_POST);
        $request->request->set('_token', $this->csrf('crew_import_upload_'.$this->project->getId()));
        $request->files->set('crewFile', $this->spreadsheet([
            ['Ana', 'Uno', '', '', 'ana.one@example.test', '600111222'],
            ['Ana', 'Dos', '', '', 'ana.two@example.test', '600111222'],
        ]));

        $response = $this->controller->importCrew(
            $this->project,
            $request,
            self::getContainer()->get(CrewImportSpreadsheetExtractor::class),
            self::getContainer()->get(CrewImportProposalBuilder::class),
            $this->storage,
        );
        preg_match('~/crew/import/([a-f0-9]{64})/review$~', $response->getTargetUrl(), $matches);
        self::assertArrayHasKey(1, $matches);
        $token = $matches[1];
        $this->tokens[] = $token;
        $stored = $this->storage->load(
            $token,
            (int) $this->project->getId(),
            (int) $this->user->getId(),
            $this->session->getId()
        );

        self::assertSame(CrewImportPersonProposal::CONFLICT, $stored->people[0]->action);
        self::assertFalse($stored->isApplicable());
        self::assertCount(0, self::getContainer()->get(CrewMemberRepository::class)->findByProject($this->project));
    }

    public function testUploadRejectsInvalidCsrf(): void
    {
        $request = $this->request('backend_project_import_crew', Request::METHOD_POST);
        $request->request->set('_token', 'invalid');
        $request->files->set('crewFile', $this->spreadsheet([]));

        $this->expectException(AccessDeniedException::class);
        $this->controller->importCrew(
            $this->project,
            $request,
            self::getContainer()->get(CrewImportSpreadsheetExtractor::class),
            self::getContainer()->get(CrewImportProposalBuilder::class),
            $this->storage,
        );
    }

    public function testConfirmAndCancelRejectInvalidCsrfWithoutDeletingStorage(): void
    {
        $proposal = new CrewImportProposal((int) $this->project->getId(), CrewImportExtraction::OFFICIAL_TEMPLATE, []);
        $token = $this->storage->store($proposal, (int) $this->user->getId(), $this->session->getId());
        $this->tokens[] = $token;
        $confirm = $this->request('backend_project_crew_import_confirm', Request::METHOD_POST);
        $confirm->request->replace(['_token' => 'invalid', 'people' => []]);

        try {
            $this->controller->confirmCrewImport(
                $this->project,
                $token,
                $confirm,
                $this->entityManager,
                $this->storage,
                self::getContainer()->get(CrewImportConfirmationBuilder::class),
                self::getContainer()->get(CrewImportApplier::class),
                self::getContainer()->get(CrewCatalogContextProvider::class),
                self::getContainer()->get(CrewMemberRepository::class),
            );
            self::fail('Confirm must reject an invalid CSRF token.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }

        $cancel = $this->request('backend_project_crew_import_cancel', Request::METHOD_POST);
        $cancel->request->set('_token', 'invalid');
        try {
            $this->controller->cancelCrewImport($this->project, $token, $cancel, $this->storage);
            self::fail('Cancel must reject an invalid CSRF token.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }

        self::assertInstanceOf(
            CrewImportProposal::class,
            $this->storage->load($token, (int) $this->project->getId(), (int) $this->user->getId(), $this->session->getId())
        );
        self::assertCount(0, self::getContainer()->get(CrewMemberRepository::class)->findByProject($this->project));
    }

    private function request(string $route, string $method = Request::METHOD_GET): Request
    {
        $request = Request::create('/test', $method);
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_params', ['id' => $this->project->getId()]);
        $request->setLocale('es');
        $request->setSession($this->session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function csrf(string $id): string
    {
        return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    /** @param list<array<string>> $rows */
    private function spreadsheet(array $rows): UploadedFile
    {
        return $this->spreadsheetWithHeaders(
            ['Nombre', 'Apellido', 'Cargo', 'Departamento', 'Email', 'Teléfono'],
            $rows
        );
    }

    /** @param list<string> $headers @param list<array<string>> $rows */
    private function spreadsheetWithHeaders(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            $headers,
            ...$rows,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'crew_http_');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'crew.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function assertStorageMissing(string $token): void
    {
        try {
            $this->storage->load($token, (int) $this->project->getId(), (int) $this->user->getId(), $this->session->getId());
            self::fail('The temporary proposal should have been deleted.');
        } catch (CrewImportProposalStorageException $exception) {
            self::assertSame('not_found', $exception->reason);
        }
    }
}
