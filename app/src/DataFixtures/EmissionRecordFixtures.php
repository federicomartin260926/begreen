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
                    $count = 0;
                    $limit = rand(2,4);
                    foreach ($acts as $act) {
                        if ($count >= $limit) break;

                        $createdAt = $this->getRandomDateBetween($phase->getStartDate(), $phase->getEndDate());

                        $rec = new EmissionRecord();
                        $rec->setProject($project)
                            ->setPhase($phase)
                            ->setActivity($act);

                        if ($catName === 'Energía'){
                            if (stripos($act->getName(), 'Electricidad') !== false) {
                                $details = [
                                    'subCategory'               => 'electricidad',
                                    'electricityMethod'         => 'contador',
                                    'contador_localizacion'     => 'Estudio A',
                                    'contador_fecha_lectura'    => $createdAt->format('Y-m-d'),
                                    'contador_comercializadora' => 'Iberdrola',
                                    'contador_tarifa'           => 'renovable',
                                    'contador_lectura_inicial'  => 10234,
                                    'contador_lectura_final'    => 10450,
                                ];
                                $amount = $details['contador_lectura_final'] - $details['contador_lectura_inicial'];
                            } elseif (stripos($act->getName(), 'Bombona') !== false) {
                                $details = [
                                    'subCategory'             => 'gas_bombona',
                                    'bombona_nombre_espacio'  => 'Cocina catering',
                                    'bombona_periodo_uso'     => 'Rodaje 3 días',
                                    'bombona_kg'              => 12.5,
                                    'bombona_cantidad'        => 4,
                                ];
                                $amount = $details['bombona_kg'] * $details['bombona_cantidad'];
                            }
                            else{
                                continue;
                            }
                        }
                        else{
                            // Solo categorías distintas a Energía
                            $details = [];
                            $amount  = mt_rand(10, 100);
                        }

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
