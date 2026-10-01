<?php

namespace App\Tests\Service\CrewImport;

use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Entity\Project;
use App\Exception\CrewImport\MissingCrewCatalogTranslationException;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use App\Service\CrewCatalogScopeResolver;
use App\Service\CrewImport\CrewCatalogContextProvider;
use Doctrine\Persistence\ManagerRegistry;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CrewCatalogContextProviderTest extends KernelTestCase
{
    public function testProvidesOnlyTheProjectScopeWithIdsTranslationsAndDepartmentPositions(): void
    {
        $listener = new TranslatableListener();
        $listener->setTranslatableLocale('es');
        $production = $this->department(10, 'PRODUCCIÓN', CrewDepartment::SCOPE_EVENT);
        $sound = $this->department(20, 'SONIDO', CrewDepartment::SCOPE_EVENT);
        $producer = $this->position(11, 'Productor/a', $production);
        $runner = $this->position(12, 'Runner', $production);
        $soundDesigner = $this->position(21, 'Diseñador/a de sonido', $sound);

        $departmentRepository = $this->createMock(CrewDepartmentRepository::class);
        $departmentRepository->expects(self::once())
            ->method('findByScope')
            ->with(CrewDepartment::SCOPE_EVENT)
            ->willReturn([$production, $sound]);

        $positionRepository = $this->createMock(CrewPositionRepository::class);
        $positionRepository->expects(self::exactly(2))
            ->method('findByCrewDepartment')
            ->willReturnCallback(static fn (CrewDepartment $department): array => match ($department) {
                $production => [$producer, $runner],
                $sound => [$soundDesigner],
            });

        $translations = [
            spl_object_id($production) => 'PRODUCTION',
            spl_object_id($sound) => 'SOUND',
            spl_object_id($producer) => 'Producer',
            spl_object_id($runner) => 'Runner',
            spl_object_id($soundDesigner) => 'Sound Designer',
        ];

        $provider = $this->provider(
            $departmentRepository,
            $positionRepository,
            $translations,
            [10 => 'PRODUCCIÓN', 20 => 'SONIDO'],
            [11 => 'Productor/a', 12 => 'Runner', 21 => 'Diseñador/a de sonido'],
        );

        self::assertSame([
            [
                'id' => 10,
                'name' => ['es' => 'PRODUCCIÓN', 'en' => 'PRODUCTION'],
                'positions' => [
                    ['id' => 11, 'name' => ['es' => 'Productor/a', 'en' => 'Producer']],
                    ['id' => 12, 'name' => ['es' => 'Runner', 'en' => 'Runner']],
                ],
            ],
            [
                'id' => 20,
                'name' => ['es' => 'SONIDO', 'en' => 'SOUND'],
                'positions' => [
                    ['id' => 21, 'name' => ['es' => 'Diseñador/a de sonido', 'en' => 'Sound Designer']],
                ],
            ],
        ], $provider->provide((new Project())->setType('evento')));
        self::assertSame('es', $listener->getListenerLocale());
    }

    public function testUsesCanonicalSpanishNamesWhenEntitiesWereHydratedInEnglish(): void
    {
        $listener = new TranslatableListener();
        $listener->setTranslatableLocale('en');

        $department = $this->department(10, 'PRODUCTION', CrewDepartment::SCOPE_EVENT);
        $position = $this->position(11, 'Stage Manager', $department);

        $departmentRepository = $this->createMock(CrewDepartmentRepository::class);
        $departmentRepository->expects(self::once())
            ->method('findByScope')
            ->with(CrewDepartment::SCOPE_EVENT)
            ->willReturn([$department]);
        $positionRepository = $this->createMock(CrewPositionRepository::class);
        $positionRepository->expects(self::once())
            ->method('findByCrewDepartment')
            ->with($department)
            ->willReturn([$position]);

        $provider = $this->provider(
            $departmentRepository,
            $positionRepository,
            [
                spl_object_id($department) => 'PRODUCTION',
                spl_object_id($position) => 'Stage Manager',
            ],
            [10 => 'PRODUCCIÓN'],
            [11 => 'Regidor/a'],
        );

        self::assertSame([
            [
                'id' => 10,
                'name' => ['es' => 'PRODUCCIÓN', 'en' => 'PRODUCTION'],
                'positions' => [
                    ['id' => 11, 'name' => ['es' => 'Regidor/a', 'en' => 'Stage Manager']],
                ],
            ],
        ], $provider->provide((new Project())->setType('evento')));
        self::assertSame('en', $listener->getListenerLocale());
    }

    public function testFailsWhenAnEnglishTranslationIsMissing(): void
    {
        $department = $this->department(10, 'PRODUCCIÓN', CrewDepartment::SCOPE_FILMING);

        $departmentRepository = $this->createMock(CrewDepartmentRepository::class);
        $departmentRepository->method('findByScope')->willReturn([$department]);
        $positionRepository = $this->createMock(CrewPositionRepository::class);

        $provider = $this->provider(
            $departmentRepository,
            $positionRepository,
            [],
            [10 => 'PRODUCCIÓN'],
            [],
        );

        $this->expectException(MissingCrewCatalogTranslationException::class);
        $this->expectExceptionMessage('ID 10');

        $provider->provide((new Project())->setType('rodaje')->setFilmingGenre('ficcion'));
    }

    public function testRealCatalogIsIdenticalWithSpanishAndEnglishListenerLocales(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $listener = $container->get(TranslatableListener::class);
        $entityManager = $container->get('doctrine')->getManager();
        $provider = new CrewCatalogContextProvider(
            new CrewCatalogScopeResolver(),
            $container->get(CrewDepartmentRepository::class),
            $container->get(CrewPositionRepository::class),
            $container->get('doctrine'),
        );
        $originalLocale = $listener->getListenerLocale();
        $project = (new Project())->setType('evento');

        try {
            $listener->setTranslatableLocale('es');
            $entityManager->clear();
            $spanishLocaleContext = $provider->provide($project);
            self::assertSame('es', $listener->getListenerLocale());

            $listener->setTranslatableLocale('en');
            $entityManager->clear();
            $englishLocaleContext = $provider->provide($project);

            self::assertSame($spanishLocaleContext, $englishLocaleContext);
            self::assertCount(22, $englishLocaleContext);
            self::assertSame(132, array_sum(array_map(
                static fn (array $department): int => count($department['positions']),
                $englishLocaleContext
            )));
            self::assertSame('PRODUCCIÓN', $englishLocaleContext[0]['name']['es']);
            self::assertSame('PRODUCTION', $englishLocaleContext[0]['name']['en']);
            self::assertIsInt($englishLocaleContext[0]['id']);
            self::assertIsInt($englishLocaleContext[0]['positions'][0]['id']);
            self::assertSame('en', $listener->getListenerLocale());
        } finally {
            $listener->setTranslatableLocale($originalLocale);
            $entityManager->clear();
            self::ensureKernelShutdown();
        }
    }

    /**
     * @param array<int, string> $translations
     * @param array<int, string> $departmentNames
     * @param array<int, string> $positionNames
     */
    private function provider(
        CrewDepartmentRepository $departmentRepository,
        CrewPositionRepository $positionRepository,
        array $translations,
        array $departmentNames,
        array $positionNames,
    ): CrewCatalogContextProvider {
        $departmentRepository->method('canonicalName')
            ->willReturnCallback(static fn (int $id): string => $departmentNames[$id]);
        $positionRepository->method('canonicalName')
            ->willReturnCallback(static fn (int $id): string => $positionNames[$id]);

        $translationRepository = $this->createMock(TranslationRepository::class);
        $translationRepository->method('findTranslations')
            ->willReturnCallback(static function (object $entity) use ($translations): array {
                $english = $translations[spl_object_id($entity)] ?? null;

                return $english === null ? [] : ['en' => ['name' => $english]];
            });

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::once())
            ->method('getRepository')
            ->with(Translation::class)
            ->willReturn($translationRepository);

        return new CrewCatalogContextProvider(
            new CrewCatalogScopeResolver(),
            $departmentRepository,
            $positionRepository,
            $doctrine,
        );
    }

    private function department(int $id, string $name, string $scope): CrewDepartment
    {
        $department = (new CrewDepartment())->setName($name)->setScope($scope);
        (new \ReflectionProperty(CrewDepartment::class, 'id'))->setValue($department, $id);

        return $department;
    }

    private function position(int $id, string $name, CrewDepartment $department): CrewPosition
    {
        $position = (new CrewPosition())->setName($name)->setCrewDepartment($department);
        (new \ReflectionProperty(CrewPosition::class, 'id'))->setValue($position, $id);

        return $position;
    }
}
