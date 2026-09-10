<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Emission\Transport\TransportEmissionCalculator;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class TransportEmissionRecordFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATEGORY_NAME = 'Transporte';

    public function __construct(
        private readonly TransportEmissionCalculator $calculator,
        private readonly TransportEmissionSnapshot $snapshot,
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
            TransportEmissionFactorFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Category|null $category */
        $category = $manager->getRepository(Category::class)->findOneBy([
            'name' => self::CATEGORY_NAME,
        ]);
        if (!$category) {
            throw new \LogicException('No existe la categoría Transporte.');
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
                    if (null === $calculation->normalizedActivityValue || null === $calculation->generatedKgCo2e) {
                        throw new \LogicException(sprintf('El caso fixture de Transporte "%s" no es calculable.', $case['key']));
                    }

                    $record = (new EmissionRecord())
                        ->setProject($project)
                        ->setPhase($phase)
                        ->setCategory($category)
                        ->setRegisteredAt($date)
                        ->setNotes($case['notes'])
                        ->setAmount((float) $calculation->normalizedActivityValue)
                        ->setEmission((float) $calculation->generatedKgCo2e)
                        ->setStatus(EmissionRecord::STATUS_CALCULATED)
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
     * @return list<array{key: string, notes: string, input: TransportEmissionInput}>
     */
    private function cases(\DateTimeImmutable $date): array
    {
        return [
            [
                'key' => 'taxi_petrol',
                'notes' => 'Fixture Transporte v20 · Taxi gasolina',
                'input' => new TransportEmissionInput(
                    'local',
                    'taxi',
                    'route',
                    'ES',
                    $date,
                    $date,
                    '12',
                    'km',
                    vehicleType: 'petrol',
                ),
            ],
            [
                'key' => 'metro_passenger_distance',
                'notes' => 'Fixture Transporte v20 · Metro pasajero-km',
                'input' => new TransportEmissionInput(
                    'local',
                    'metro',
                    'passenger_distance',
                    'ES',
                    $date,
                    $date,
                    '20',
                    'passenger-km',
                ),
            ],
            [
                'key' => 'operator_direct',
                'notes' => 'Fixture Transporte v20 · Emisiones directas del operador',
                'input' => new TransportEmissionInput(
                    'local',
                    'taxi',
                    'operator',
                    'ES',
                    $date,
                    $date,
                    '3',
                    'kg_co2e',
                ),
            ],
        ];
    }
}
