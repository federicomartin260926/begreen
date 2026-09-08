<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Emission\Water\WaterEmissionCalculator;
use App\Service\Emission\Water\WaterEmissionInput;
use App\Service\Emission\Water\WaterEmissionSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class WaterEmissionRecordFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATEGORY_NAME = 'Agua';

    public function __construct(
        private readonly WaterEmissionCalculator $calculator,
        private readonly WaterEmissionSnapshot $snapshot,
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
            WaterEmissionFactorFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Category|null $category */
        $category = $manager->getRepository(Category::class)->findOneBy([
            'name' => self::CATEGORY_NAME,
        ]);
        if (!$category) {
            throw new \LogicException('No existe la categoría Agua.');
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

                    $record = (new EmissionRecord())
                        ->setProject($project)
                        ->setPhase($phase)
                        ->setCategory($category)
                        ->setActivity(null)
                        ->setRegisteredAt($date)
                        ->setNotes($case['notes'])
                        ->setAmount(null === $calculation->normalizedAmount ? null : (float) $calculation->normalizedAmount)
                        ->setEmission(null === $calculation->emissionKgCo2e ? null : (float) $calculation->emissionKgCo2e)
                        ->setStatus($calculation->status)
                        ->setCalculationDetails($this->snapshot->encode(
                            $input,
                            $calculation,
                            ['fixtureCase' => $case['key']],
                        ));

                    $manager->persist($record);
                }
            }
        }

        $manager->flush();
    }

    /**
     * @return list<array{key: string, notes: string, input: WaterEmissionInput}>
     */
    private function cases(\DateTimeImmutable $date): array
    {
        return [
            [
                'key' => 'es_sewer',
                'notes' => 'Fixture Water V1 · ES alcantarillado',
                'input' => new WaterEmissionInput(
                    $date,
                    $date,
                    'ES',
                    WaterEmissionInput::USE_CLEANING,
                    '10',
                    WaterEmissionInput::UNIT_CUBIC_METRES,
                    WaterEmissionInput::DESTINATION_SEWER,
                ),
            ],
            [
                'key' => 'gb_sewer_supply_treatment',
                'notes' => 'Fixture Water V1 · GB suministro y tratamiento',
                'input' => new WaterEmissionInput(
                    $date,
                    $date,
                    'GB',
                    WaterEmissionInput::USE_SANITARY,
                    '5',
                    WaterEmissionInput::UNIT_CUBIC_METRES,
                    WaterEmissionInput::DESTINATION_SEWER,
                ),
            ],
            [
                'key' => 'es_irrigation_uk_proxy',
                'notes' => 'Fixture Water V1 · ES riego con proxy UK',
                'input' => new WaterEmissionInput(
                    $date,
                    $date,
                    'ES',
                    WaterEmissionInput::USE_IRRIGATION,
                    '2',
                    WaterEmissionInput::UNIT_CUBIC_METRES,
                    WaterEmissionInput::DESTINATION_IRRIGATION,
                ),
            ],
            [
                'key' => 'fr_sewer_uk_proxy',
                'notes' => 'Fixture Water V1 · FR alcantarillado con proxy UK',
                'input' => new WaterEmissionInput(
                    $date,
                    $date,
                    'FR',
                    WaterEmissionInput::USE_PROCESS,
                    '3',
                    WaterEmissionInput::UNIT_CUBIC_METRES,
                    WaterEmissionInput::DESTINATION_SEWER,
                ),
            ],
            [
                'key' => 'gb_litres_normalization',
                'notes' => 'Fixture Water V1 · Volumen expresado en litros',
                'input' => new WaterEmissionInput(
                    $date,
                    $date,
                    'GB',
                    WaterEmissionInput::USE_SHOWERS,
                    '750',
                    WaterEmissionInput::UNIT_LITRES,
                    WaterEmissionInput::DESTINATION_UNKNOWN,
                ),
            ],
        ];
    }
}
