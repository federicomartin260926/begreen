<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Emission\Accommodation\AccommodationEmissionCalculator;
use App\Service\Emission\Accommodation\AccommodationEmissionInput;
use App\Service\Emission\Accommodation\AccommodationEmissionSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class AccommodationEmissionRecordFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATEGORY_NAME = 'Alojamientos';

    public function __construct(
        private readonly AccommodationEmissionCalculator $calculator,
        private readonly AccommodationEmissionSnapshot $snapshot,
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
            AccommodationEmissionFactorFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Category|null $category */
        $category = $manager->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_NAME]);
        if (!$category) {
            throw new \LogicException('No existe la categoría Alojamientos.');
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
                $phaseDate = \DateTimeImmutable::createFromInterface($phase->getStartDate());
                foreach ($this->cases($phaseDate) as $case) {
                    $input = $case['input'];
                    $calculation = $this->calculator->calculate($input);

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

    /** @return list<array{key: string, notes: string, input: AccommodationEmissionInput}> */
    private function cases(\DateTimeImmutable $phaseDate): array
    {
        $exactDate = new \DateTimeImmutable('2024-06-01');
        $proxyDate = new \DateTimeImmutable('2022-06-01');

        return [
            [
                'key' => 'hotel_exact',
                'notes' => 'Fixture Accommodation V1 · Hotel exacto',
                'input' => new AccommodationEmissionInput($exactDate, $exactDate, 'ESP', AccommodationEmissionInput::TYPE_HOTEL, '4', '2', '3', '4'),
            ],
            [
                'key' => 'hotel_temporal_fallback',
                'notes' => 'Fixture Accommodation V1 · Hotel con fallback temporal',
                'input' => new AccommodationEmissionInput($phaseDate, $phaseDate, 'ESP', AccommodationEmissionInput::TYPE_HOTEL, '4', '1', '2', '2'),
            ],
            [
                'key' => 'hotel_geographic_proxy',
                'notes' => 'Fixture Accommodation V1 · Hotel con proxy geográfico',
                'input' => new AccommodationEmissionInput($proxyDate, $proxyDate, 'AFG', AccommodationEmissionInput::TYPE_HOTEL, '4', '1', '1', '1'),
            ],
            [
                'key' => 'hostel',
                'notes' => 'Fixture Accommodation V1 · Hostal',
                'input' => new AccommodationEmissionInput($phaseDate, $phaseDate, 'ESP', AccommodationEmissionInput::TYPE_HOSTEL, null, null, '3', '2'),
            ],
            [
                'key' => 'apartment',
                'notes' => 'Fixture Accommodation V1 · Apartamento',
                'input' => new AccommodationEmissionInput($phaseDate, $phaseDate, 'ESP', AccommodationEmissionInput::TYPE_APARTMENT, null, null, '2', '3'),
            ],
            [
                'key' => 'other',
                'notes' => 'Fixture Accommodation V1 · Otro',
                'input' => new AccommodationEmissionInput($phaseDate, $phaseDate, 'ESP', AccommodationEmissionInput::TYPE_OTHER, null, null, null, null),
            ],
        ];
    }
}
