<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Emission\Energy\EnergyEmissionCalculator;
use App\Service\Emission\Energy\EnergyEmissionInput;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class EnergyEmissionRecordFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATEGORY_NAME = 'Energía';

    public function __construct(
        private readonly EnergyEmissionCalculator $calculator,
        private readonly EnergyEmissionSnapshot $snapshot,
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
            EnergyEmissionFactorFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Category|null $category */
        $category = $manager->getRepository(Category::class)->findOneBy([
            'name' => self::CATEGORY_NAME,
        ]);

        if (!$category) {
            throw new \LogicException('No existe la categoría Energía.');
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
                ['startDate' => 'ASC']
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
                        ->setRegisteredAt($date)
                        ->setNotes($case['notes'])
                        ->setAmount(
                            null === $calculation->normalizedAmount
                                ? null
                                : (float) $calculation->normalizedAmount
                        )
                        ->setEmission(
                            null === $calculation->emissionKgCo2e
                                ? null
                                : (float) $calculation->emissionKgCo2e
                        )
                        ->setStatus($calculation->status)
                        ->setCalculationDetails(
                            $this->snapshot->encode(
                                $input,
                                $calculation,
                                ['fixtureCase' => $case['key']]
                            )
                        );

                    $manager->persist($record);
                }
            }
        }

        $manager->flush();
    }

    /**
     * Casos representativos del contrato funcional Energy V1.
     *
     * @return list<array{
     *     key: string,
     *     notes: string,
     *     input: EnergyEmissionInput
     * }>
     */
    private function cases(\DateTimeImmutable $date): array
    {
        return [
            [
                'key' => 'electricity_grid',
                'notes' => 'Fixture Energy V1 · Electricidad de red',
                'input' => new EnergyEmissionInput(
                    family: EnergyEmissionInput::FAMILY_ELECTRICITY,
                    startDate: $date,
                    endDate: $date,
                    country: 'ES',
                    origin: EnergyEmissionInput::ORIGIN_GRID,
                    amount: '20',
                    unit: 'kWh',
                ),
            ],
            [
                'key' => 'electricity_solar',
                'notes' => 'Fixture Energy V1 · Electricidad solar',
                'input' => new EnergyEmissionInput(
                    family: EnergyEmissionInput::FAMILY_ELECTRICITY,
                    startDate: $date,
                    endDate: $date,
                    country: 'ES',
                    origin: EnergyEmissionInput::ORIGIN_SOLAR,
                    amount: '10',
                    unit: 'kWh',
                ),
            ],
            [
                'key' => 'electricity_mixed',
                'notes' => 'Fixture Energy V1 · Electricidad mixta',
                'input' => new EnergyEmissionInput(
                    family: EnergyEmissionInput::FAMILY_ELECTRICITY,
                    startDate: $date,
                    endDate: $date,
                    country: 'ES',
                    origin: EnergyEmissionInput::ORIGIN_MIXED,
                    amount: '10',
                    unit: 'kWh',
                    gridKwh: '6',
                    solarKwh: '4',
                ),
            ],
            [
                'key' => 'equipment_diesel',
                'notes' => 'Fixture Energy V1 · Generador diésel',
                'input' => new EnergyEmissionInput(
                    family: EnergyEmissionInput::FAMILY_EQUIPMENT,
                    startDate: $date,
                    endDate: $date,
                    country: 'ES',
                    amount: '10',
                    unit: 'litros',
                    equipmentType: 'Generador',
                    fuel: 'Diésel',
                    mode: EnergyEmissionInput::EQUIPMENT_MODE_DIRECT,
                ),
            ],
            [
                'key' => 'battery_grid',
                'notes' => 'Fixture Energy V1 · Batería cargada desde red',
                'input' => new EnergyEmissionInput(
                    family: EnergyEmissionInput::FAMILY_BATTERY,
                    startDate: $date,
                    endDate: $date,
                    country: 'ES',
                    batteryType: 'Estación portátil',
                    chargeSource: EnergyEmissionInput::ORIGIN_GRID,
                    chargedKwh: '10',
                ),
            ],
            [
                'key' => 'digital_not_automatically_calculable',
                'notes' => 'Fixture Energy V1 · IA sin consumo eléctrico conocido',
                'input' => new EnergyEmissionInput(
                    family: EnergyEmissionInput::FAMILY_DIGITAL,
                    startDate: $date,
                    endDate: $date,
                    country: 'ES',
                    digitalType: 'IA',
                    hours: '3',
                    gpu: 'A100',
                ),
            ],
        ];
    }
}
