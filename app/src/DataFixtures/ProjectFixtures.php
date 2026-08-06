<?php

namespace App\DataFixtures;

use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\ProjectPhaseDate;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Enum\ProjectCatalog;
use App\Repository\OdsRepository;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ProjectFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const FRANC_EMAIL = 'francplanas@gmail.com';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OdsRepository $odsRepository,
    ) {
        // OdsRepository se mantiene por compatibilidad con la definición actual del servicio.
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
        $users = $this->userRepository->findAll();
        if ($users === []) {
            throw new \RuntimeException('No se encontró ningún usuario en la base de datos.');
        }

        foreach ($users as $index => $user) {
            if (!$user instanceof User) {
                continue;
            }

            if ($user->getEmail() === self::FRANC_EMAIL) {
                $this->createFrancProjects($manager, $user);
                continue;
            }

            $this->createDefaultProject($manager, $user, $index);
        }

        $manager->flush();
    }

    private function createDefaultProject(ObjectManager $manager, User $user, int $index): void
    {
        $userName = $user->getName() ?: $user->getEmail();
        $filmingType = ProjectCatalog::FILMING_TYPES[$index % count(ProjectCatalog::FILMING_TYPES)];
        $distributionMedium = ProjectCatalog::DISTRIBUTION_MEDIA[$index % count(ProjectCatalog::DISTRIBUTION_MEDIA)];

        $project = (new Project())
            ->setName("Proyecto $userName")
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType($filmingType)
            ->setDistributionMedia([$distributionMedium])
            ->setUser($user)
            ->setEmissionSourceName('MITECO');

        if ($filmingType === 'tv_series') {
            $project
                ->setEpisodios(8)
                ->setDuracionEpisodio(45);
        }

        $this->persistProjectGraph(
            $manager,
            $project,
            $user,
            [
                ['preproduccion', '+1 days', '+15 days'],
                ['actividad', '+20 days', '+60 days'],
                ['postproduccion', '+60 days', '+65 days'],
            ],
        );
    }

    private function createFrancProjects(ObjectManager $manager, User $franc): void
    {
        $event = (new Project())
            ->setName('EVENTO FRANC')
            ->setCountry('ES')
            ->setType('evento')
            ->setEventTypePrimary('corporativo')
            ->setEventModality('hibrido')
            ->setEventAttendeesCount(300)
            ->setEventOnlineConnections(900)
            ->setPresupuesto('133000.00')
            ->setMainLocation('Madrid')
            ->setEcoManagerStatus('designated')
            ->setDistributionMedia([])
            ->setUser($franc)
            ->setEmissionSourceName('MITECO');

        $this->persistProjectGraph(
            $manager,
            $event,
            $franc,
            [
                ['preproduccion', '2026-07-01', '2026-07-15'],
                ['actividad', '2026-07-16', '2026-07-16'],
                ['postproduccion', '2026-07-17', '2026-07-17'],
            ],
        );

        $film = (new Project())
            ->setName('RODAJE ELABORADO')
            ->setCountry('ES')
            ->setType('rodaje')
            ->setFilmingType('advert')
            ->setDistributionMedia(['tv'])
            ->setPresupuesto('150000.00')
            ->setMainLocation('Madrid')
            ->setEcoManagerStatus('designated')
            ->setUser($franc)
            ->setEmissionSourceName('MITECO');

        $this->persistProjectGraph(
            $manager,
            $film,
            $franc,
            [
                ['preproduccion', '2026-08-03', '2026-08-14'],
                ['actividad', '2026-08-17', '2026-08-17'],
                ['postproduccion', '2026-08-18', '2026-08-31'],
            ],
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $phases
     */
    private function persistProjectGraph(
        ObjectManager $manager,
        Project $project,
        User $owner,
        array $phases,
    ): void {
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

        $membership = (new ProjectMembership())
            ->setUser($owner)
            ->setProject($project)
            ->setProjectRole('owner');
        $manager->persist($membership);

        foreach ($phases as [$phaseName, $startDate, $endDate]) {
            $phase = (new ProjectPhaseDate())
                ->setPhase($phaseName)
                ->setStartDate(new \DateTime($startDate))
                ->setEndDate(new \DateTime($endDate))
                ->setProject($project);
            $manager->persist($phase);
        }
    }
}
