<?php

namespace App\DataFixtures;

use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\ProjectPhaseDate;
use App\Entity\ProjectSubscription;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Repository\UserRepository;
use App\Repository\OdsRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;

class ProjectFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private UserRepository $userRepository;
    private OdsRepository $odsRepository;

    public function __construct(UserRepository $userRepository, OdsRepository $odsRepository)
    {
        $this->userRepository = $userRepository;
        $this->odsRepository = $odsRepository; // (no usada aquí, la mantengo por compatibilidad)
    }

    public static function getGroups(): array
    {
        return ['projects'];
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            MeasureFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        // Obtener usuarios existentes
        $users = $this->userRepository->findAll();
        if (count($users) === 0) {
            throw new \RuntimeException('No se encontró ningún usuario en la base de datos.');
        }

        foreach ($users as $index => $user) {
            $userName = $user->getName() ?: $user->getEmail();
            $filmingType = ProjectCatalog::FILMING_TYPES[$index % count(ProjectCatalog::FILMING_TYPES)];
            $distributionMedium = ProjectCatalog::DISTRIBUTION_MEDIA[$index % count(ProjectCatalog::DISTRIBUTION_MEDIA)];

            // Crear proyecto base
            $project = (new Project())
                ->setName("Proyecto $userName")
                ->setCountry('ES')
                ->setType('rodaje')
                ->setFilmingType($filmingType)
                ->setDistributionMedia([$distributionMedium])
                ->setUser($user)
                ->setEmissionSourceName('MITECO'); // Valor por defecto

            if ($filmingType === 'tv_series') {
                $project
                    ->setEpisodios(8)
                    ->setDuracionEpisodio(45);
            }

            foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $commercialPhase) {
                $subscription = (new ProjectSubscription())
                    ->setPhase($commercialPhase)
                    ->setProject($project)
                    ->setTier(ProjectSubscription::TIER_BASIC)
                    ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                    ->setSource(ProjectSubscription::SOURCE_SYSTEM);
                $project->addSubscription($subscription);
            }

            $manager->persist($project);

            // Asignar OWNER (membresía)
            $membership = (new ProjectMembership())
                ->setUser($user)
                ->setProject($project)
                ->setProjectRole('owner');

            $manager->persist($membership);

            // Fases por defecto
            $phase = (new ProjectPhaseDate())
                ->setPhase('preproduccion')
                ->setStartDate(new \DateTime('+1 days'))
                ->setEndDate(new \DateTime('+15 days'))
                ->setProject($project);
            $manager->persist($phase);

            $phase = (new ProjectPhaseDate())
                ->setPhase('actividad')
                ->setStartDate(new \DateTime('+20 days'))
                ->setEndDate(new \DateTime('+60 days'))
                ->setProject($project);
            $manager->persist($phase);

            $phase = (new ProjectPhaseDate())
                ->setPhase('postproduccion')
                ->setStartDate(new \DateTime('+60 days'))
                ->setEndDate(new \DateTime('+65 days'))
                ->setProject($project);
            $manager->persist($phase);
        }

        $manager->flush();
    }
}
