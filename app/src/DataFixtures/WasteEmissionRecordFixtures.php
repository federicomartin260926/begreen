<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Emission\Waste\WasteEmissionCalculator;
use App\Service\Emission\Waste\WasteEmissionInput;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class WasteEmissionRecordFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATEGORY_NAME = 'Residuos';

    public function __construct(
        private readonly WasteEmissionCalculator $calculator,
        private readonly WasteEmissionSnapshot $snapshot,
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
            WasteEmissionFactorFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Category|null $category */
        $category = $manager->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_NAME]);
        if (!$category) {
            throw new \LogicException('No existe la categoría Residuos.');
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
                        throw new \LogicException(sprintf('Waste fixture case "%s" is unexpectedly pending.', $case['key']));
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

    /** @return list<array{key: string, notes: string, input: WasteEmissionInput}> */
    private function cases(): array
    {
        $date2021 = new \DateTimeImmutable('2021-06-01');
        $date2025 = new \DateTimeImmutable('2025-06-01');
        $date2027 = new \DateTimeImmutable('2027-06-01');

        return [
            [
                'key' => 'spain_occc_versioned',
                'notes' => 'Fixture Waste V1 · España OCCC versionado',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'ESP',
                    'Orgánico (residuos de jardín)', null, 'Compostaje',
                    '10', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'spain_defra_proxy_subactivity',
                'notes' => 'Fixture Waste V1 · España DEFRA proxy con subtipo',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'ESP',
                    'Textil', 'Moquetas', 'Vertedero',
                    '20', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'uk_defra_local',
                'notes' => 'Fixture Waste V1 · Reino Unido DEFRA local',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'GBR',
                    'Residuos domésticos residuales', null, 'Vertedero',
                    '30', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'france_defra_proxy',
                'notes' => 'Fixture Waste V1 · Francia con proxy DEFRA UK',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'FRA',
                    'Residuos domésticos residuales', null, 'Vertedero',
                    '40', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'annual_temporal_fallback',
                'notes' => 'Fixture Waste V1 · fallback temporal DEFRA',
                'input' => new WasteEmissionInput(
                    $date2027, $date2027, 'FRA',
                    'Residuos domésticos residuales', null, 'Vertedero',
                    '50', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'unknown_maximum',
                'notes' => 'Fixture Waste V1 · destino desconocido máximo calculable',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'ESP',
                    'Orgánico (residuos de jardín)', null, 'Desconocido',
                    '10', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'non_waste_zero',
                'notes' => 'Fixture Waste V1 · reutilización/donación no residuo',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'ESP',
                    'Construcción / Set promedio', null, 'Reutilización / Donación',
                    '100', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'tonnes_normalization',
                'notes' => 'Fixture Waste V1 · toneladas normalizadas a kg',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'ESP',
                    'Orgánico (residuos de jardín)', null, 'Compostaje',
                    '1.5', WasteEmissionInput::UNIT_TONNE,
                ),
            ],
            [
                'key' => 'specific_subactivity',
                'notes' => 'Fixture Waste V1 · subtipo Pintura al agua',
                'input' => new WasteEmissionInput(
                    $date2025, $date2025, 'ESP',
                    'Residuos químicos (no peligrosos)', 'Pintura al agua', 'Tratamiento físico-químico y biológico',
                    '12', WasteEmissionInput::UNIT_KG,
                ),
            ],
            [
                'key' => 'not_calculable_before_series',
                'notes' => 'Fixture Waste V1 · sin factor anual anterior disponible',
                'input' => new WasteEmissionInput(
                    $date2021, $date2021, 'FRA',
                    'Residuos domésticos residuales', null, 'Vertedero',
                    '100', WasteEmissionInput::UNIT_KG,
                ),
            ],
        ];
    }
}
