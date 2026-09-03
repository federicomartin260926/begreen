<?php

namespace App\Tests\Form;

use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Entity\Project;
use App\Enum\ProjectCatalog;
use App\Form\CrewMemberCollectionType;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class CrewMemberCollectionTypeTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[DataProvider('projectScopeProvider')]
    public function testProjectExposesOnlyDepartmentsFromResolvedCrewScope(
        string $projectType,
        ?string $filmingGenre,
        string $expectedScope,
        int $expectedDepartments
    ): void {
        $project = $this->project($projectType, $filmingGenre);
        $member = (new CrewMember())->setName('Ana');
        $member->addAssignment(new CrewMemberAssignment());
        $project->addCrewMember($member);

        $form = $this->createCrewForm($project);
        $choices = $form->get('crewMembers')
            ->get(0)
            ->get('assignments')
            ->get(0)
            ->get('crewDepartment')
            ->getConfig()
            ->getOption('choices');

        self::assertCount($expectedDepartments, $choices);
        foreach ($choices as $department) {
            self::assertSame($expectedScope, $department->getScope());
        }
    }

    public static function projectScopeProvider(): iterable
    {
        yield 'rodaje' => ['rodaje', null, CrewDepartment::SCOPE_FILMING, 22];
        yield 'evento' => ['evento', null, CrewDepartment::SCOPE_EVENT, 22];
        yield 'animación' => ['rodaje', ProjectCatalog::FILMING_GENRE_ANIMATION, CrewDepartment::SCOPE_ANIMATION, 25];
    }

    public function testMemberCanBeSubmittedWithoutAssignments(): void
    {
        $form = $this->createCrewForm($this->project('rodaje'));
        $form->submit(['crewMembers' => [$this->memberData([])]]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertCount(1, $form->getData()->getCrewMembers());
        self::assertCount(0, $form->getData()->getCrewMembers()->first()->getAssignments());
        self::assertFalse($form->get('crewMembers')->get(0)->has('department'));
        self::assertFalse($form->get('crewMembers')->get(0)->has('position'));
    }

    public function testMemberCanHaveOneAssignment(): void
    {
        $department = $this->department(CrewDepartment::SCOPE_FILMING, 'ARTE');
        $position = $this->positions($department)[0];
        $form = $this->createCrewForm($this->project('rodaje'));
        $form->submit(['crewMembers' => [$this->memberData([
            $this->assignmentData($department, $position->getId()),
        ])]]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $assignment = $form->getData()->getCrewMembers()->first()->getAssignments()->first();
        self::assertSame($department->getId(), $assignment->getCrewDepartment()?->getId());
        self::assertSame($position->getId(), $assignment->getCrewPosition()?->getId());
    }

    public function testMemberCanHaveAssignmentsFromDifferentDepartments(): void
    {
        $production = $this->department(CrewDepartment::SCOPE_EVENT, 'PRODUCCIÓN');
        $sound = $this->department(CrewDepartment::SCOPE_EVENT, 'SONIDO');
        $form = $this->createCrewForm($this->project('evento'));
        $form->submit(['crewMembers' => [$this->memberData([
            $this->assignmentData($production, $this->positions($production)[0]->getId()),
            $this->assignmentData($sound, $this->positions($sound)[0]->getId()),
        ])]]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertCount(2, $form->getData()->getCrewMembers()->first()->getAssignments());
    }

    public function testMemberCanHaveDifferentPositionsFromSameDepartment(): void
    {
        $department = $this->department(CrewDepartment::SCOPE_ANIMATION, 'PRODUCCIÓN');
        $positions = $this->positions($department);
        $form = $this->createCrewForm($this->project('rodaje', ProjectCatalog::FILMING_GENRE_ANIMATION));
        $form->submit(['crewMembers' => [$this->memberData([
            $this->assignmentData($department, $positions[0]->getId()),
            $this->assignmentData($department, $positions[1]->getId()),
        ])]]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertCount(2, $form->getData()->getCrewMembers()->first()->getAssignments());
    }

    public function testRejectsDepartmentFromAnotherScope(): void
    {
        $filmingDepartment = $this->department(CrewDepartment::SCOPE_FILMING, 'ARTE');
        $form = $this->createCrewForm($this->project('evento'));
        $form->submit(['crewMembers' => [$this->memberData([
            $this->assignmentData($filmingDepartment),
        ])]]);

        $departmentField = $form->get('crewMembers')->get(0)->get('assignments')->get(0)->get('crewDepartment');
        self::assertFalse($departmentField->isValid());
        self::assertFalse($form->isValid());
    }

    private function createCrewForm(Project $project): FormInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = self::getContainer()->get(FormFactoryInterface::class);

        return $factory->create(CrewMemberCollectionType::class, $project, ['csrf_protection' => false]);
    }

    private function project(string $type, ?string $filmingGenre = null): Project
    {
        $project = (new Project())
            ->setName('Proyecto de prueba')
            ->setCountry('ES')
            ->setType($type)
            ->setFilmingGenre($filmingGenre);

        if ($type === 'rodaje') {
            $project
                ->setFilmingType('short')
                ->setDistributionMedia(['cinema']);
        } else {
            $project
                ->setEventTypePrimary('cultural')
                ->setEventModality('presencial')
                ->setEventAttendeesCount(1);
        }

        return $project;
    }

    private function department(string $scope, string $name): CrewDepartment
    {
        /** @var CrewDepartmentRepository $repository */
        $repository = self::getContainer()->get(CrewDepartmentRepository::class);
        $department = $repository->findOneBy(['scope' => $scope, 'name' => $name]);
        self::assertInstanceOf(CrewDepartment::class, $department);

        return $department;
    }

    private function positions(CrewDepartment $department): array
    {
        /** @var CrewPositionRepository $repository */
        $repository = self::getContainer()->get(CrewPositionRepository::class);

        return $repository->findByCrewDepartment($department);
    }

    private function memberData(array $assignments): array
    {
        return [
            'name' => 'Ana',
            'lastName' => 'López',
            'email' => 'ana@example.com',
            'phone' => '',
            'assignments' => $assignments,
        ];
    }

    private function assignmentData(CrewDepartment $department, ?int $positionId = null): array
    {
        return [
            'crewDepartment' => (string) $department->getId(),
            'crewPosition' => $positionId ? (string) $positionId : '',
        ];
    }
}
