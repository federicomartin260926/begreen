<?php

namespace App\Controller\Backend;

use App\Entity\BgosCrewTransportDay;
use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosSubcategoryConfig;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Entity\Project;
use App\Repository\BgosCrewTransportDayRepository;
use App\Repository\BgosCrewTransportJourneyRepository;
use App\Repository\BgosSubcategoryConfigRepository;
use App\Repository\CrewMemberRepository;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\Bgos\BgosCrewProfileManager;
use App\Service\Bgos\BgosCrewTransportDayManager;
use App\Service\Bgos\BgosCrewTransportJourneyManager;
use App\Service\Bgos\BgosCrewRosterService;
use App\Service\Bgos\BgosEmissionEntryContextResolver;
use App\Service\Bgos\BgosPeriodService;
use App\Service\Bgos\BgosPeriodWindowResolver;
use App\Service\Bgos\BgosSubcategoryCatalog;
use App\Service\Emission\Transport\TransportUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/bgos', name: 'backend_bgos_')]
#[IsGranted('ROLE_USER')]
final class BgosController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        ActiveProjectService $activeProjectService,
        BgosPeriodWindowResolver $windowResolver,
        BgosPeriodService $periodService,
        TransportUiCatalog $transportCatalog,
        BgosCrewTransportJourneyRepository $journeyRepository,
        Request $request,
    ): Response {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $today = new \DateTimeImmutable('today');
        $view = $request->query->getString('view', BgosPeriodWindowResolver::VIEW_DAY);
        $selectedDate = $this->selectedDate($request->query->getString('date'));

        $window = $windowResolver->resolve(
            $project,
            $view,
            $selectedDate,
            $today,
        );

        $period = null;
        if (null !== $window) {
            $period = $periodService->build(
                $project,
                $window->startDate,
                $window->endDate,
                $today,
                BgosPeriodWindowResolver::VIEW_TOTAL === $window->view,
            );
        }

        $journeys = [];
        if (
            null !== $window
            && BgosPeriodWindowResolver::VIEW_DAY === $window->view
        ) {
            $journeys = $journeyRepository->findForProjectAndDate(
                $project,
                $window->selectedDate,
            );
        }

        return $this->render('backend/bgos/agenda.html.twig', [
            'project' => $project,
            'window' => $window,
            'period' => $period,
            'views' => BgosPeriodWindowResolver::VIEWS,
            'entryRoutes' => BgosEmissionEntryContextResolver::ROUTES,
            'crewTransportOptions' => $this->crewTransportOptions($transportCatalog),
            'crewTransportJourneys' => $journeys,
            'journeyCrewMembers' => $this->projectCrewMembers($project),
        ]);
    }

    #[Route('/journey/create', name: 'journey_create', methods: ['POST'])]
    public function createJourney(
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosCrewTransportJourneyManager $journeyManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $date = $this->selectedDate($request->request->getString('date'));
        if (null === $date) {
            $this->addFlash('danger', 'backend.bgos.flash.journey_invalid');

            return $this->redirectToRoute('backend_bgos_index');
        }

        if (!$this->isCsrfTokenValid(
            'bgos_crew_journey_create_'.$date->format('Y-m-d'),
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->journeyRedirect($date);
        }

        try {
            $journeyManager->create(
                $project,
                $date,
                $request->request->getString('mode'),
                $request->request->getString('vehicleType'),
                $request->request->getString('fuel'),
                $request->request->getString('thermalFuel'),
                $this->readJourneySegments($request, $project),
            );
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'backend.bgos.flash.journey_invalid');

            return $this->journeyRedirect($date);
        }

        $this->addFlash('success', 'backend.bgos.flash.journey_saved');

        return $this->journeyRedirect($date);
    }

    #[Route(
        '/journey/{journeyId}/update',
        name: 'journey_update',
        methods: ['POST'],
        requirements: ['journeyId' => '\d+'],
    )]
    public function updateJourney(
        int $journeyId,
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosCrewTransportJourneyRepository $journeyRepository,
        BgosCrewTransportJourneyManager $journeyManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $journey = $journeyRepository->find($journeyId);
        if (
            !$journey instanceof BgosCrewTransportJourney
            || $journey->getProject() !== $project
        ) {
            throw $this->createNotFoundException();
        }

        $date = $journey->getDate();
        if (!$date instanceof \DateTimeImmutable) {
            throw new \LogicException('BGoS crew transport journey has no date.');
        }

        if (!$this->isCsrfTokenValid(
            'bgos_crew_journey_update_'.$journeyId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->journeyRedirect($date);
        }

        try {
            $journeyManager->update(
                $project,
                $journey,
                $date,
                $request->request->getString('mode'),
                $request->request->getString('vehicleType'),
                $request->request->getString('fuel'),
                $request->request->getString('thermalFuel'),
                $this->readJourneySegments($request, $project),
            );
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'backend.bgos.flash.journey_invalid');

            return $this->journeyRedirect($date);
        }

        $this->addFlash('success', 'backend.bgos.flash.journey_saved');

        return $this->journeyRedirect($date);
    }

    #[Route(
        '/journey/{journeyId}/remove',
        name: 'journey_remove',
        methods: ['POST'],
        requirements: ['journeyId' => '\d+'],
    )]
    public function removeJourney(
        int $journeyId,
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosCrewTransportJourneyRepository $journeyRepository,
        BgosCrewTransportJourneyManager $journeyManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $journey = $journeyRepository->find($journeyId);
        if (
            !$journey instanceof BgosCrewTransportJourney
            || $journey->getProject() !== $project
        ) {
            throw $this->createNotFoundException();
        }

        $date = $journey->getDate();
        if (!$date instanceof \DateTimeImmutable) {
            throw new \LogicException('BGoS crew transport journey has no date.');
        }

        if (!$this->isCsrfTokenValid(
            'bgos_crew_journey_remove_'.$journeyId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->journeyRedirect($date);
        }

        try {
            $journeyManager->remove($project, $journey);
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'backend.bgos.flash.journey_protected');

            return $this->journeyRedirect($date);
        }

        $this->addFlash('success', 'backend.bgos.flash.journey_removed');

        return $this->journeyRedirect($date);
    }

    #[Route('/config', name: 'config', methods: ['GET'])]
    public function config(
        ActiveProjectService $activeProjectService,
        BgosSubcategoryConfigRepository $repository,
        BgosSubcategoryCatalog $catalog,
        Request $request,
    ): Response {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $configs = [];
        foreach ($repository->findBy(['project' => $project]) as $config) {
            $configs[$config->getCategoryKey()][$config->getSubcategoryKey()] = $config;
        }

        $categories = $catalog->categories();
        $requestedOpenCategory = $request->query->getString('open');
        $categoryKeys = array_column($categories, 'key');
        $openCategory = in_array($requestedOpenCategory, $categoryKeys, true)
            ? $requestedOpenCategory
            : null;

        foreach ($categories as &$category) {
            $category['open'] = $category['key'] === $openCategory;
            $previousGroupKey = null;

            foreach ($category['subcategories'] as &$definition) {
                $config = $configs[$definition['categoryKey']][$definition['subcategoryKey']] ?? null;
                $definition['config'] = $config;
                $definition['active'] = $config?->isActive() ?? true;
                $definition['preproductionFrequency'] = $config?->getPreproductionFrequency()
                    ?? BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE;
                $definition['activityFrequency'] = $config?->getActivityFrequency()
                    ?? BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE;
                $definition['postproductionFrequency'] = $config?->getPostproductionFrequency()
                    ?? BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE;
                $definition['showGroup'] = null !== $definition['groupKey']
                    && $definition['groupKey'] !== $previousGroupKey;
                $previousGroupKey = $definition['groupKey'];
            }
            unset($definition);
        }
        unset($category);

        return $this->render('backend/bgos/config.html.twig', [
            'project' => $project,
            'categories' => $categories,
            'frequencies' => BgosSubcategoryConfig::FREQUENCIES,
            'phaseLabels' => [
                'preproduction' => $project->getPhaseLabel('preproduccion'),
                'activity' => $project->getPhaseLabel('actividad'),
                'postproduction' => $project->getPhaseLabel('postproduccion'),
            ],
        ]);
    }

    #[Route('/crew', name: 'crew', methods: ['GET'])]
    public function crew(
        ActiveProjectService $activeProjectService,
        BgosCrewRosterService $rosterService,
        TransportUiCatalog $transportCatalog,
    ): Response {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $transportCategories = $transportCatalog->categories();
        $transportConfig = $transportCatalog->configuration();

        $peopleModes = array_values(array_unique(array_merge(
            $transportCategories['local'] ?? [],
            $transportCategories['travel'] ?? [],
        )));

        return $this->render('backend/bgos/crew.html.twig', [
            'project' => $project,
            'rows' => $rosterService->build($project),
            'peopleModes' => $peopleModes,
            'vehicleTypes' => $transportConfig['vehicleTypes'] ?? [],
            'fuels' => $transportConfig['fuelsByMode']['car'] ?? [],
            'thermalFuels' => $transportConfig['thermalFuels'] ?? [],
        ]);
    }

    #[Route(
        '/crew/{crewMemberId}/profile',
        name: 'crew_profile_save',
        methods: ['POST'],
        requirements: ['crewMemberId' => '\d+'],
    )]
    public function saveCrewProfile(
        int $crewMemberId,
        Request $request,
        ActiveProjectService $activeProjectService,
        CrewMemberRepository $crewMemberRepository,
        BgosCrewProfileManager $profileManager,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $crewMember = $crewMemberRepository->find($crewMemberId);

        if (
            null === $crewMember
            || $crewMember->getProject() !== $project
        ) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(
            'bgos_crew_profile_'.$crewMemberId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->redirectToRoute('backend_bgos_crew');
        }

        $assignment = null;
        $assignmentId = $request->request->getString('defaultAssignment');

        if ('' !== $assignmentId) {
            if (!ctype_digit($assignmentId)) {
                $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

                return $this->redirectToRoute('backend_bgos_crew', [
                    '_fragment' => 'crew-member-'.$crewMemberId,
                ]);
            }

            $assignment = $entityManager->find(
                CrewMemberAssignment::class,
                (int) $assignmentId,
            );

            if (
                !$assignment instanceof CrewMemberAssignment
                || $assignment->getCrewMember() !== $crewMember
            ) {
                $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

                return $this->redirectToRoute('backend_bgos_crew', [
                    '_fragment' => 'crew-member-'.$crewMemberId,
                ]);
            }
        }

        try {
            $profileManager->save(
                $crewMember,
                $assignment,
                $request->request->get('defaultOrigin'),
                $request->request->get('defaultMode'),
                $request->request->get('defaultVehicleType'),
                $request->request->get('defaultFuel'),
                $request->request->get('defaultThermalFuel'),
            );
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

            return $this->redirectToRoute('backend_bgos_crew', [
                '_fragment' => 'crew-member-'.$crewMemberId,
            ]);
        }

        $this->addFlash('success', 'backend.bgos.flash.saved');

        return $this->redirectToRoute('backend_bgos_crew', [
            '_fragment' => 'crew-member-'.$crewMemberId,
        ]);
    }

    #[Route(
        '/crew-day/{crewMemberId}/add',
        name: 'crew_day_add',
        methods: ['POST'],
        requirements: ['crewMemberId' => '\d+'],
    )]
    public function addCrewDay(
        int $crewMemberId,
        Request $request,
        ActiveProjectService $activeProjectService,
        CrewMemberRepository $crewMemberRepository,
        BgosCrewTransportDayManager $dayManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $crewMember = $crewMemberRepository->find($crewMemberId);

        if (
            null === $crewMember
            || $crewMember->getProject() !== $project
        ) {
            throw $this->createNotFoundException();
        }

        $date = $this->selectedDate(
            $request->request->getString('date')
        );

        if (null === $date) {
            $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

            return $this->redirectToRoute('backend_bgos_index');
        }

        if (!$this->isCsrfTokenValid(
            sprintf(
                'bgos_crew_day_add_%d_%s',
                $crewMemberId,
                $date->format('Y-m-d'),
            ),
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->redirectToRoute('backend_bgos_index', [
                'view' => BgosPeriodWindowResolver::VIEW_DAY,
                'date' => $date->format('Y-m-d'),
            ]);
        }

        $dayManager->ensure($crewMember, $date);

        $this->addFlash('success', 'backend.bgos.flash.saved');

        return $this->redirectToRoute('backend_bgos_index', [
            'view' => BgosPeriodWindowResolver::VIEW_DAY,
            'date' => $date->format('Y-m-d'),
            'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
        ]);
    }

    #[Route(
        '/crew-day/{dayId}/status',
        name: 'crew_day_status',
        methods: ['POST'],
        requirements: ['dayId' => '\d+'],
    )]
    public function updateCrewDayStatus(
        int $dayId,
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosCrewTransportDayRepository $dayRepository,
        BgosCrewTransportDayManager $dayManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $day = $dayRepository->find($dayId);

        if (
            !$day instanceof BgosCrewTransportDay
            || $day->getCrewMember()?->getProject() !== $project
        ) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(
            'bgos_crew_day_status_'.$dayId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->redirectToRoute('backend_bgos_index', [
                'view' => BgosPeriodWindowResolver::VIEW_DAY,
                'date' => $day->getDate()?->format('Y-m-d'),
            ]);
        }

        try {
            $dayManager->update(
                $day,
                $request->request->getString('status'),
                $day->getCrewAssignment(),
                $day->getOrigin(),
                $day->getMode(),
                $day->getVehicleType(),
                $day->getFuel(),
                $day->getThermalFuel(),
            );
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

            return $this->redirectToRoute('backend_bgos_index', [
                'view' => BgosPeriodWindowResolver::VIEW_DAY,
                'date' => $day->getDate()?->format('Y-m-d'),
            ]);
        }

        $this->addFlash('success', 'backend.bgos.flash.saved');

        return $this->redirectToRoute('backend_bgos_index', [
            'view' => BgosPeriodWindowResolver::VIEW_DAY,
            'date' => $day->getDate()?->format('Y-m-d'),
            'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
        ]);
    }

    #[Route(
        '/crew-day/{dayId}/update',
        name: 'crew_day_update',
        methods: ['POST'],
        requirements: ['dayId' => '\d+'],
    )]
    public function updateCrewDay(
        int $dayId,
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosCrewTransportDayRepository $dayRepository,
        BgosCrewTransportDayManager $dayManager,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $day = $dayRepository->find($dayId);

        if (
            !$day instanceof BgosCrewTransportDay
            || $day->getCrewMember()?->getProject() !== $project
        ) {
            throw $this->createNotFoundException();
        }

        $date = $day->getDate();

        if (!$this->isCsrfTokenValid(
            'bgos_crew_day_update_'.$dayId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->redirectToRoute('backend_bgos_index', [
                'view' => BgosPeriodWindowResolver::VIEW_DAY,
                'date' => $date?->format('Y-m-d'),
            ]);
        }

        $assignment = null;
        $assignmentId = $request->request->getString('crewAssignment');

        if ('' !== $assignmentId) {
            if (!ctype_digit($assignmentId)) {
                $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

                return $this->redirectToRoute('backend_bgos_index', [
                    'view' => BgosPeriodWindowResolver::VIEW_DAY,
                    'date' => $date?->format('Y-m-d'),
                    'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
                ]);
            }

            $assignment = $entityManager->find(
                CrewMemberAssignment::class,
                (int) $assignmentId,
            );

            if (
                !$assignment instanceof CrewMemberAssignment
                || $assignment->getCrewMember() !== $day->getCrewMember()
            ) {
                $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

                return $this->redirectToRoute('backend_bgos_index', [
                    'view' => BgosPeriodWindowResolver::VIEW_DAY,
                    'date' => $date?->format('Y-m-d'),
                    'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
                ]);
            }
        }

        try {
            $dayManager->update(
                $day,
                $request->request->getString('status'),
                $assignment,
                $request->request->getString('origin'),
                $request->request->getString('mode'),
                $request->request->getString('vehicleType'),
                $request->request->getString('fuel'),
                $request->request->getString('thermalFuel'),
            );
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

            return $this->redirectToRoute('backend_bgos_index', [
                'view' => BgosPeriodWindowResolver::VIEW_DAY,
                'date' => $date?->format('Y-m-d'),
                'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
            ]);
        }

        $this->addFlash('success', 'backend.bgos.flash.saved');

        return $this->redirectToRoute('backend_bgos_index', [
            'view' => BgosPeriodWindowResolver::VIEW_DAY,
            'date' => $date?->format('Y-m-d'),
            'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
        ]);
    }

    #[Route(
        '/crew-day/{dayId}/remove',
        name: 'crew_day_remove',
        methods: ['POST'],
        requirements: ['dayId' => '\d+'],
    )]
    public function removeCrewDay(
        int $dayId,
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosCrewTransportDayRepository $dayRepository,
        BgosCrewTransportDayManager $dayManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $day = $dayRepository->find($dayId);

        if (
            !$day instanceof BgosCrewTransportDay
            || $day->getCrewMember()?->getProject() !== $project
        ) {
            throw $this->createNotFoundException();
        }

        $date = $day->getDate();

        if (!$this->isCsrfTokenValid(
            'bgos_crew_day_remove_'.$dayId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->redirectToRoute('backend_bgos_index', [
                'view' => BgosPeriodWindowResolver::VIEW_DAY,
                'date' => $date?->format('Y-m-d'),
            ]);
        }

        $dayManager->remove($day);

        $this->addFlash('success', 'backend.bgos.flash.saved');

        return $this->redirectToRoute('backend_bgos_index', [
            'view' => BgosPeriodWindowResolver::VIEW_DAY,
            'date' => $date?->format('Y-m-d'),
            'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
        ]);
    }

    #[Route(
        '/subcategory/{categoryKey}/{subcategoryKey}',
        name: 'subcategory_save',
        methods: ['POST'],
        requirements: [
            'categoryKey' => '[a-z]+',
            'subcategoryKey' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        ],
    )]
    public function save(
        string $categoryKey,
        string $subcategoryKey,
        Request $request,
        ActiveProjectService $activeProjectService,
        BgosSubcategoryConfigRepository $repository,
        BgosSubcategoryCatalog $catalog,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $definition = $catalog->find($categoryKey, $subcategoryKey);
        if (null === $definition) {
            throw $this->createNotFoundException();
        }

        $tokenId = sprintf(
            'bgos_subcategory_save_%s_%s',
            $categoryKey,
            $subcategoryKey,
        );

        if (!$this->isCsrfTokenValid(
            $tokenId,
            $request->request->getString('_token'),
        )) {
            $this->addFlash('danger', 'backend.bgos.flash.csrf_invalid');

            return $this->redirectToRoute('backend_bgos_config', [
                'open' => $categoryKey,
            ]);
        }

        $frequencies = $this->readFrequencies($request);
        if (null === $frequencies) {
            $this->addFlash('danger', 'backend.bgos.flash.invalid_input');

            return $this->redirectToRoute('backend_bgos_config', [
                'open' => $categoryKey,
            ]);
        }

        $config = $repository->findOneBy([
            'project' => $project,
            'categoryKey' => $categoryKey,
            'subcategoryKey' => $subcategoryKey,
        ]);

        $created = null === $config;

        $config ??= (new BgosSubcategoryConfig())
            ->setProject($project)
            ->setCategoryKey($definition['categoryKey'])
            ->setSubcategoryKey($definition['subcategoryKey']);

        $config
            ->setLabel($definition['label'])
            ->setActive($request->request->getBoolean('active'))
            ->setPreproductionFrequency($frequencies['preproduction'])
            ->setActivityFrequency($frequencies['activity'])
            ->setPostproductionFrequency($frequencies['postproduction']);

        if ($created) {
            $entityManager->persist($config);
        }

        $entityManager->flush();

        $this->addFlash('success', 'backend.bgos.flash.saved');

        return $this->redirectToRoute('backend_bgos_config', [
            'open' => $categoryKey,
            '_fragment' => sprintf(
                'bgos-%s-%s',
                $categoryKey,
                $subcategoryKey,
            ),
        ]);
    }

    /**
     * @return list<array{
     *     origin:string,
     *     destination:string,
     *     originLatitude:?string,
     *     originLongitude:?string,
     *     destinationLatitude:?string,
     *     destinationLongitude:?string,
     *     distanceKm:?string,
     *     distanceSource:?string,
     *     participants:list<array{crewMember:CrewMember, role:string}>
     * }>
     */
    private function readJourneySegments(Request $request, Project $project): array
    {
        $requestData = $request->request->all();
        $rawSegments = $requestData['segments'] ?? [];
        if (!is_array($rawSegments)) {
            throw new \InvalidArgumentException('Invalid journey segments.');
        }

        $crewMembers = [];
        foreach ($project->getCrewMembers() as $crewMember) {
            if (null !== $crewMember->getId()) {
                $crewMembers[$crewMember->getId()] = $crewMember;
            }
        }

        $segments = [];
        foreach ($rawSegments as $rawSegment) {
            if (!is_array($rawSegment)) {
                throw new \InvalidArgumentException('Invalid journey segment.');
            }

            $rawParticipants = $rawSegment['participants'] ?? [];
            if (!is_array($rawParticipants)) {
                throw new \InvalidArgumentException('Invalid journey participants.');
            }

            $participants = [];
            foreach ($rawParticipants as $rawParticipant) {
                if (!is_array($rawParticipant)) {
                    throw new \InvalidArgumentException('Invalid journey participant.');
                }

                $crewMemberId = $this->journeyInputString(
                    $rawParticipant['crewMember'] ?? null,
                );
                if (!ctype_digit($crewMemberId)) {
                    throw new \InvalidArgumentException('Invalid journey crew member.');
                }

                $crewMember = $crewMembers[(int) $crewMemberId] ?? null;
                if (!$crewMember instanceof CrewMember) {
                    throw new \InvalidArgumentException(
                        'Journey crew member does not belong to the active project.',
                    );
                }

                $participants[] = [
                    'crewMember' => $crewMember,
                    'role' => $this->journeyInputString($rawParticipant['role'] ?? null),
                ];
            }

            $distanceKm = trim($this->journeyInputString($rawSegment['distanceKm'] ?? null));
            $distanceKm = '' === $distanceKm ? null : $distanceKm;
            $distanceSource = trim($this->journeyInputString($rawSegment['distanceSource'] ?? null));

            $segments[] = [
                'origin' => $this->journeyInputString($rawSegment['origin'] ?? null),
                'destination' => $this->journeyInputString($rawSegment['destination'] ?? null),
                'originLatitude' => $this->journeyOptionalInputString($rawSegment['originLatitude'] ?? null),
                'originLongitude' => $this->journeyOptionalInputString($rawSegment['originLongitude'] ?? null),
                'destinationLatitude' => $this->journeyOptionalInputString($rawSegment['destinationLatitude'] ?? null),
                'destinationLongitude' => $this->journeyOptionalInputString($rawSegment['destinationLongitude'] ?? null),
                'distanceKm' => $distanceKm,
                'distanceSource' => null === $distanceKm ? null : ('ors' === $distanceSource ? 'ors' : 'manual'),
                'participants' => $participants,
            ];
        }

        return $segments;
    }

    private function journeyInputString(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Invalid journey input.');
        }

        return (string) $value;
    }

    private function journeyOptionalInputString(mixed $value): ?string
    {
        $value = trim($this->journeyInputString($value));

        return '' === $value ? null : $value;
    }

    /** @return list<CrewMember> */
    private function projectCrewMembers(Project $project): array
    {
        $crewMembers = $project->getCrewMembers()->toArray();
        usort(
            $crewMembers,
            static fn (CrewMember $left, CrewMember $right): int => strcasecmp(
                trim((string) $left->getName().' '.(string) $left->getLastName()),
                trim((string) $right->getName().' '.(string) $right->getLastName()),
            ),
        );

        return $crewMembers;
    }

    private function journeyRedirect(\DateTimeInterface $date): RedirectResponse
    {
        return $this->redirectToRoute('backend_bgos_index', [
            'view' => BgosPeriodWindowResolver::VIEW_DAY,
            'date' => $date->format('Y-m-d'),
            'open' => 'transport',
            '_fragment' => 'bgos-agenda-heading-transport',
        ]);
    }

    /**
     * @return array{
     *     peopleModes:list<string>,
     *     vehicleTypes:list<string>,
     *     fuels:list<string>,
     *     thermalFuels:list<string>
     * }
     */
    private function crewTransportOptions(
        TransportUiCatalog $transportCatalog,
    ): array {
        $categories = $transportCatalog->categories();
        $config = $transportCatalog->configuration();

        return [
            'peopleModes' => array_values(array_unique(array_merge(
                $categories['local'] ?? [],
                $categories['travel'] ?? [],
            ))),
            'vehicleTypes' => $config['vehicleTypes'] ?? [],
            'fuels' => $config['fuelsByMode']['car'] ?? [],
            'thermalFuels' => $config['thermalFuels'] ?? [],
        ];
    }

    private function activeProject(
        ActiveProjectService $activeProjectService,
    ): Project {
        $project = $activeProjectService->getActiveProject();

        if (!$project) {
            throw $this->createNotFoundException('No active project.');
        }

        return $project;
    }

    private function selectedDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (
            false === $date
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }

    /** @return array{preproduction: string, activity: string, postproduction: string}|null */
    private function readFrequencies(Request $request): ?array
    {
        $frequencies = [
            'preproduction' => $request->request->getString('preproductionFrequency'),
            'activity' => $request->request->getString('activityFrequency'),
            'postproduction' => $request->request->getString('postproductionFrequency'),
        ];

        foreach ($frequencies as $frequency) {
            if (!in_array(
                $frequency,
                BgosSubcategoryConfig::FREQUENCIES,
                true,
            )) {
                return null;
            }
        }

        return $frequencies;
    }
}
