<?php

namespace App\DataFixtures;

use App\Entity\EmissionRecord;
use App\Entity\EmissionActivity;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use DateTimeImmutable;
use Doctrine\Common\DataFixtures\FixtureGroupInterface;

class EmissionRecordFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * Las categorías migradas al motor moderno deben generar sus registros
     * mediante fixtures específicos, nunca a partir de EmissionActivity legacy.
     */
    private const MODERN_CATEGORIES = [
        'Energía',
    ];

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
            EmissionActivityFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Project $project */
        $projects = $manager->getRepository(Project::class)->findAll();
        if (count($projects)==0) {
            throw new \LogicException('No hay proyecto: ejecuta ProjectFixtures primero.');
        }

        foreach($projects as $project){
            $phases = $manager->getRepository(ProjectPhaseDate::class)->findBy(['project' => $project]);

            // actividades agrupadas por categoría
            $actsByCat = [];
            foreach ($manager->getRepository(EmissionActivity::class)->findAll() as $act) {
                $actsByCat[$act->getCategory()->getName()][] = $act;
            }

            foreach ($phases as $phase) {
                foreach ($actsByCat as $catName => $acts) {
                    // Categorías ya migradas al motor moderno no generan registros legacy.
                    if (in_array($catName, self::MODERN_CATEGORIES, true)) {
                        continue;
                    }

                    $count = 0;
                    $limit = rand(2,4);
                    foreach ($acts as $act) {
                        if ($count >= $limit) break;

                        $createdAt = $this->getRandomDateBetween($phase->getStartDate(), $phase->getEndDate());

                        $rec = new EmissionRecord();
                        $rec->setProject($project)
                            ->setPhase($phase)
                            ->setActivity($act)
                            ->setCategory($act->getCategory());

                        $details = [];
                        $amount = mt_rand(10, 100);

                        $rec->setAmount($amount)
                            ->setEmission(round($amount * $act->getEmissionFactor(), 4))
                            ->setRegisteredAt($createdAt)
                            ->setCalculationDetails($details ? json_encode($details) : null);

                        $manager->persist($rec);
                        $count++;
                    }
                }
            }

            $manager->flush();

        }
    }

    private function getRandomDateBetween(\DateTimeInterface $start, \DateTimeInterface $end): \DateTimeImmutable
    {
        // Garantizar que ambos sean DateTimeImmutable
        $startImmutable = $start instanceof \DateTimeImmutable ? $start : \DateTimeImmutable::createFromMutable($start);
        $endImmutable = $end instanceof \DateTimeImmutable ? $end : \DateTimeImmutable::createFromMutable($end);

        $startTs = $startImmutable->getTimestamp();
        $endTs = $endImmutable->getTimestamp();

        $randomTs = random_int($startTs, $endTs);
        return (new \DateTimeImmutable())->setTimestamp($randomTs);
    }

}
