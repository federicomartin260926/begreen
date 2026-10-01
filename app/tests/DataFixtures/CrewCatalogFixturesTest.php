<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\CrewCatalogFixtures;
use App\DataFixtures\CrewCatalogEnglish;
use App\DataFixtures\MeasureDepartmentFixtures;
use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Entity\Department;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;
use PHPUnit\Framework\TestCase;

final class CrewCatalogFixturesTest extends TestCase
{
    private const EXPECTED_TOTALS = [
        CrewDepartment::SCOPE_FILMING => ['departments' => 22, 'positions' => 202],
        CrewDepartment::SCOPE_EVENT => ['departments' => 22, 'positions' => 132],
        CrewDepartment::SCOPE_ANIMATION => ['departments' => 25, 'positions' => 169],
    ];

    private const EXPECTED_MAPPING_TOTALS = [
        CrewDepartment::SCOPE_FILMING => 22,
        CrewDepartment::SCOPE_EVENT => 18,
        CrewDepartment::SCOPE_ANIMATION => 9,
    ];

    public function testLoadsOfficialCatalogWithoutDuplicatesAndWithExpectedTotals(): void
    {
        $departments = [];
        $measureDepartments = [];
        $englishTranslations = [];
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use (&$measureDepartments): Department {
                self::assertSame(['projectType', 'name'], array_keys($criteria));
                self::assertContains($criteria['projectType'], [
                    CrewDepartment::SCOPE_FILMING,
                    CrewDepartment::SCOPE_EVENT,
                ]);

                $key = $criteria['projectType'].'|'.$criteria['name'];

                return $measureDepartments[$key] ??= (new Department())
                    ->setProjectType($criteria['projectType'])
                    ->setName($criteria['name']);
            });

        $manager = $this->createMock(ObjectManager::class);
        $translationRepository = $this->createMock(TranslationRepository::class);
        $translationRepository->expects(self::exactly(572))
            ->method('translate')
            ->willReturnCallback(function (object $entity, string $field, string $locale, ?string $value) use (&$englishTranslations): void {
                self::assertContains($entity::class, [CrewDepartment::class, CrewPosition::class]);
                self::assertSame('name', $field);
                self::assertSame('en', $locale);
                self::assertNotSame('', trim((string) $value));
                self::assertArrayNotHasKey(spl_object_id($entity), $englishTranslations);
                $englishTranslations[spl_object_id($entity)] = $value;
            });

        $manager->expects(self::exactly(2))
            ->method('getRepository')
            ->willReturnMap([
                [Department::class, $repository],
                [Translation::class, $translationRepository],
            ]);
        $manager->expects(self::exactly(69))
            ->method('persist')
            ->with(self::callback(function (object $entity) use (&$departments): bool {
                self::assertInstanceOf(CrewDepartment::class, $entity);
                $departments[] = $entity;

                return true;
            }));
        $manager->expects(self::once())->method('flush');

        (new CrewCatalogFixtures())->load($manager);

        $totals = [];
        $departmentNames = [];
        $nextSortOrder = [];
        $departmentsByScopeAndName = [];
        $mappingTotals = [];

        foreach ($departments as $department) {
            $scope = $department->getScope();
            self::assertArrayHasKey($scope, self::EXPECTED_TOTALS);

            $totals[$scope] ??= ['departments' => 0, 'positions' => 0];
            $departmentNames[$scope] ??= [];
            $nextSortOrder[$scope] ??= 10;

            self::assertArrayNotHasKey($department->getName(), $departmentNames[$scope]);
            self::assertSame($nextSortOrder[$scope], $department->getSortOrder());
            self::assertArrayHasKey(spl_object_id($department), $englishTranslations);

            $departmentNames[$scope][$department->getName()] = true;
            $departmentsByScopeAndName[$scope][$department->getName()] = $department;
            $nextSortOrder[$scope] += 10;
            ++$totals[$scope]['departments'];
            $mappingTotals[$scope] = ($mappingTotals[$scope] ?? 0)
                + $department->getCompatibleMeasureDepartments()->count();

            foreach ($department->getCompatibleMeasureDepartments() as $measureDepartment) {
                self::assertSame(
                    CrewDepartment::SCOPE_EVENT === $scope
                        ? CrewDepartment::SCOPE_EVENT
                        : CrewDepartment::SCOPE_FILMING,
                    $measureDepartment->getProjectType()
                );
            }

            $positionNames = [];
            $nextPositionSortOrder = 10;
            foreach ($department->getPositions() as $position) {
                self::assertSame($department, $position->getCrewDepartment());
                self::assertArrayNotHasKey($position->getName(), $positionNames);
                self::assertSame($nextPositionSortOrder, $position->getSortOrder());
                self::assertArrayHasKey(spl_object_id($position), $englishTranslations);

                $positionNames[$position->getName()] = true;
                $nextPositionSortOrder += 10;
                ++$totals[$scope]['positions'];
            }
        }

        self::assertSame(self::EXPECTED_TOTALS, $totals);
        self::assertSame(self::EXPECTED_MAPPING_TOTALS, $mappingTotals);
        self::assertCount(69 + 503, $englishTranslations);

        self::assertSame(
            'Arte',
            $departmentsByScopeAndName[CrewDepartment::SCOPE_FILMING]['ARTE']
                ->getCompatibleMeasureDepartments()->first()?->getName()
        );
        self::assertSame(
            'Guion y Dirección',
            $departmentsByScopeAndName[CrewDepartment::SCOPE_FILMING]['GUION']
                ->getCompatibleMeasureDepartments()->first()?->getName()
        );
        self::assertSame(
            'Producción',
            $departmentsByScopeAndName[CrewDepartment::SCOPE_EVENT]['PRODUCCIÓN']
                ->getCompatibleMeasureDepartments()->first()?->getName()
        );
        self::assertSame(
            'Técnica (Sonido, Luz, AV)',
            $departmentsByScopeAndName[CrewDepartment::SCOPE_EVENT]['ILUMINACIÓN']
                ->getCompatibleMeasureDepartments()->first()?->getName()
        );
        self::assertSame(
            'Guion y Dirección',
            $departmentsByScopeAndName[CrewDepartment::SCOPE_ANIMATION]['DESARROLLO Y GUION']
                ->getCompatibleMeasureDepartments()->first()?->getName()
        );
        self::assertCount(
            0,
            $departmentsByScopeAndName[CrewDepartment::SCOPE_EVENT]['DIRECCIÓN Y SHOW']
                ->getCompatibleMeasureDepartments()
        );
        self::assertCount(
            0,
            $departmentsByScopeAndName[CrewDepartment::SCOPE_EVENT]['VESTUARIO E IMAGEN']
                ->getCompatibleMeasureDepartments()
        );
        self::assertSame(
            $departmentsByScopeAndName[CrewDepartment::SCOPE_EVENT]['ILUMINACIÓN']
                ->getCompatibleMeasureDepartments()->first(),
            $departmentsByScopeAndName[CrewDepartment::SCOPE_EVENT]['SONIDO']
                ->getCompatibleMeasureDepartments()->first()
        );

        $this->assertDocumentOrder(
            $departmentsByScopeAndName[CrewDepartment::SCOPE_FILMING]['ARTE'],
            'Diseñador/a de producción',
            'Director/a de arte',
            'Concept art'
        );
        $this->assertDocumentOrder(
            $departmentsByScopeAndName[CrewDepartment::SCOPE_EVENT]['PRODUCCIÓN'],
            'Productor/a ejecutivo/a',
            'Productor/a',
            'Runner'
        );
        $this->assertDocumentOrder(
            $departmentsByScopeAndName[CrewDepartment::SCOPE_ANIMATION]['DESARROLLO Y GUION'],
            'Guionista',
            'Coordinador/a de guion',
            'Documentalista'
        );
    }

    public function testDependsOnMeasureDepartmentsFixture(): void
    {
        self::assertSame(
            [MeasureDepartmentFixtures::class],
            (new CrewCatalogFixtures())->getDependencies()
        );
    }

    public function testRepeatedPositionTranslationsCanBeOverriddenByContext(): void
    {
        self::assertSame(
            'Floor Manager',
            CrewCatalogEnglish::position(CrewDepartment::SCOPE_FILMING, 'PRODUCCIÓN', 'Regidor/a')
        );
        self::assertSame(
            'Stage Manager',
            CrewCatalogEnglish::position(CrewDepartment::SCOPE_EVENT, 'DIRECCIÓN Y SHOW', 'Regidor/a')
        );
        self::assertSame(
            'Composer',
            CrewCatalogEnglish::position(CrewDepartment::SCOPE_ANIMATION, 'SONIDO Y MÚSICA', 'Compositor/a')
        );
        self::assertSame(
            'Compositor',
            CrewCatalogEnglish::position(
                CrewDepartment::SCOPE_ANIMATION,
                'ILUMINACIÓN, RENDER Y COMPOSICIÓN',
                'Compositor/a'
            )
        );
    }

    private function assertDocumentOrder(
        CrewDepartment $department,
        string $firstName,
        string $secondName,
        string $lastName
    ): void {
        $positions = $department->getPositions()->toArray();

        self::assertSame($firstName, $positions[0]->getName());
        self::assertSame(10, $positions[0]->getSortOrder());
        self::assertSame($secondName, $positions[1]->getName());
        self::assertSame(20, $positions[1]->getSortOrder());
        self::assertSame($lastName, $positions[array_key_last($positions)]->getName());
        self::assertSame(count($positions) * 10, $positions[array_key_last($positions)]->getSortOrder());
    }
}
