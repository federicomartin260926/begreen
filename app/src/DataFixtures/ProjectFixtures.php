<?php

namespace App\DataFixtures;

use App\Entity\Project;
use App\Entity\ProjectCompany;
use App\Entity\ProjectDocument;
use App\Entity\ProjectFundingSource;
use App\Entity\ProjectMembership;
use App\Entity\ProjectPhaseDate;
use App\Entity\User;
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
    private const VERIFIER_EMAIL = 'verificadora.qa82@example.test';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OdsRepository $odsRepository,
    ) {
    }

    public static function getGroups(): array { return ['projects']; }

    public function getDependencies(): array
    {
        return [UserFixtures::class, MeasureFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $users = $this->userRepository->findAll();
        if ($users === []) {
            throw new \RuntimeException('No se encontró ningún usuario en la base de datos.');
        }
        foreach ($users as $index => $user) {
            if (!$user instanceof User) { continue; }
            if ($user->getEmail() === self::VERIFIER_EMAIL) { continue; }
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
            ->setCommercialTier(Project::COMMERCIAL_TIER_BASIC)
            ->setFilmingType($filmingType)
            ->setDistributionMedia([$distributionMedium])
            ->setUser($user)
            ->setEmissionSourceName('MITECO');
        if ($filmingType === 'tv_series') {
            $project->setEpisodios(8)->setDuracionEpisodio(45);
        }
        $this->persistProjectGraph($manager, $project, $user, [
            ['preproduccion', '+1 days', '+15 days'],
            ['actividad', '+20 days', '+60 days'],
            ['postproduccion', '+60 days', '+65 days'],
        ]);
    }

    private function createFrancProjects(ObjectManager $manager, User $franc): void
    {
        $verifier = $this->userRepository->findOneBy(['email' => self::VERIFIER_EMAIL]);
        if (!$verifier instanceof User) {
            throw new \RuntimeException('No se encontró la certificadora QA #82 en fixtures.');
        }
        foreach (FrancProjectPlanData::PROJECTS as $name => $fixture) {
            $data = $fixture['project'];
            $project = (new Project())
                ->setName($name)
                ->setType($data['type'])
                ->setCountry($data['country'])
                ->setCommercialTier($fixture['tier'])
                ->setFilmingType($data['filmingType'])
                ->setFilmingGenre($data['filmingGenre'])
                ->setMainLocation($data['mainLocation'])
                ->setEcoManagerStatus($data['ecoManagerStatus'])
                ->setPresupuesto($data['presupuesto'])
                ->setDistributionMedia($data['distributionMedia'])
                ->setEmissionSourceName($data['emissionSourceName'])
                ->setUser($franc);
            $this->persistProjectGraph($manager, $project, $franc, $fixture['phases']);
            $manager->persist((new ProjectMembership())
                ->setUser($verifier)->setProject($project)->setProjectRole('member'));
            foreach ($fixture['companies'] as $company) {
                $logoPath = $this->installLogoAsset($company['logo']);
                $manager->persist((new ProjectCompany())
                    ->setProject($project)
                    ->setType($company['type'])
                    ->setName($company['name'])
                    ->setPosition($company['position'])
                    ->setLogoPath($logoPath));
            }
            foreach ($fixture['fundingSources'] as $funding) {
                $manager->persist((new ProjectFundingSource())
                    ->setProject($project)
                    ->setType($funding['type'])
                    ->setName($funding['name'])
                    ->setPercentage($funding['percentage'])
                    ->setPosition($funding['position']));
            }
            // Solo enlaces de documentación de la ficha. No fotos del informe final.
            foreach ($fixture['documents'] as $document) {
                $manager->persist((new ProjectDocument())
                    ->setProject($project)
                    ->setType($document['type'])
                    ->setKind('link')
                    ->setUrl($document['url']));
            }
        }
    }

    private function installLogoAsset(string $filename): string
    {
        if (!preg_match('/^(jacobs-creek|ecoembes)-company-0[1-4]\.png$/D', $filename)) {
            throw new \RuntimeException('Unexpected fixture logo filename.');
        }
        $source = __DIR__.'/Resources/franc-demo-company-logos/'.$filename;
        $relative = '/uploads/demo/company-logos/'.$filename;
        $destination = dirname(__DIR__, 2).'/public'.$relative;
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('Missing fixture logo %s', $filename));
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create fixture logo directory.');
        }
        if (!copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Cannot install fixture logo %s', $filename));
        }
        return $relative;
    }

    /** @param list<array{0: string, 1: string, 2: string}> $phases */
    private function persistProjectGraph(ObjectManager $manager, Project $project, User $owner, array $phases): void
    {
        $manager->persist($project);
        $manager->persist((new ProjectMembership())->setUser($owner)->setProject($project)->setProjectRole('owner'));
        foreach ($phases as [$name, $startDate, $endDate]) {
            $manager->persist((new ProjectPhaseDate())
                ->setPhase($name)
                ->setStartDate(new \DateTime($startDate))
                ->setEndDate(new \DateTime($endDate))
                ->setProject($project));
        }
    }
}
