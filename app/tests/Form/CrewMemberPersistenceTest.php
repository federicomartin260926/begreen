<?php

namespace App\Tests\Form;

use App\Entity\CrewDepartment;
use App\Entity\Project;
use App\Form\CrewMemberCollectionType;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class CrewMemberPersistenceTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->formFactory = $container->get(FormFactoryInterface::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAssignmentsPersistReloadAndCanBeRemovedWithoutRemovingMember(): void
    {
        /** @var CrewDepartmentRepository $departmentRepository */
        $departmentRepository = self::getContainer()->get(CrewDepartmentRepository::class);
        /** @var CrewPositionRepository $positionRepository */
        $positionRepository = self::getContainer()->get(CrewPositionRepository::class);
        $art = $departmentRepository->findOneBy(['scope' => CrewDepartment::SCOPE_FILMING, 'name' => 'ARTE']);
        $production = $departmentRepository->findOneBy(['scope' => CrewDepartment::SCOPE_FILMING, 'name' => 'PRODUCCIÓN']);
        self::assertInstanceOf(CrewDepartment::class, $art);
        self::assertInstanceOf(CrewDepartment::class, $production);
        $artPositions = $positionRepository->findByCrewDepartment($art);
        $productionPositions = $positionRepository->findByCrewDepartment($production);

        $project = (new Project())
            ->setName('Crew persistence test')
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType('short')
            ->setDistributionMedia(['cinema']);

        $this->submit($project, [
            $this->assignmentData($art->getId(), $artPositions[0]->getId()),
            $this->assignmentData($production->getId(), $productionPositions[0]->getId()),
        ]);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $projectId = $project->getId();
        $this->entityManager->clear();

        $project = $this->reloadProject($projectId);
        $member = $project->getCrewMembers()->first();
        self::assertCount(2, $member->getAssignments());

        $this->submit($project, [
            $this->assignmentData($art->getId(), $artPositions[0]->getId()),
            $this->assignmentData($art->getId(), $artPositions[1]->getId()),
        ]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $project = $this->reloadProject($projectId);
        self::assertCount(2, $project->getCrewMembers()->first()->getAssignments());

        $this->submit($project, [
            $this->assignmentData($art->getId(), $artPositions[0]->getId()),
        ]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $project = $this->reloadProject($projectId);
        self::assertCount(1, $project->getCrewMembers());
        self::assertCount(1, $project->getCrewMembers()->first()->getAssignments());

        $this->submit($project, []);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $project = $this->reloadProject($projectId);
        self::assertCount(1, $project->getCrewMembers());
        self::assertCount(0, $project->getCrewMembers()->first()->getAssignments());
    }

    private function submit(Project $project, array $assignments): void
    {
        $form = $this->formFactory->create(CrewMemberCollectionType::class, $project, ['csrf_protection' => false]);
        $form->submit(['crewMembers' => [[
            'name' => 'Ana',
            'lastName' => 'López',
            'email' => 'ana@example.com',
            'phone' => '',
            'assignments' => $assignments,
        ]]]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    private function assignmentData(?int $departmentId, ?int $positionId): array
    {
        return [
            'crewDepartment' => (string) $departmentId,
            'crewPosition' => (string) $positionId,
        ];
    }

    private function reloadProject(?int $projectId): Project
    {
        $project = $this->entityManager->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $project);

        return $project;
    }
}
