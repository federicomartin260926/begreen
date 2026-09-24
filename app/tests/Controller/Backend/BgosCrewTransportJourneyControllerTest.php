<?php

declare(strict_types=1);

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\BgosController;
use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosCrewTransportParticipant;
use App\Entity\Category;
use App\Entity\CrewMember;
use App\Entity\EmissionFactor;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Entity\ProjectSubscription;
use App\Entity\User;
use App\Enum\CommercialPhase;
use App\Repository\BgosCrewTransportJourneyRepository;
use App\Service\ActiveProjectService;
use App\Service\Bgos\BgosCrewTransportJourneyManager;
use App\Service\Bgos\BgosPeriodService;
use App\Service\Bgos\BgosPeriodWindowResolver;
use App\Service\Emission\EmissionFactorKeyGenerator;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportFactorCriteriaMapper;
use App\Service\Emission\Transport\TransportUiCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class BgosCrewTransportJourneyControllerTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private BgosController $controller;
    private Project $project;
    private CrewMember $ana;
    private CrewMember $luis;
    private ActiveProjectService $activeProjectService;
    private BgosCrewTransportJourneyRepository $journeyRepository;
    private BgosCrewTransportJourneyManager $journeyManager;
    private Session $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get('doctrine')->getConnection();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection->beginTransaction();
        $this->session = new Session(new MockArraySessionStorage());

        $admin = (new User())
            ->setName('Admin')
            ->setSurnames('BGoS journeys')
            ->setEmail(sprintf('admin.bgos.journeys.%s@example.test', uniqid()))
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $this->entityManager->persist($admin);

        $this->project = $this->project($admin, 'Proyecto journeys');
        $this->ana = $this->member($this->project, 'Ana');
        $this->luis = $this->member($this->project, 'Luis');
        $this->project
            ->addCrewMember($this->ana)
            ->addCrewMember($this->luis)
            ->addPhaseDate(
                (new ProjectPhaseDate())
                    ->setPhase('actividad')
                    ->setStartDate(new \DateTimeImmutable('2026-09-01'))
                    ->setEndDate(new \DateTimeImmutable('2026-09-30')),
            );
        $this->entityManager->persist($this->project);
        $this->prepareEmissionContext();
        $this->entityManager->flush();

        $container->get('security.token_storage')->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles()),
        );
        $container->get('twig')->addGlobal('userProjects', [$this->project]);
        $container->get('twig')->addGlobal('activeProject', $this->project);
        $container->get('twig')->addGlobal('is_admin', true);
        $this->prepareRequest(new Request([], [], ['_route' => 'backend_bgos_index']));

        $this->activeProjectService = $this->createMock(ActiveProjectService::class);
        $this->activeProjectService->method('getActiveProject')->willReturn($this->project);
        $this->journeyRepository = $container->get(BgosCrewTransportJourneyRepository::class);
        $this->journeyManager = $container->get(BgosCrewTransportJourneyManager::class);
        $this->controller = new BgosController();
        $this->controller->setContainer($container);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTransportAgendaShowsJourneysSection(): void
    {
        $content = $this->agenda('2026-09-10');

        self::assertStringContainsString('Desplazamientos del día', $content);
        self::assertStringContainsString('Crear desplazamiento', $content);
        self::assertStringContainsString('Todavía no hay desplazamientos registrados', $content);
    }

    public function testAgendaOnlyShowsJourneysForSelectedDate(): void
    {
        $this->createJourney('2026-09-10', 'Madrid visible', 'Toledo', [$this->ana]);
        $this->createJourney('2026-09-11', 'Madrid oculto', 'Segovia', [$this->luis]);

        $content = $this->agenda('2026-09-10');

        self::assertStringContainsString('Madrid visible', $content);
        self::assertStringContainsString('Toledo', $content);
        self::assertStringNotContainsString('Madrid oculto', $content);
        self::assertStringNotContainsString('kg CO₂', $this->journeysSection($content));
    }

    public function testCreatesSimpleJourney(): void
    {
        $response = $this->controller->createJourney(
            $this->createRequest([
                $this->segment('Madrid', 'Toledo', [
                    $this->participant($this->ana, BgosCrewTransportParticipant::ROLE_DRIVER),
                ], '72.5'),
            ]),
            $this->activeProjectService,
            $this->journeyManager,
        );

        $journeys = $this->journeys('2026-09-10');
        self::assertCount(1, $journeys);
        self::assertCount(1, $journeys[0]->getSegments());
        self::assertCount(1, $journeys[0]->getSegments()->first()->getParticipants());
        self::assertSame('72.5', $journeys[0]->getSegments()->first()->getDistanceKm());
        self::assertSame('manual', $journeys[0]->getSegments()->first()->getDistanceSource());
        self::assertStringEndsWith('/backend/bgos/?view=day&date=2026-09-10&open=transport#bgos-agenda-heading-transport', $response->getTargetUrl());
    }

    public function testCreatesJourneyWithOrsRouteData(): void
    {
        $segment = $this->segment('Madrid, España', 'Toledo, España', [
            $this->participant($this->ana, BgosCrewTransportParticipant::ROLE_DRIVER),
        ], '73.125');
        $segment += [
            'originLatitude' => '40.4168',
            'originLongitude' => '-3.7038',
            'destinationLatitude' => '39.8628',
            'destinationLongitude' => '-4.0273',
            'distanceSource' => 'ors',
        ];

        $this->controller->createJourney(
            $this->createRequest([$segment]),
            $this->activeProjectService,
            $this->journeyManager,
        );

        $storedSegment = $this->journeys('2026-09-10')[0]->getSegments()->first();
        self::assertSame('40.4168', $storedSegment->getOriginLatitude());
        self::assertSame('-3.7038', $storedSegment->getOriginLongitude());
        self::assertSame('39.8628', $storedSegment->getDestinationLatitude());
        self::assertSame('-4.0273', $storedSegment->getDestinationLongitude());
        self::assertSame('73.125', $storedSegment->getDistanceKm());
        self::assertSame('ors', $storedSegment->getDistanceSource());
    }

    public function testCreatesSharedJourneyWithSeveralParticipants(): void
    {
        $this->controller->createJourney(
            $this->createRequest([
                $this->segment('Madrid', 'Toledo', [
                    $this->participant($this->ana, BgosCrewTransportParticipant::ROLE_DRIVER),
                    $this->participant($this->luis, BgosCrewTransportParticipant::ROLE_PASSENGER),
                ]),
            ]),
            $this->activeProjectService,
            $this->journeyManager,
        );

        self::assertCount(2, $this->journeys('2026-09-10')[0]->getSegments()->first()->getParticipants());
    }

    public function testCreatesMultiSegmentRouteWithDifferentParticipants(): void
    {
        $this->controller->createJourney(
            $this->createRequest([
                $this->segment('A', 'B', [
                    $this->participant($this->ana, BgosCrewTransportParticipant::ROLE_DRIVER),
                    $this->participant($this->luis, BgosCrewTransportParticipant::ROLE_PASSENGER),
                ]),
                $this->segment('B', 'C', [
                    $this->participant($this->luis, BgosCrewTransportParticipant::ROLE_DRIVER),
                ]),
            ]),
            $this->activeProjectService,
            $this->journeyManager,
        );

        $segments = $this->journeys('2026-09-10')[0]->getSegments()->toArray();
        self::assertCount(2, $segments);
        self::assertCount(2, $segments[0]->getParticipants());
        self::assertCount(1, $segments[1]->getParticipants());
        self::assertSame($this->luis->getId(), $segments[1]->getParticipants()->first()->getCrewMember()?->getId());
    }

    public function testEditsJourney(): void
    {
        $journey = $this->createJourney('2026-09-10', 'Madrid', 'Toledo', [$this->ana]);
        $request = $this->postRequest('backend_bgos_journey_update', [
            '_token' => $this->csrf('bgos_crew_journey_update_'.$journey->getId()),
            'mode' => 'long_distance_train',
            'segments' => [$this->segment('Madrid', 'Barcelona', [
                $this->participant($this->luis, BgosCrewTransportParticipant::ROLE_PASSENGER),
            ], '')],
        ]);

        $response = $this->controller->updateJourney(
            (int) $journey->getId(),
            $request,
            $this->activeProjectService,
            $this->journeyRepository,
            $this->journeyManager,
        );

        self::assertSame('long_distance_train', $journey->getMode());
        self::assertSame('Barcelona', $journey->getSegments()->first()->getDestination());
        self::assertNull($journey->getSegments()->first()->getDistanceKm());
        self::assertNull($journey->getSegments()->first()->getDistanceSource());
        self::assertSame($this->luis, $journey->getSegments()->first()->getParticipants()->first()->getCrewMember());
        self::assertStringEndsWith('/backend/bgos/?view=day&date=2026-09-10&open=transport#bgos-agenda-heading-transport', $response->getTargetUrl());
    }

    public function testRemovesJourneyWithSynchronizedEmission(): void
    {
        $journey = $this->createJourney('2026-09-10', 'Madrid', 'Toledo', [$this->ana]);
        $id = $journey->getId();
        $request = $this->postRequest('backend_bgos_journey_remove', [
            '_token' => $this->csrf('bgos_crew_journey_remove_'.$id),
        ]);

        $this->controller->removeJourney(
            (int) $id,
            $request,
            $this->activeProjectService,
            $this->journeyRepository,
            $this->journeyManager,
        );

        self::assertNull($this->journeyRepository->find($id));
        self::assertSame(
            ['backend.bgos.flash.journey_removed'],
            $request->getSession()->getFlashBag()->peek('success'),
        );
    }

    public function testCannotInjectCrewMemberFromAnotherProject(): void
    {
        $otherProject = $this->project($this->project->getUser(), 'Otro proyecto');
        $outsider = $this->member($otherProject, 'Intruso');
        $otherProject->addCrewMember($outsider);
        $this->entityManager->persist($otherProject);
        $this->entityManager->flush();

        $request = $this->createRequest([
            $this->segment('Madrid', 'Toledo', [
                $this->participant($outsider, BgosCrewTransportParticipant::ROLE_PASSENGER),
            ]),
        ]);
        $this->controller->createJourney(
            $request,
            $this->activeProjectService,
            $this->journeyManager,
        );

        self::assertCount(0, $this->journeys('2026-09-10'));
        self::assertSame(
            ['backend.bgos.flash.journey_invalid'],
            $request->getSession()->getFlashBag()->peek('danger'),
        );
    }

    public function testInvalidCsrfDoesNotCreateJourney(): void
    {
        $request = $this->createRequest([
            $this->segment('Madrid', 'Toledo', [
                $this->participant($this->ana, BgosCrewTransportParticipant::ROLE_DRIVER),
            ]),
        ], 'invalid-token');

        $this->controller->createJourney(
            $request,
            $this->activeProjectService,
            $this->journeyManager,
        );

        self::assertCount(0, $this->journeys('2026-09-10'));
        self::assertSame(
            ['backend.bgos.flash.csrf_invalid'],
            $request->getSession()->getFlashBag()->peek('danger'),
        );
    }

    public function testManagerErrorsAreShownAsControlledFlash(): void
    {
        $request = $this->createRequest([
            $this->segment('Madrid', 'Toledo', [
                $this->participant($this->ana, BgosCrewTransportParticipant::ROLE_DRIVER),
                $this->participant($this->ana, BgosCrewTransportParticipant::ROLE_PASSENGER),
            ]),
        ]);

        $response = $this->controller->createJourney(
            $request,
            $this->activeProjectService,
            $this->journeyManager,
        );

        self::assertCount(0, $this->journeys('2026-09-10'));
        self::assertSame(
            ['backend.bgos.flash.journey_invalid'],
            $request->getSession()->getFlashBag()->peek('danger'),
        );
        self::assertStringContainsString('#bgos-agenda-heading-transport', $response->getTargetUrl());
    }

    public function testRemovalRedirectKeepsTransportOpenAndHeadingVisible(): void
    {
        $journey = $this->createJourney('2026-09-10', 'Madrid', 'Toledo', [$this->ana]);
        $journeyId = $journey->getId();
        $request = $this->postRequest('backend_bgos_journey_remove', [
            '_token' => $this->csrf('bgos_crew_journey_remove_'.$journeyId),
        ]);

        $response = $this->controller->removeJourney(
            (int) $journeyId,
            $request,
            $this->activeProjectService,
            $this->journeyRepository,
            $this->journeyManager,
        );

        self::assertNull($this->journeyRepository->find($journeyId));
        self::assertSame(
            ['backend.bgos.flash.journey_removed'],
            $request->getSession()->getFlashBag()->peek('success'),
        );
        self::assertStringEndsWith('/backend/bgos/?view=day&date=2026-09-10&open=transport#bgos-agenda-heading-transport', $response->getTargetUrl());
    }

    private function agenda(string $date): string
    {
        $request = new Request(['view' => 'day', 'date' => $date], [], [
            '_route' => 'backend_bgos_index',
        ]);
        $this->prepareRequest($request);

        return (string) $this->controller->index(
            $this->activeProjectService,
            self::getContainer()->get(BgosPeriodWindowResolver::class),
            self::getContainer()->get(BgosPeriodService::class),
            self::getContainer()->get(TransportUiCatalog::class),
            $this->journeyRepository,
            $request,
        )->getContent();
    }

    private function journeysSection(string $content): string
    {
        $start = strpos($content, 'id="bgos-crew-journeys"');
        self::assertNotFalse($start);

        return substr($content, $start);
    }

    /** @param list<CrewMember> $members */
    private function createJourney(
        string $date,
        string $origin,
        string $destination,
        array $members,
    ): BgosCrewTransportJourney {
        $participants = [];
        foreach ($members as $index => $member) {
            $participants[] = [
                'crewMember' => $member,
                'role' => 0 === $index
                    ? BgosCrewTransportParticipant::ROLE_DRIVER
                    : BgosCrewTransportParticipant::ROLE_PASSENGER,
            ];
        }

        return $this->journeyManager->create(
            $this->project,
            new \DateTimeImmutable($date),
            'car',
            'petrol',
            null,
            null,
            [[
                'origin' => $origin,
                'destination' => $destination,
                'distanceKm' => '10.000',
                'distanceSource' => 'manual',
                'participants' => $participants,
            ]],
        );
    }

    /** @return list<BgosCrewTransportJourney> */
    private function journeys(string $date): array
    {
        return $this->journeyRepository->findForProjectAndDate(
            $this->project,
            new \DateTimeImmutable($date),
        );
    }

    /** @param list<array<string, string>> $participants */
    private function segment(
        string $origin,
        string $destination,
        array $participants,
        string $distanceKm = '10.000',
    ): array {
        return [
            'origin' => $origin,
            'destination' => $destination,
            'distanceKm' => $distanceKm,
            'participants' => $participants,
        ];
    }

    /** @return array{crewMember:string, role:string} */
    private function participant(CrewMember $member, string $role): array
    {
        return ['crewMember' => (string) $member->getId(), 'role' => $role];
    }

    /** @param list<array<string, mixed>> $segments */
    private function createRequest(array $segments, ?string $token = null): Request
    {
        return $this->postRequest('backend_bgos_journey_create', [
            '_token' => $token ?? $this->csrf('bgos_crew_journey_create_2026-09-10'),
            'date' => '2026-09-10',
            'mode' => 'car',
            'vehicleType' => 'petrol',
            'segments' => $segments,
        ]);
    }

    private function postRequest(string $route, array $data): Request
    {
        $request = new Request([], $data, ['_route' => $route]);
        $request->setMethod(Request::METHOD_POST);
        $this->prepareRequest($request);

        return $request;
    }

    private function prepareRequest(Request $request): void
    {
        $request->setLocale('es');
        $request->setSession($this->session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function csrf(string $tokenId): string
    {
        return self::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken($tokenId)
            ->getValue();
    }

    private function project(?User $owner, string $name): Project
    {
        $project = (new Project())
            ->setName($name.' '.uniqid())
            ->setType('rodaje')
            ->setCountry('ES')
            ->setUser($owner);
        foreach ([CommercialPhase::ELABORATION, CommercialPhase::IMPLEMENTATION] as $phase) {
            $project->addSubscription(
                (new ProjectSubscription())
                    ->setPhase($phase)
                    ->setTier(ProjectSubscription::TIER_BASIC)
                    ->setStatus(ProjectSubscription::STATUS_ACTIVE)
                    ->setSource(ProjectSubscription::SOURCE_SYSTEM),
            );
        }

        return $project;
    }

    private function member(Project $project, string $name): CrewMember
    {
        return (new CrewMember())
            ->setProject($project)
            ->setName($name);
    }

    private function prepareEmissionContext(): void
    {
        $category = $this->entityManager->getRepository(Category::class)->findOneBy(['name' => 'Transporte']);
        if (!$category instanceof Category) {
            $this->entityManager->persist((new Category())->setName('Transporte'));
        }

        $date = new \DateTimeImmutable('2026-09-10');
        $input = new TransportEmissionInput(
            'local', 'car', 'distance', 'ES', $date, $date, '1', 'km', vehicleType: 'petrol',
        );
        $mapping = (new TransportFactorCriteriaMapper())->map($input);
        self::assertNotNull($mapping);

        $this->entityManager->persist(
            (new EmissionFactor())
                ->setCategoryKey('transport')
                ->setFunctionalKey((new EmissionFactorKeyGenerator())->generate($mapping->criteria))
                ->setFactorId('BGOS_CONTROLLER_CAR_PETROL')
                ->setCriteria($mapping->criteria)
                ->setActivityYear(2026)
                ->setYear(2026)
                ->setValue('0.5')
                ->setUnit($mapping->criteria['unit'])
                ->setSource('BGoS controller test'),
        );
    }
}
