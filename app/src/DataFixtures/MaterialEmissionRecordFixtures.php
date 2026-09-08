<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Emission\Material\MaterialEmissionCalculator;
use App\Service\Emission\Material\MaterialEmissionInput;
use App\Service\Emission\Material\MaterialEmissionSnapshot;
use App\Service\Emission\Material\MaterialUiCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class MaterialEmissionRecordFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATEGORY_NAME = 'Materiales';

    public function __construct(
        private readonly MaterialEmissionCalculator $calculator,
        private readonly MaterialEmissionSnapshot $snapshot,
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
            MaterialEmissionFactorFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Category|null $category */
        $category = $manager->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_NAME]);
        if (!$category) {
            throw new \LogicException('No existe la categoría Materiales.');
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
                foreach ($this->cases() as $case) {
                    $input = $case['input'];
                    $calculation = $this->calculator->calculate($input);

                    if (EmissionRecord::STATUS_PENDING_DATA === $calculation->status) {
                        throw new \LogicException(sprintf(
                            'Material fixture case "%s" is unexpectedly pending.',
                            $case['key'],
                        ));
                    }

                    $manager->persist((new EmissionRecord())
                        ->setProject($project)
                        ->setPhase($phase)
                        ->setCategory($category)
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

    /** @return list<array{key: string, notes: string, input: MaterialEmissionInput}> */
    private function cases(): array
    {
        return [
            [
                'key' => 'direct_weight',
                'notes' => 'Fixture Material V1 · peso directo',
                'input' => new MaterialEmissionInput(
                    startDate: new \DateTimeImmutable('2022-01-01'),
                    endDate: new \DateTimeImmutable('2022-12-31'),
                    country: 'ESP',
                    activity: MaterialUiCatalog::ACTIVITY_WOOD,
                    origin: 'Producción de materia prima',
                    measurementMethod: MaterialEmissionInput::METHOD_WEIGHT,
                    inputQuantity: '100',
                    inputUnit: 'kg',
                ),
            ],
            [
                'key' => 'wood_dimensions',
                'notes' => 'Fixture Material V1 · madera por dimensiones',
                'input' => new MaterialEmissionInput(
                    startDate: new \DateTimeImmutable('2026-01-01'),
                    endDate: new \DateTimeImmutable('2026-12-31'),
                    country: 'ESP',
                    activity: MaterialUiCatalog::ACTIVITY_WOOD,
                    origin: 'Producción de materia prima',
                    measurementMethod: MaterialEmissionInput::METHOD_DIMENSIONS,
                    boardFamily: 'DM o MDF',
                    boardThickness: '12 mm',
                    unitCount: '10',
                ),
            ],
            [
                'key' => 'paper_packages',
                'notes' => 'Fixture Material V1 · papel por paquetes',
                'input' => new MaterialEmissionInput(
                    startDate: new \DateTimeImmutable('2026-01-01'),
                    endDate: new \DateTimeImmutable('2026-12-31'),
                    country: 'ESP',
                    activity: MaterialUiCatalog::ACTIVITY_PAPER,
                    origin: 'Producción de materia prima',
                    measurementMethod: MaterialEmissionInput::METHOD_PACKAGES,
                    inputQuantity: '3',
                    paperFormat: 'A4 (210 x 297)',
                ),
            ],
            [
                'key' => 'battery_versioned',
                'notes' => 'Fixture Material V1 · batería por unidades',
                'input' => new MaterialEmissionInput(
                    startDate: new \DateTimeImmutable('2024-01-01'),
                    endDate: new \DateTimeImmutable('2024-12-31'),
                    country: 'ESP',
                    activity: MaterialUiCatalog::ACTIVITY_BATTERIES,
                    subproduct: 'Pila/batería de Ion de litio',
                    origin: 'Producción de materia prima',
                    measurementMethod: MaterialEmissionInput::METHOD_UNITS,
                    unitCount: '10',
                    batteryChemistry: 'Litio-Ion',
                    batterySize: 'AA',
                ),
            ],
            [
                'key' => 'paint_volume',
                'notes' => 'Fixture Material V1 · pintura por volumen',
                'input' => new MaterialEmissionInput(
                    startDate: new \DateTimeImmutable('2026-01-01'),
                    endDate: new \DateTimeImmutable('2026-12-31'),
                    country: 'ESP',
                    activity: MaterialUiCatalog::ACTIVITY_PAINT,
                    subproduct: 'Pintura con base al agua',
                    origin: '',
                    measurementMethod: MaterialEmissionInput::METHOD_VOLUME,
                    inputQuantity: '10',
                    inputUnit: 'l',
                ),
            ],
            [
                'key' => 'reuse_rule_zero',
                'notes' => 'Fixture Material V1 · reutilización RULE cero',
                'input' => new MaterialEmissionInput(
                    startDate: new \DateTimeImmutable('2026-01-01'),
                    endDate: new \DateTimeImmutable('2026-12-31'),
                    country: 'ESP',
                    activity: MaterialUiCatalog::ACTIVITY_PAPER,
                    origin: 'Reutilizado',
                    measurementMethod: MaterialEmissionInput::METHOD_WEIGHT,
                    inputQuantity: '12',
                    inputUnit: 'kg',
                ),
            ],
            [
                'key' => 'reused_plastic_not_calculable',
                'notes' => 'Fixture Material V1 · plástico reutilizado no calculable',
                'input' => new MaterialEmissionInput(
                    startDate: new \DateTimeImmutable('2026-01-01'),
                    endDate: new \DateTimeImmutable('2026-12-31'),
                    country: 'ESP',
                    activity: 'Plástico promedio',
                    origin: 'Reutilizado',
                    measurementMethod: MaterialEmissionInput::METHOD_WEIGHT,
                    inputQuantity: '10',
                    inputUnit: 'kg',
                ),
            ],
        ];
    }
}
