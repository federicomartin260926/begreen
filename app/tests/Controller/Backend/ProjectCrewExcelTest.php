<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\ProjectController;
use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\Project;
use App\Enum\ProjectCatalog;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewMemberRepository;
use App\Repository\CrewPositionRepository;
use App\Repository\ProjectBillingDocumentRepository;
use App\Service\ActiveProjectService;
use App\Service\CrewCatalogScopeResolver;
use App\Service\ProjectCompanyLogoStorage;
use App\Service\ProjectFeatureGate;
use App\Service\StripeInvoiceStorageService;
use App\Service\SustainabilityPlanCollaborationService;
use App\Service\SustainabilityPlanImplementationPhaseService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProjectCrewExcelTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ProjectController $controller;
    /** @var string[] */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection->beginTransaction();
        $this->controller = new ProjectController(
            $container->get('translator'),
            $container->get(ProjectFeatureGate::class),
            $this->createMock(ProjectBillingDocumentRepository::class),
            $this->createMock(StripeInvoiceStorageService::class),
            $container->get(SustainabilityPlanCollaborationService::class),
            $container->get(SustainabilityPlanImplementationPhaseService::class),
            $container->get(ProjectCompanyLogoStorage::class),
        );
        $this->controller->setContainer($container);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public static function templateCases(): iterable
    {
        yield 'rodaje' => ['rodaje', null, 22, 202, 'ARTE', 'Diseñador/a de producción'];
        yield 'evento' => ['evento', null, 22, 132, 'PRODUCCIÓN', 'Productor/a ejecutivo/a'];
        yield 'animacion' => ['rodaje', ProjectCatalog::FILMING_GENRE_ANIMATION, 25, 169, 'DESARROLLO Y GUION', 'Guionista'];
    }

    #[DataProvider('templateCases')]
    public function testTemplateUsesOrderedCrewCatalogForEffectiveScope(
        string $projectType,
        ?string $filmingGenre,
        int $expectedDepartments,
        int $expectedPositions,
        string $firstDepartment,
        string $firstPosition
    ): void {
        $project = $this->project($projectType, $filmingGenre);
        $activeProjectService = $this->createMock(ActiveProjectService::class);
        $activeProjectService->method('getActiveProject')->willReturn($project);

        $response = $this->controller->downloadCrewTemplate(
            self::getContainer()->get(CrewPositionRepository::class),
            self::getContainer()->get(CrewDepartmentRepository::class),
            self::getContainer()->get(CrewCatalogScopeResolver::class),
            $activeProjectService
        );

        ob_start();
        $response->sendContent();
        $contents = (string) ob_get_clean();
        $path = $this->temporaryPath();
        file_put_contents($path, $contents);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $lists = $spreadsheet->getSheetByName('Listas');
        $scope = self::getContainer()->get(CrewCatalogScopeResolver::class)->resolve($project);
        $catalogDepartments = self::getContainer()->get(CrewDepartmentRepository::class)->findByScope($scope);

        self::assertNotNull($lists);
        self::assertSame(['Nombre', 'Apellido', 'Cargo', 'Departamento', 'Email', 'Teléfono'], $sheet->rangeToArray('A1:F1')[0]);
        self::assertSame($expectedDepartments, $lists->getHighestDataRow('D'));
        self::assertSame($expectedPositions, $lists->getHighestDataRow('A'));
        self::assertSame($firstDepartment, $lists->getCell('D1')->getValue());
        self::assertSame($firstDepartment, $lists->getCell('A1')->getValue());
        self::assertSame($firstPosition, $lists->getCell('B1')->getValue());
        self::assertStringContainsString("'Listas'!\$D\$1:\$D\$", (string) $sheet->getCell('D2')->getDataValidation()->getFormula1());
        self::assertStringContainsString('MATCH($D2', (string) $sheet->getCell('C2')->getDataValidation()->getFormula1());

        $listRow = 1;
        foreach ($catalogDepartments as $departmentIndex => $department) {
            self::assertSame($department->getName(), $lists->getCell('D'.($departmentIndex + 1))->getValue());
            foreach (self::getContainer()->get(CrewPositionRepository::class)->findByCrewDepartment($department) as $position) {
                self::assertSame($department->getName(), $lists->getCell('A'.$listRow)->getValue());
                self::assertSame($position->getName(), $lists->getCell('B'.$listRow)->getValue());
                ++$listRow;
            }
        }
    }

    public function testImportsRowsWithoutAssignmentsAndDoesNotMergePeopleWithoutEmail(): void
    {
        $project = $this->persistProject('rodaje');

        [$ok] = $this->importRows($project, [
            ['Ana', '', '', '', '', ''],
            ['Ana', 'López', '', '', '', '+34 600 000 000'],
        ]);

        self::assertTrue($ok);
        $members = $this->members($project);
        self::assertCount(2, $members);
        self::assertCount(0, $members[0]->getAssignments());
        self::assertCount(0, $members[1]->getAssignments());
        self::assertSame('López', $members[1]->getLastName());
        self::assertSame('+34 600 000 000', $members[1]->getPhone());
    }

    public function testImportsDepartmentWithOptionalPosition(): void
    {
        $project = $this->persistProject('rodaje');

        [$ok] = $this->importRows($project, [
            ['Ana', '', '', 'ARTE', 'ana@example.com', ''],
            ['Luis', '', 'Director/a de arte', 'ARTE', 'luis@example.com', ''],
        ]);

        self::assertTrue($ok);
        $members = $this->members($project);
        self::assertCount(2, $members);
        self::assertSame('ARTE', $members[0]->getAssignments()->first()?->getCrewDepartment()?->getName());
        self::assertNull($members[0]->getAssignments()->first()?->getCrewPosition());
        self::assertSame('Director/a de arte', $members[1]->getAssignments()->first()?->getCrewPosition()?->getName());
    }

    public function testInfersUniquePositionDepartmentButRejectsAmbiguousPosition(): void
    {
        $filming = $this->persistProject('rodaje');
        [$ok] = $this->importRows($filming, [
            ['Ana', '', 'Concept art', '', 'ana@example.com', ''],
        ]);

        self::assertTrue($ok);
        self::assertSame('ARTE', $this->members($filming)[0]->getAssignments()->first()?->getCrewDepartment()?->getName());

        $event = $this->persistProject('evento');
        [$ok, $messages] = $this->importRows($event, [
            ['Luis', '', 'Realizador/a', '', 'luis@example.com', ''],
        ], false);

        self::assertFalse($ok);
        self::assertStringContainsString('varios departamentos', $messages[0]);
    }

    public function testRejectsIncompatibleDepartmentPositionAndWrongScope(): void
    {
        $project = $this->persistProject('rodaje');

        [$ok, $messages] = $this->importRows($project, [
            ['Ana', '', 'Director/a', 'ARTE', 'ana@example.com', ''],
        ], false);
        self::assertFalse($ok);
        self::assertStringContainsString('no pertenece', $messages[0]);

        [$ok, $messages] = $this->importRows($project, [
            ['Luis', '', '', 'DIRECCIÓN TÉCNICA', 'luis@example.com', ''],
        ], false);
        self::assertFalse($ok);
        self::assertStringContainsString('no encontrado', $messages[0]);
    }

    public function testRepeatedEmailAccumulatesDepartmentsAndLastPersonalDataWins(): void
    {
        $project = $this->persistProject('rodaje');

        [$ok] = $this->importRows($project, [
            ['Ana', 'Inicial', 'Director/a de arte', 'ARTE', ' ANA@Example.com ', '111'],
            ['Ana María', 'Final', 'Productor/a', 'PRODUCCIÓN', 'ana@example.com', '222'],
        ]);

        self::assertTrue($ok);
        $members = $this->members($project);
        self::assertCount(1, $members);
        self::assertSame('Ana María', $members[0]->getName());
        self::assertSame('Final', $members[0]->getLastName());
        self::assertSame('ana@example.com', $members[0]->getEmail());
        self::assertSame('222', $members[0]->getPhone());
        self::assertSame(
            ['ARTE', 'PRODUCCIÓN'],
            array_map(
                static fn ($assignment): ?string => $assignment->getCrewDepartment()?->getName(),
                $members[0]->getAssignments()->toArray()
            )
        );
    }

    public function testExistingMemberIsFoundByEmailCaseInsensitivelyWithinProject(): void
    {
        $project = $this->persistProject('rodaje');
        $existing = (new CrewMember())
            ->setName('Nombre anterior')
            ->setEmail('Ana@Example.com');
        $project->addCrewMember($existing);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        [$ok] = $this->importRows($project, [
            ['Ana', 'López', 'Director/a de arte', 'ARTE', 'ana@example.com', '333'],
        ]);

        self::assertTrue($ok);
        $members = $this->members($project);
        self::assertCount(1, $members);
        self::assertSame($existing->getId(), $members[0]->getId());
        self::assertSame('Ana', $members[0]->getName());
        self::assertCount(1, $members[0]->getAssignments());
    }

    public function testRepeatedEmailAccumulatesDifferentPositionsInSameDepartment(): void
    {
        $project = $this->persistProject('rodaje');

        [$ok] = $this->importRows($project, [
            ['Ana', '', 'Director/a de arte', 'ARTE', 'ana@example.com', ''],
            ['Ana', '', 'Ayudante de arte', 'ARTE', 'ANA@example.com', ''],
        ]);

        self::assertTrue($ok);
        $members = $this->members($project);
        self::assertCount(1, $members);
        $this->assertSamePositions(['Director/a de arte', 'Ayudante de arte'], $members[0]);
    }

    public function testRepeatedExactAssignmentIsIgnored(): void
    {
        $project = $this->persistProject('rodaje');

        [$ok] = $this->importRows($project, [
            ['Ana', '', 'Director/a de arte', 'ARTE', 'ana@example.com', ''],
            ['Ana', '', 'Director/a de arte', 'ARTE', 'ANA@example.com', ''],
        ]);

        self::assertTrue($ok);
        $members = $this->members($project);
        self::assertCount(1, $members);
        self::assertCount(1, $members[0]->getAssignments());
    }

    /** @return array{bool, string[]} */
    private function importRows(Project $project, array $rows, bool $flush = true): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Nombre', 'Apellido', 'Cargo', 'Departamento', 'Email', 'Teléfono'],
            ...$rows,
        ]);
        $path = $this->temporaryPath();
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile(
            $path,
            'crew.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $method = new \ReflectionMethod(ProjectController::class, 'processCrewFile');
        /** @var array{bool, string[]} $result */
        $result = $method->invoke(
            $this->controller,
            $file,
            $project,
            $this->entityManager,
            self::getContainer()->get(CrewCatalogScopeResolver::class)
        );

        if ($result[0] && $flush) {
            $this->entityManager->flush();
        }

        return $result;
    }

    private function project(string $type, ?string $filmingGenre = null): Project
    {
        return (new Project())
            ->setName('Crew Excel test '.uniqid('', true))
            ->setCountry('ES')
            ->setType($type)
            ->setFilmingGenre($filmingGenre);
    }

    private function persistProject(string $type, ?string $filmingGenre = null): Project
    {
        $project = $this->project($type, $filmingGenre);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /** @return CrewMember[] */
    private function members(Project $project): array
    {
        return self::getContainer()->get(CrewMemberRepository::class)->findBy(
            ['project' => $project],
            ['id' => 'ASC']
        );
    }

    private function assertSamePositions(array $expected, CrewMember $member): void
    {
        self::assertSame(
            $expected,
            array_map(
                static fn ($assignment): ?string => $assignment->getCrewPosition()?->getName(),
                $member->getAssignments()->toArray()
            )
        );
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'begreen_crew_excel_');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
