<?php

namespace App\Tests\Service\CrewImport;

use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewPosition;
use App\Entity\Project;
use App\Exception\CrewImport\CrewImportApplyException;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewMemberRepository;
use App\Repository\CrewPositionRepository;
use App\Service\CrewImport\CrewImportApplier;
use App\Service\CrewImport\CrewImportProposalBuilder;
use App\Service\CrewImport\CrewImportSpreadsheetExtractor;
use App\Service\CrewImport\CrewImportWarning;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;
use App\Service\CrewImport\Dto\CrewImportRow;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CrewImportCoreTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private CrewImportSpreadsheetExtractor $extractor;
    private CrewImportProposalBuilder $proposalBuilder;
    private CrewImportApplier $applier;
    private CrewMemberRepository $memberRepository;
    private CrewDepartmentRepository $departmentRepository;
    private CrewPositionRepository $positionRepository;
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->extractor = $container->get(CrewImportSpreadsheetExtractor::class);
        $this->proposalBuilder = $container->get(CrewImportProposalBuilder::class);
        $this->applier = $container->get(CrewImportApplier::class);
        $this->memberRepository = $container->get(CrewMemberRepository::class);
        $this->departmentRepository = $container->get(CrewDepartmentRepository::class);
        $this->positionRepository = $container->get(CrewPositionRepository::class);
        $this->connection->beginTransaction();
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

    public function testProposalDoesNotModifyDoctrineAndContainsOnlyScalarCatalogIds(): void
    {
        $project = $this->project();
        $beforeMembers = $project->getCrewMembers()->toArray();
        $unitOfWork = $this->entityManager->getUnitOfWork();

        $proposal = $this->proposal($project, [
            new CrewImportRow(2, 'Ana', 'López', 'Director/a de arte', 'ARTE', 'ana@example.com', '600 111 222'),
        ]);

        self::assertSame($beforeMembers, $project->getCrewMembers()->toArray());
        self::assertSame([], $unitOfWork->getScheduledEntityInsertions());
        self::assertSame([], $unitOfWork->getScheduledEntityUpdates());
        self::assertTrue($proposal->isApplicable());
        self::assertSame(CrewImportPersonProposal::CREATE, $proposal->people[0]->action);
        self::assertIsInt($proposal->people[0]->assignments[0]->departmentId);
        self::assertIsInt($proposal->people[0]->assignments[0]->positionId);
        self::assertJson((string) json_encode($proposal, JSON_THROW_ON_ERROR));
    }

    public function testMatchesByPhoneAndDetectsDifferentEmailAndPhoneOwners(): void
    {
        $project = $this->project();
        $emailOwner = $this->member($project, 'Email Owner', 'owner@example.com', '600111111');
        $phoneOwner = $this->member($project, 'Phone Owner', 'other@example.com', '600222222');
        $this->entityManager->flush();

        $phoneProposal = $this->proposal($project, [
            new CrewImportRow(2, 'Phone', 'Updated', '', '', '', '600-111-111'),
        ]);
        self::assertSame(CrewImportPersonProposal::ASSOCIATE, $phoneProposal->people[0]->action);
        self::assertSame($emailOwner->getId(), $phoneProposal->people[0]->existingCrewMemberId);

        $conflictProposal = $this->proposal($project, [
            new CrewImportRow(2, 'Conflict', 'Person', '', '', 'owner@example.com', '(600) 222 222'),
        ]);
        self::assertSame(CrewImportPersonProposal::CONFLICT, $conflictProposal->people[0]->action);
        self::assertTrue($conflictProposal->people[0]->reviewRequired);
        self::assertFalse($conflictProposal->isApplicable());
        self::assertContains(CrewImportWarning::PERSON_IDENTITY_CONFLICT, $conflictProposal->people[0]->warningCodes);
        self::assertNotSame($emailOwner->getId(), $phoneOwner->getId());
    }

    public function testFileDeduplicationUsesEmailThenPhoneButNeverNameAlone(): void
    {
        $project = $this->project();
        $proposal = $this->proposal($project, [
            new CrewImportRow(2, 'Ana', 'López', '', '', '', ''),
            new CrewImportRow(3, 'Ana', 'López', '', '', '', ''),
            new CrewImportRow(4, 'Email One', '', '', '', ' SAME@example.com ', ''),
            new CrewImportRow(5, 'Email Final', '', '', '', 'same@example.com', ''),
            new CrewImportRow(6, 'Phone One', '', '', '', '', '600 333 333'),
            new CrewImportRow(7, 'Phone Final', '', '', '', '', '600-333-333'),
        ]);

        self::assertCount(4, $proposal->people);
        self::assertSame([[2], [3], [4, 5], [6, 7]], array_map(
            static fn (CrewImportPersonProposal $person): array => $person->sourceRows,
            $proposal->people
        ));
        self::assertSame('Email Final', $proposal->people[2]->name);
        self::assertSame('Phone Final', $proposal->people[3]->name);
        self::assertContains(CrewImportWarning::DUPLICATE_IN_FILE, $proposal->people[2]->warningCodes);
        self::assertContains(CrewImportWarning::DUPLICATE_IN_FILE, $proposal->people[3]->warningCodes);
    }

    public function testSameEmailWithChangedPhoneRemainsOnePersonWithoutConflict(): void
    {
        $project = $this->project();
        $proposal = $this->proposal($project, [
            new CrewImportRow(2, 'Ana', 'Inicial', '', '', 'ana@example.com', '600111111'),
            new CrewImportRow(3, 'Ana', 'Final', '', '', 'ANA@example.com', '600222222'),
        ]);

        self::assertCount(1, $proposal->people);
        self::assertSame([2, 3], $proposal->people[0]->sourceRows);
        self::assertSame(CrewImportPersonProposal::CREATE, $proposal->people[0]->action);
        self::assertFalse($proposal->people[0]->reviewRequired);
        self::assertSame('600222222', $proposal->people[0]->phone);
        self::assertNotContains(
            CrewImportWarning::PERSON_IDENTITY_CONFLICT,
            $proposal->people[0]->warningCodes
        );
    }

    public function testDifferentEmailsSharingPhoneProduceIdentityConflict(): void
    {
        $project = $this->project();
        $proposal = $this->proposal($project, [
            new CrewImportRow(2, 'Ana', 'Uno', '', '', 'a@example.com', '600111111'),
            new CrewImportRow(3, 'Ana', 'Dos', '', '', 'b@example.com', '600111111'),
        ]);

        self::assertCount(1, $proposal->people);
        self::assertSame([2, 3], $proposal->people[0]->sourceRows);
        self::assertSame(CrewImportPersonProposal::CONFLICT, $proposal->people[0]->action);
        self::assertTrue($proposal->people[0]->reviewRequired);
        self::assertFalse($proposal->isApplicable());
        self::assertContains(
            CrewImportWarning::PERSON_IDENTITY_CONFLICT,
            $proposal->people[0]->warningCodes
        );
    }

    public function testTransitiveEmailPhoneIdentityConflictIsDetected(): void
    {
        $project = $this->project();
        $proposal = $this->proposal($project, [
            new CrewImportRow(2, 'Ana', 'Uno', '', '', 'a@example.com', '600111111'),
            new CrewImportRow(3, 'Ana', 'Dos', '', '', 'a@example.com', '600222222'),
            new CrewImportRow(4, 'Ana', 'Tres', '', '', 'b@example.com', '600222222'),
        ]);

        self::assertCount(1, $proposal->people);
        self::assertSame([2, 3, 4], $proposal->people[0]->sourceRows);
        self::assertSame(CrewImportPersonProposal::CONFLICT, $proposal->people[0]->action);
        self::assertTrue($proposal->people[0]->reviewRequired);
        self::assertContains(
            CrewImportWarning::PERSON_IDENTITY_CONFLICT,
            $proposal->people[0]->warningCodes
        );
    }

    public function testRowWithoutEmailCanJoinPhoneIdentityAndReceiveKnownEmail(): void
    {
        $project = $this->project();
        $proposal = $this->proposal($project, [
            new CrewImportRow(2, 'Ana', 'Inicial', '', '', '', '600111111'),
            new CrewImportRow(3, 'Ana', 'Final', '', '', 'ana@example.com', '600111111'),
        ]);

        self::assertCount(1, $proposal->people);
        self::assertSame([2, 3], $proposal->people[0]->sourceRows);
        self::assertSame('ana@example.com', $proposal->people[0]->email);
        self::assertSame(CrewImportPersonProposal::CREATE, $proposal->people[0]->action);
        self::assertFalse($proposal->people[0]->reviewRequired);
        self::assertNotContains(
            CrewImportWarning::PERSON_IDENTITY_CONFLICT,
            $proposal->people[0]->warningCodes
        );
    }

    public function testApplyRejectsManipulatedDepartmentPositionCombinationBeforeMutation(): void
    {
        $project = $this->project();
        $art = $this->departmentRepository->findOneBy(['scope' => CrewDepartment::SCOPE_FILMING, 'name' => 'ARTE']);
        $director = $this->positionRepository->findOneBy(['name' => 'Director/a']);
        self::assertInstanceOf(CrewDepartment::class, $art);
        self::assertInstanceOf(CrewPosition::class, $director);

        $proposal = $this->manualProposal($project, null, CrewImportPersonProposal::CREATE, [
            new CrewImportAssignmentProposal(
                2,
                'ARTE',
                'Director/a',
                $art->getId(),
                $director->getId(),
                CrewImportAssignmentProposal::RESOLVED
            ),
        ]);

        $this->expectException(CrewImportApplyException::class);
        $this->expectExceptionMessage('does not belong');
        try {
            $this->applier->apply($project, $proposal);
        } finally {
            self::assertCount(0, $project->getCrewMembers());
            self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions());
        }
    }

    public function testApplyRejectsMemberFromAnotherProjectAndReviewProposal(): void
    {
        $project = $this->project();
        $otherProject = $this->project();
        $foreignMember = $this->member($otherProject, 'Foreign', 'foreign@example.com', null);
        $this->entityManager->flush();

        $foreignProposal = $this->manualProposal(
            $project,
            $foreignMember->getId(),
            CrewImportPersonProposal::ASSOCIATE,
            []
        );
        try {
            $this->applier->apply($project, $foreignProposal);
            self::fail('A member from another project must be rejected.');
        } catch (CrewImportApplyException $exception) {
            self::assertStringContainsString('does not belong', $exception->getMessage());
        }

        $reviewProposal = new CrewImportProposal($project->getId(), CrewImportExtraction::OFFICIAL_TEMPLATE, [
            new CrewImportPersonProposal(
                [2], 'Review Person', 'Review', 'Person', '', '', null,
                CrewImportPersonProposal::REVIEW, true,
                [CrewImportWarning::UNKNOWN_DEPARTMENT], []
            ),
        ]);
        $this->expectException(CrewImportApplyException::class);
        $this->applier->apply($project, $reviewProposal);
    }

    public function testValidApplyMutatesWithoutFlushAndThenPersists(): void
    {
        $project = $this->project();
        $proposal = $this->proposal($project, [
            new CrewImportRow(2, 'Ana', 'López', 'Director/a de arte', 'ARTE', 'ana@example.com', '600111222'),
        ]);

        $members = $this->applier->apply($project, $proposal);

        self::assertCount(1, $members);
        self::assertNull($members[0]->getId());
        self::assertCount(1, $project->getCrewMembers());
        self::assertCount(1, $members[0]->getAssignments());
        self::assertNotEmpty($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions());

        $this->entityManager->flush();
        self::assertNotNull($members[0]->getId());
        self::assertSame('Director/a de arte', $members[0]->getAssignments()->first()?->getCrewPosition()?->getName());
    }

    public function testUnsupportedHeadersReturnExplicitFreeFormatState(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Persona', 'Trabajo', 'Contacto'],
            ['Ana López', 'Arte', 'ana@example.com'],
        ]);
        $path = $this->temporaryPath();
        (new Xlsx($spreadsheet))->save($path);

        $extraction = $this->extractor->extract($path);

        self::assertSame(CrewImportExtraction::UNSUPPORTED_TEMPLATE, $extraction->status);
        self::assertSame([], $extraction->rows);
    }

    public function testOfficialXlsIsAcceptedAndFormulaCellsAreNotEvaluated(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Nombre', 'Apellido', 'Cargo', 'Departamento', 'Email', 'Teléfono'],
            ['=CONCATENATE("A","na")', 'López', '', '', 'ana@example.com', ''],
        ]);
        $path = $this->temporaryPath();
        (new Xls($spreadsheet))->save($path);

        $extraction = $this->extractor->extract($path);

        self::assertSame(CrewImportExtraction::OFFICIAL_TEMPLATE, $extraction->status);
        self::assertCount(1, $extraction->rows);
        self::assertSame('', $extraction->rows[0]->name);
        self::assertSame('López', $extraction->rows[0]->lastName);
    }

    /** @param list<CrewImportRow> $rows */
    private function proposal(Project $project, array $rows): CrewImportProposal
    {
        return $this->proposalBuilder->proposal(
            $project,
            new CrewImportExtraction(CrewImportExtraction::OFFICIAL_TEMPLATE, $rows)
        );
    }

    /** @param list<CrewImportAssignmentProposal> $assignments */
    private function manualProposal(Project $project, ?int $memberId, string $action, array $assignments): CrewImportProposal
    {
        return new CrewImportProposal($project->getId(), CrewImportExtraction::OFFICIAL_TEMPLATE, [
            new CrewImportPersonProposal(
                [2], 'Ana López', 'Ana', 'López', 'ana@example.com', '600111222',
                $memberId, $action, false, [], $assignments
            ),
        ]);
    }

    private function project(): Project
    {
        $project = (new Project())
            ->setName('Crew core '.uniqid('', true))
            ->setCountry('ES')
            ->setType('rodaje');
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function member(Project $project, string $name, ?string $email, ?string $phone): CrewMember
    {
        $member = (new CrewMember())->setName($name)->setEmail($email)->setPhone($phone);
        $project->addCrewMember($member);
        $this->entityManager->persist($member);

        return $member;
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'begreen_crew_core_');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
