<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Emission\Catering\CateringEmissionCalculator;
use App\Service\Emission\Catering\CateringEmissionInput;
use App\Service\Emission\Catering\CateringEmissionSnapshot;
use App\Service\Emission\Catering\CateringMenuLine;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class CateringEmissionRecordFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATEGORY_NAME = 'Catering';

    public function __construct(
        private readonly CateringEmissionCalculator $calculator,
        private readonly CateringEmissionSnapshot $snapshot,
    ) {
    }

    public static function getGroups(): array
    {
        return ['emission'];
    }

    public function getDependencies(): array
    {
        return [
            AuxiliaryFixtures::class,
            ProjectFixtures::class,
            CateringEmissionFactorFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Category|null $category */
        $category = $manager->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_NAME]);
        if (!$category) {
            throw new \LogicException('No existe la categoría Catering.');
        }

        /** @var list<Project> $projects */
        $projects = $manager->getRepository(Project::class)->findAll();
        if ([] === $projects) {
            throw new \LogicException('No hay proyectos: ejecuta ProjectFixtures primero.');
        }

        foreach ($projects as $project) {
            /** @var list<ProjectPhaseDate> $phases */
            $phases = $manager->getRepository(ProjectPhaseDate::class)->findBy(
                ['project' => $project],
                ['startDate' => 'ASC'],
            );

            foreach ($phases as $phase) {
                $date = \DateTimeImmutable::createFromInterface($phase->getStartDate());

                foreach ($this->cases($date) as $case) {
                    $input = $case['input'];
                    $calculation = $this->calculator->calculate($input);

                    if (EmissionRecord::STATUS_PENDING_DATA === $calculation->status) {
                        throw new \LogicException(sprintf(
                            'El fixture moderno de Catering "%s" contiene datos incompletos.',
                            $case['key'],
                        ));
                    }

                    $manager->persist((new EmissionRecord())
                        ->setProject($project)
                        ->setPhase($phase)
                        ->setCategory($category)
                        ->setActivity(null)
                        ->setRegisteredAt(\DateTimeImmutable::createFromInterface($input->startDate))
                        ->setNotes($case['notes'])
                        ->setAmount(null === $calculation->normalizedAmount ? null : (float) $calculation->normalizedAmount)
                        ->setEmission(null === $calculation->emissionKgCo2e ? null : (float) $calculation->emissionKgCo2e)
                        ->setStatus($calculation->status)
                        ->setCalculationDetails($this->snapshot->encode(
                            $input,
                            $calculation,
                            ['fixtureCase' => $case['key']],
                        )));
                }
            }
        }

        $manager->flush();
    }

    /** @return list<array{key: string, notes: string, input: CateringEmissionInput}> */
    private function cases(\DateTimeImmutable $date): array
    {
        return [
            [
                'key' => 'meal_compostable',
                'notes' => 'Fixture Catering V1 · Menú vegano con vajilla compostable',
                'input' => new CateringEmissionInput(
                    $date,
                    $date,
                    'ESP',
                    CateringEmissionInput::TYPE_MEAL,
                    menuLines: [new CateringMenuLine('vegan', '100', '90')],
                    tablewareType: 'compostable',
                ),
            ],
            [
                'key' => 'meal_reusable',
                'notes' => 'Fixture Catering V1 · Menú vegano con vajilla reutilizable',
                'input' => new CateringEmissionInput(
                    $date,
                    $date,
                    'ESP',
                    CateringEmissionInput::TYPE_MEAL,
                    menuLines: [new CateringMenuLine('vegan', '100', '90')],
                    tablewareType: 'reusable',
                ),
            ],
            [
                'key' => 'drink',
                'notes' => 'Fixture Catering V1 · Bebida sin factor automático',
                'input' => new CateringEmissionInput(
                    $date,
                    $date,
                    'ESP',
                    CateringEmissionInput::TYPE_DRINK,
                    description: 'Refresco',
                    unitCount: '12',
                    litersPerUnit: '0.33',
                ),
            ],
            [
                'key' => 'gas',
                'notes' => 'Fixture Catering V1 · Gas sin factor automático',
                'input' => new CateringEmissionInput(
                    $date,
                    $date,
                    'ESP',
                    CateringEmissionInput::TYPE_GAS,
                    gasType: 'propane',
                    cylinderCount: '2',
                    kgPerCylinder: '11',
                ),
            ],
        ];
    }
}
