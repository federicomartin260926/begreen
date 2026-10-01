<?php

namespace App\Tests\Service\CrewImport;

use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewPosition;
use App\Entity\Project;
use App\Exception\CrewImport\CrewImportReviewValidationException;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use App\Service\CrewImport\CrewImportConfirmationBuilder;
use App\Service\CrewImport\CrewImportWarning;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportExtraction;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CrewImportConfirmationBuilderTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private CrewImportConfirmationBuilder $builder;
    private CrewDepartmentRepository $departmentRepository;
    private CrewPositionRepository $positionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        self::assertSame('begreenmyfriend_test', $this->connection->getDatabase());
        $this->connection->beginTransaction();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->builder = $container->get(CrewImportConfirmationBuilder::class);
        $this->departmentRepository = $container->get(CrewDepartmentRepository::class);
        $this->positionRepository = $container->get(CrewPositionRepository::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testBuildsValidCreateWithoutModifyingDoctrine(): void
    {
        $project = $this->project('rodaje');
        [$department, $position] = $this->filmingAssignment();
        $stored = $this->proposal($project, CrewImportPersonProposal::CREATE, null, $department, $position);
        $before = $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions();

        $confirmed = $this->builder->build($stored, $project, $this->input('create', null, $department, $position));

        self::assertTrue($confirmed->isApplicable());
        self::assertSame(CrewImportPersonProposal::CREATE, $confirmed->people[0]->action);
        self::assertSame($department->getId(), $confirmed->people[0]->assignments[0]->departmentId);
        self::assertSame($position->getId(), $confirmed->people[0]->assignments[0]->positionId);
        self::assertSame($before, $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledEntityUpdates());
    }

    public function testAssociatesOnlyMemberFromSameProject(): void
    {
        $project = $this->project('rodaje');
        $member = $this->member($project, 'Existing');
        $foreignProject = $this->project('rodaje');
        $foreign = $this->member($foreignProject, 'Foreign');
        $this->entityManager->flush();
        [$department, $position] = $this->filmingAssignment();
        $stored = $this->proposal($project, CrewImportPersonProposal::ASSOCIATE, $member->getId(), $department, $position);

        $confirmed = $this->builder->build(
            $stored,
            $project,
            $this->input('associate', $member->getId(), $department, $position)
        );
        self::assertSame($member->getId(), $confirmed->people[0]->existingCrewMemberId);

        $this->expectValidation(fn () => $this->builder->build(
            $stored,
            $project,
            $this->input('associate', $foreign->getId(), $department, $position)
        ));
    }

    public function testRejectsDepartmentOutsideScopeAndMismatchedPosition(): void
    {
        $project = $this->project('rodaje');
        [$filmingDepartment, $filmingPosition] = $this->filmingAssignment();
        $eventDepartment = $this->departmentRepository->findOneBy(['scope' => CrewDepartment::SCOPE_EVENT]);
        $otherFilmingDepartment = $this->departmentRepository->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'PRODUCCIÓN',
        ]);
        self::assertInstanceOf(CrewDepartment::class, $eventDepartment);
        self::assertInstanceOf(CrewDepartment::class, $otherFilmingDepartment);
        $stored = $this->proposal($project, CrewImportPersonProposal::CREATE, null, $filmingDepartment, $filmingPosition);

        $this->expectValidation(fn () => $this->builder->build(
            $stored,
            $project,
            $this->input('create', null, $eventDepartment, null)
        ));
        $this->expectValidation(fn () => $this->builder->build(
            $stored,
            $project,
            $this->input('create', null, $otherFilmingDepartment, $filmingPosition)
        ));
    }

    public function testRejectsPositionWithoutDepartment(): void
    {
        $project = $this->project('rodaje');
        [$department, $position] = $this->filmingAssignment();

        $this->expectValidation(fn () => $this->builder->build(
            $this->proposal($project, CrewImportPersonProposal::REVIEW, null, $department, $position),
            $project,
            $this->input('create', null, null, $position)
        ));
    }

    public function testConflictCanBeExplicitlyResolvedToCreateOrAssociate(): void
    {
        $project = $this->project('rodaje');
        $member = $this->member($project, 'Existing');
        $this->entityManager->flush();
        [$department, $position] = $this->filmingAssignment();
        $stored = $this->proposal($project, CrewImportPersonProposal::CONFLICT, null, $department, $position);

        $created = $this->builder->build($stored, $project, $this->input('create', null, $department, $position));
        $associated = $this->builder->build($stored, $project, $this->input('associate', $member->getId(), $department, $position));

        self::assertSame(CrewImportPersonProposal::CREATE, $created->people[0]->action);
        self::assertSame(CrewImportPersonProposal::ASSOCIATE, $associated->people[0]->action);
        self::assertFalse($created->people[0]->reviewRequired);
        self::assertNotContains(CrewImportWarning::PERSON_IDENTITY_CONFLICT, $created->people[0]->warningCodes);
    }

    public function testOmittedPersonIsRemovedFromConfirmedProposal(): void
    {
        $project = $this->project('rodaje');
        [$department, $position] = $this->filmingAssignment();
        $input = $this->input('create', null, $department, $position);
        $input['people'][0]['include'] = '0';

        $confirmed = $this->builder->build(
            $this->proposal($project, CrewImportPersonProposal::CONFLICT, null, $department, $position),
            $project,
            $input
        );

        self::assertSame([], $confirmed->people);
        self::assertTrue($confirmed->isApplicable());
    }

    public function testRejectsExtraPeopleAssignmentsAndInvalidEmail(): void
    {
        $project = $this->project('rodaje');
        [$department, $position] = $this->filmingAssignment();
        $stored = $this->proposal($project, CrewImportPersonProposal::CREATE, null, $department, $position);

        $extraPerson = $this->input('create', null, $department, $position);
        $extraPerson['people'][1] = $extraPerson['people'][0];
        $this->expectValidation(fn () => $this->builder->build($stored, $project, $extraPerson));

        $extraAssignment = $this->input('create', null, $department, $position);
        $extraAssignment['people'][0]['assignments'][1] = ['departmentId' => '', 'positionId' => ''];
        $this->expectValidation(fn () => $this->builder->build($stored, $project, $extraAssignment));

        $invalidEmail = $this->input('create', null, $department, $position);
        $invalidEmail['people'][0]['email'] = 'not-an-email';
        $this->expectValidation(fn () => $this->builder->build($stored, $project, $invalidEmail));
    }

    public function testRejectsPersonalFieldsThatExceedPersistentCrewMemberLimits(): void
    {
        $project = $this->project('rodaje');
        [$department, $position] = $this->filmingAssignment();
        $stored = $this->proposal(
            $project,
            CrewImportPersonProposal::CREATE,
            null,
            $department,
            $position
        );

        $cases = [
            ['name', str_repeat('N', 101)],
            ['lastName', str_repeat('L', 256)],
            ['email', str_repeat('a', 139).'@example.test'],
            ['phone', str_repeat('1', 21)],
        ];

        foreach ($cases as [$field, $value]) {
            $input = $this->input('create', null, $department, $position);
            $input['people'][0][$field] = $value;

            $this->expectValidation(
                fn () => $this->builder->build($stored, $project, $input)
            );
        }
    }

    /** @return array{CrewDepartment, CrewPosition} */
    private function filmingAssignment(): array
    {
        $department = $this->departmentRepository->findOneBy([
            'scope' => CrewDepartment::SCOPE_FILMING,
            'name' => 'ARTE',
        ]);
        self::assertInstanceOf(CrewDepartment::class, $department);
        $position = $this->positionRepository->findOneBy([
            'crewDepartment' => $department,
            'name' => 'Director/a de arte',
        ]);
        self::assertInstanceOf(CrewPosition::class, $position);

        return [$department, $position];
    }

    private function proposal(
        Project $project,
        string $action,
        ?int $memberId,
        CrewDepartment $department,
        CrewPosition $position,
    ): CrewImportProposal {
        return new CrewImportProposal($project->getId(), CrewImportExtraction::OFFICIAL_TEMPLATE, [
            new CrewImportPersonProposal(
                [2],
                'Ana López',
                'Ana',
                'López',
                'ana@example.test',
                '600 111 222',
                $memberId,
                $action,
                in_array($action, [CrewImportPersonProposal::REVIEW, CrewImportPersonProposal::CONFLICT], true),
                $action === CrewImportPersonProposal::CONFLICT ? [CrewImportWarning::PERSON_IDENTITY_CONFLICT] : [],
                [
                    new CrewImportAssignmentProposal(
                        2,
                        'ARTE',
                        'Director/a de arte',
                        $department->getId(),
                        $position->getId(),
                        CrewImportAssignmentProposal::RESOLVED,
                    ),
                ],
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function input(
        string $action,
        ?int $memberId,
        ?CrewDepartment $department,
        ?CrewPosition $position,
    ): array {
        return ['people' => [[
            'include' => '1',
            'name' => ' Ana ',
            'lastName' => ' López ',
            'email' => 'ana@example.test',
            'phone' => '600 111 222',
            'action' => $action,
            'existingCrewMemberId' => $memberId === null ? '' : (string) $memberId,
            'assignments' => [[
                'departmentId' => $department === null ? '' : (string) $department->getId(),
                'positionId' => $position === null ? '' : (string) $position->getId(),
            ]],
        ]]];
    }

    private function project(string $type): Project
    {
        $project = (new Project())
            ->setName('Crew confirmation '.uniqid('', true))
            ->setCountry('ES')
            ->setType($type);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function member(Project $project, string $name): CrewMember
    {
        $member = (new CrewMember())->setName($name);
        $project->addCrewMember($member);
        $this->entityManager->persist($member);

        return $member;
    }

    private function expectValidation(\Closure $callback): void
    {
        try {
            $callback();
            self::fail('Invalid review input must be rejected.');
        } catch (CrewImportReviewValidationException $exception) {
            self::assertNotEmpty($exception->errors);
        }
    }
}
