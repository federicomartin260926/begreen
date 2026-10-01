<?php

namespace App\Tests\Service\CrewImport;

use App\Entity\CrewDepartment;
use App\Entity\Project;
use App\Exception\Ai\AiInvalidStructureException;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use App\Service\CrewImport\CrewCatalogContextProvider;
use App\Service\CrewImport\CrewImportAiInterpreter;
use App\Service\CrewImport\CrewImportAiOutputSchema;
use App\Service\CrewImport\CrewImportAiProviderInterface;
use App\Service\CrewImport\CrewImportInterpretedRowsAdapter;
use App\Service\CrewImport\CrewImportProposalBuilder;
use App\Service\CrewImport\CrewImportWarning;
use App\Service\CrewImport\Dto\CrewImportInterpretedRow;
use App\Service\CrewImport\Dto\CrewImportTabularDocument;
use App\Service\CrewImport\Dto\CrewImportTabularRow;
use App\Service\CrewImport\Dto\CrewImportTabularSheet;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CrewImportAiInterpreterTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private Project $project;
    private CrewDepartmentRepository $departments;
    private CrewPositionRepository $positions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        self::assertSame('begreenmyfriend_test', $this->connection->getDatabase());
        $this->connection->beginTransaction();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->departments = $container->get(CrewDepartmentRepository::class);
        $this->positions = $container->get(CrewPositionRepository::class);
        $this->project = (new Project())->setName('Synthetic AI crew')->setCountry('ES')->setType('rodaje');
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testValidInterpretationPreservesSourceAndDoesNotModifyDoctrine(): void
    {
        [$departmentId, $positionId] = $this->catalogIds();
        $provider = new SyntheticCrewAiProvider(['rows' => [
            $this->row('Sheet A!7', 'crew', $departmentId, $positionId),
        ]]);
        $before = $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions();

        $rows = $this->interpreter($provider)->interpret($this->project, new CrewImportTabularDocument([
            new CrewImportTabularSheet('Sheet A', [new CrewImportTabularRow(7, ['Synthetic Person'])]),
        ]));

        self::assertCount(1, $rows);
        self::assertSame('Sheet A!7', $rows[0]->sourceReference);
        self::assertSame('Synthetic Person', $rows[0]->fullName);
        self::assertSame($departmentId, $rows[0]->departmentId);
        self::assertSame($positionId, $rows[0]->positionId);
        self::assertSame($before, $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledEntityUpdates());
        self::assertStringNotContainsString((string) $this->project->getId(), $provider->instructions);
        self::assertStringContainsString('Synthetic Person', $provider->context);
    }

    public function testRejectsInvalidStructuredOutputAndMissingSourceReference(): void
    {
        $this->expectException(AiInvalidStructureException::class);
        $this->interpreter(new SyntheticCrewAiProvider(['unexpected' => []]))
            ->interpret($this->project, 'synthetic PDF text');
    }

    public function testRejectsRowWithMissingRequiredData(): void
    {
        $row = $this->row('Sheet!2', 'crew', null, null);
        unset($row['sourceReference']);

        $this->expectException(AiInvalidStructureException::class);
        $this->interpreter(new SyntheticCrewAiProvider(['rows' => [$row]]))
            ->interpret($this->project, 'synthetic content');
    }

    public function testInvalidAndCrossDepartmentIdsBecomeReviewWarnings(): void
    {
        [$departmentId, $positionId] = $this->catalogIds();
        $otherDepartment = $this->departments->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'PRODUCCIÓN',
        ]);
        self::assertInstanceOf(CrewDepartment::class, $otherDepartment);
        $rows = $this->interpreter(new SyntheticCrewAiProvider(['rows' => [
            $this->row('Sheet!2', 'crew', 999999, $positionId),
            $this->row('Sheet!3', 'crew', $otherDepartment->getId(), $positionId),
        ]]))->interpret($this->project, 'synthetic');

        self::assertNull($rows[0]->departmentId);
        self::assertNull($rows[0]->positionId);
        self::assertTrue($rows[0]->catalogMismatch);
        self::assertSame($otherDepartment->getId(), $rows[1]->departmentId);
        self::assertNull($rows[1]->positionId);
        self::assertTrue($rows[1]->catalogMismatch);
        self::assertNotSame($departmentId, $otherDepartment->getId());
    }

    public function testUnknownAndNonCrewRemainVisibleWithWarnings(): void
    {
        $unknown = $this->row('page 3 line 8', 'unknown', null, null);
        $nonCrew = $this->row('page 1 line 3', 'non_crew', null, null);
        $nonCrew['email'] = 'other.synthetic@example.test';
        $nonCrew['phone'] = '600000002';
        $rows = $this->interpreter(new SyntheticCrewAiProvider(['rows' => [$unknown, $nonCrew]]))
            ->interpret($this->project, 'synthetic PDF text');

        self::assertSame(CrewImportInterpretedRow::UNKNOWN, $rows[0]->rowKind);
        self::assertSame(CrewImportInterpretedRow::NON_CREW, $rows[1]->rowKind);

        $proposal = self::getContainer()->get(CrewImportProposalBuilder::class)->proposal(
            $this->project,
            (new CrewImportInterpretedRowsAdapter())->toExtraction($rows)
        );
        self::assertCount(2, $proposal->people);
        self::assertSame(['page 3 line 8'], $proposal->people[0]->sourceReferences);
        self::assertSame(CrewImportPersonProposal::REVIEW, $proposal->people[0]->action);
        self::assertSame(CrewImportPersonProposal::REVIEW, $proposal->people[1]->action);
        self::assertContains(CrewImportWarning::AI_ROW_UNKNOWN, $proposal->people[0]->warningCodes);
        self::assertContains(CrewImportWarning::AI_NON_CREW, $proposal->people[1]->warningCodes);
        self::assertFalse($proposal->isApplicable());
    }

    private function interpreter(CrewImportAiProviderInterface $provider): CrewImportAiInterpreter
    {
        return new CrewImportAiInterpreter(
            $provider,
            new CrewImportAiOutputSchema(),
            self::getContainer()->get(CrewCatalogContextProvider::class),
        );
    }

    /** @return array{int, int} */
    private function catalogIds(): array
    {
        $department = $this->departments->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'ARTE',
        ]);
        self::assertInstanceOf(CrewDepartment::class, $department);
        $position = $this->positions->findOneBy([
            'crewDepartment' => $department,
            'name' => 'Director/a de arte',
        ]);
        self::assertNotNull($position);

        return [(int) $department->getId(), (int) $position->getId()];
    }

    /** @return array<string, mixed> */
    private function row(string $source, string $kind, ?int $departmentId, ?int $positionId): array
    {
        return [
            'sourceReference' => $source,
            'fullName' => 'Synthetic Person',
            'proposedName' => 'Synthetic',
            'proposedLastName' => 'Person',
            'email' => 'synthetic@example.test',
            'phone' => '600000001',
            'originalDepartment' => 'Art',
            'originalPosition' => 'Art director',
            'rowKind' => $kind,
            'departmentId' => $departmentId,
            'positionId' => $positionId,
        ];
    }
}

final class SyntheticCrewAiProvider implements CrewImportAiProviderInterface
{
    public string $instructions = '';
    public string $context = '';

    /** @param array<string, mixed> $result */
    public function __construct(private readonly array $result)
    {
    }

    public function request(string $instructions, string $context, array $schema): array
    {
        $this->instructions = $instructions;
        $this->context = $context;
        if (($schema['properties']['rows']['type'] ?? null) !== 'array') {
            throw new \LogicException('Expected strict Crew schema.');
        }

        return $this->result;
    }
}
