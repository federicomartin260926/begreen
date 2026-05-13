<?php

namespace App\Controller\Backend;

// App
use App\Entity\{Category, EmissionRecord};
use App\Form\{EmissionRecordType, EnergyEmissionType, TransportEmissionType};
use App\Repository\{CategoryRepository, EmissionActivityRepository, EmissionRecordRepository, ProjectRepository};
use App\Security\{EmissionRecordVoter, ProjectVoter};
use App\Service\{ActiveProjectService};

// Doctrine / Gedmo
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Translation;

// Symfony
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Symfony Contracts
use Symfony\Contracts\{HttpClient\HttpClientInterface, Translation\TranslatorInterface};

#[Route('/backend/emission')]
#[IsGranted('ROLE_USER')]
class EmissionController extends AbstractController
{
    #[Route('/landing', name: 'backend_emission_landing')]
    public function landing(
        ActiveProjectService $activeProjectService,
        EmissionRecordRepository $recordRepository,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $phases = $project->getPhaseDates();
        $records = $recordRepository->findBy(['project' => $project]);

        $chartData = []; // ['Rodaje' => 123, 'Preproducción' => 45, ...]

        foreach ($phases as $phase) {
            $labelKey = match ($phase->getPhase()) {
                'preproduccion' => $project->getType() === 'evento'
                    ? 'backend.emission.phase.montaje'
                    : 'backend.emission.phase.preproduccion',
                'actividad' => $project->getType() === 'evento'
                    ? 'backend.emission.phase.evento'
                    : 'backend.emission.phase.rodaje',
                'postproduccion' => $project->getType() === 'evento'
                    ? 'backend.emission.phase.desmontaje'
                    : 'backend.emission.phase.postproduccion',
                default => null,
            };

            $label = $labelKey ? $t->trans($labelKey) : ucfirst((string) $phase->getPhase());
            $chartData[$label] = 0;

            foreach ($records as $record) {
                if ($record->getPhase()?->getId() === $phase->getId()) {
                    $chartData[$label] += $record->getEmission();
                }
            }
        }

        return $this->render('backend/emission/landing.html.twig', [
            'project' => $project,
            'phases' => $phases,
            'chartData' => $chartData,
        ]);
    }

    #[Route('/records', name: 'backend_emission_index')]
    public function index(
        EmissionRecordRepository $recordRepository,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        EntityManagerInterface $em,
        TranslatorInterface $t,
        Request $request
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $records = $recordRepository->findByProjectOrderByPhaseAndDate($project);
        $allCategories = $categoryRepository->findAll();

        // VM por categoría (estable por ID)
        $categoriesVM = []; // [id => ['id','name','records'=>[],'chart'=>[]]]
        foreach ($allCategories as $cat) {
            $categoriesVM[$cat->getId()] = [
                'id'      => $cat->getId(),
                'name'    => $cat->getName(), // ya sale traducido según listener/UI
                'records' => [],
                'chart'   => [],
            ];
        }

        foreach ($records as $record) {
            $activity = $record->getActivity();
            $cat      = $activity->getCategory();
            $catId    = $cat->getId();
            $actName  = $activity->getName();

            $categoriesVM[$catId]['records'][] = $record;
            $categoriesVM[$catId]['chart'][$actName] = ($categoriesVM[$catId]['chart'][$actName] ?? 0) + $record->getEmission();
        }

        // IDs canónicos por nombre ES base (no depende del listener)
        $energyId    = $this->findCategoryIdByNameEs($em, 'Energía');
        $transportId = $this->findCategoryIdByNameEs($em, 'Transporte');
        $tripsId     = $this->findCategoryIdByNameEs($em, 'Viajes');

        $selectedCategoryId = $request->query->getInt('categoryId', 0);

        return $this->render('backend/emission/index.html.twig', [
            'project'             => $project,
            'categoriesVM'        => array_values($categoriesVM),
            'chartDataByCategory' => array_column($categoriesVM, 'chart', 'name'),
            'energyId'            => $energyId,
            'transportId'         => $transportId,
            'tripsId'             => $tripsId,
            'selectedCategoryId'  => $selectedCategoryId, // 👈
        ]);
    }

    private function findCategoryIdByNameEs(EntityManagerInterface $em, string $nameEs): ?int
    {
        $row = $em->createQuery('SELECT c.id FROM ' . Category::class . ' c WHERE c.name = :n')
                ->setParameter('n', $nameEs)
                ->setMaxResults(1)
                ->getOneOrNullResult(); // ['id'=>X] | null
        return $row['id'] ?? null;
    }


    #[Route('/new/{category}', name: 'backend_emission_new', methods: ['GET','POST'])]
    public function new(
        string $category,
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $em,
        ProjectRepository $projectRepository,
        CategoryRepository $categoryRepository,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        // Resolver categoría por ID, nombre ES o traducción EN
        $categoryEntity = $this->resolveCategoryFromRouteParam($category, $categoryRepository, $em);
        if (!$categoryEntity) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $record = new EmissionRecord();
        $record->setProject($project);
        $record->setRegisteredAt(new \DateTimeImmutable());

        $form = $this->createForm(EmissionRecordType::class, $record, [
            'category' => $categoryEntity,
        ]);
        $form->handleRequest($request);

        $calculationDetails = $request->request->get('calculationDetails');
        if ($calculationDetails !== null) {
            $record->setCalculationDetails($calculationDetails);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);
            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $record->setPhase($phase);
                $activity = $record->getActivity();
                $amount   = $record->getAmount();
                $record->setEmission($amount * $activity->getEmissionFactor());

                $em->persist($record);
                $em->flush();

                $this->addFlash('success', $t->trans('backend.emission.flash.created'));

                $categoryId = $activity->getCategory()->getId();
                return $this->redirectToRoute('backend_emission_index', ['categoryId' => $categoryId]);
            }
        }

        return $this->render('backend/emission/form.html.twig', [
            'form'     => $form->createView(),
            'project'  => $project,
            'category' => $categoryEntity,
            'edit'     => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'backend_emission_edit', methods: ['GET','POST'])]
    public function edit(
        EmissionRecord $record,
        Request $request,
        EntityManagerInterface $em,
        ProjectRepository $projectRepository,
        TranslatorInterface $t,
    ): Response {
        $project  = $record->getProject();
        $category = $record->getActivity()->getCategory();

        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.invalid_project_or_ownership'));
        }
        if (!$category) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $form = $this->createForm(EmissionRecordType::class, $record, [
            'category' => $category,
        ]);
        $form->handleRequest($request);

        $calculationDetails = $request->request->get('calculationDetails');
        if ($calculationDetails !== null) {
            $record->setCalculationDetails($calculationDetails);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);
            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $record->setPhase($phase);
                $activity = $record->getActivity();
                $amount   = $record->getAmount();
                $record->setEmission($amount * $activity->getEmissionFactor());

                $em->flush();

                $this->addFlash('success', $t->trans('backend.emission.flash.updated'));

                $categoryId = $activity->getCategory()->getId();
                return $this->redirectToRoute('backend_emission_index', ['categoryId' => $categoryId]);
            }
        }

        return $this->render('backend/emission/form.html.twig', [
            'form'    => $form->createView(),
            'project' => $project,
            'record'  => $record,
            'category'=> $category,
            'edit'    => true,
        ]);
    }

    // =======================
    // NEW ENERGY
    // =======================
    #[Route('/new-energy', name: 'backend_emission_new_energy')]
    public function newEnergy(
        Request $request,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        TranslatorInterface $t
    ): Response {
        $project  = $activeProjectService->getActiveProject();
        // OJO: aquí seguimos usando el nombre base ES "Energía"
        $category = $categoryRepository->findOneBy(['name' => 'Energía']);

        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        if (!$category) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $record = new EmissionRecord();
        $record->setProject($project);
        $record->setRegisteredAt(new \DateTimeImmutable());

        $form = $this->createForm(EnergyEmissionType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $year     = $date ? (int)$date->format('Y') : (int)date('Y');
                $subcat   = $form->get('subCategory')->getData(); // código canónico
                $activity = $activityRepository->findOneBySubcatetoryForLatestYear(
                    $subcat,
                    $year,
                    $project->getEmissionSourceName()
                );

                if (!$activity) {
                    $this->addFlash('danger', $t->trans('backend.emission.errors.activity_not_found'));
                } else {
                    $record->setPhase($phase);
                    $record->setActivity($activity);
                    $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                    $em->persist($record);
                    $em->flush();

                    $this->addFlash('success', $t->trans('backend.emission.flash.created'));

                    $categoryId = $activity->getCategory()->getId();
                    return $this->redirectToRoute('backend_emission_index', ['categoryId' => $categoryId]);
                }
            }
        }

        return $this->render('backend/emission/energy_form.html.twig', [
            'form'     => $form->createView(),
            'project'  => $project,
            'record'   => $record,
            'category' => $category,
            'edit'     => false,
        ]);
    }


    // =======================
    // EDIT ENERGY
    // =======================
    #[Route('/{id}/edit-energy', name: 'backend_emission_edit_energy')]
    public function editEnergy(
        Request $request,
        EmissionRecord $record,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        ProjectRepository $projectRepository,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        $project  = $activeProjectService->getActiveProject();
        $category = $record->getActivity()->getCategory();

        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.invalid_project_or_ownership'));
        }
        if (!$category) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $form = $this->createForm(EnergyEmissionType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $year     = $date ? (int)$date->format('Y') : (int)date('Y');
                $subcat   = $form->get('subCategory')->getData(); // código canónico
                $activity = $activityRepository->findOneBySubcatetoryForLatestYear(
                    $subcat,
                    $year,
                    $project->getEmissionSourceName()
                );

                if (!$activity) {
                    $this->addFlash('danger', $t->trans('backend.emission.errors.activity_not_found'));
                } else {
                    $record->setPhase($phase);
                    $record->setActivity($activity);
                    $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                    $em->flush();

                    $this->addFlash('success', $t->trans('backend.emission.flash.updated'));

                    $categoryId = $activity->getCategory()->getId();
                    return $this->redirectToRoute('backend_emission_index', ['categoryId' => $categoryId]);
                }
            }
        }

        return $this->render('backend/emission/energy_form.html.twig', [
            'form'     => $form->createView(),
            'project'  => $project,
            'record'   => $record,
            'category' => $category,
            'edit'     => true,
        ]);
    }


    // =======================
    // NEW TRANSPORT / TRAVEL
    // =======================
    #[Route('/new-transport-travel/{category}', name: 'backend_emission_new_transport')]
    public function newTransport(
        string $category,
        Request $request,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.no_active_project'));
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        // Resolver categoría (ID / ES / EN)
        $categoryEntity = $this->resolveCategoryFromRouteParam($category, $categoryRepository, $em);
        if (!$categoryEntity) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $record = new EmissionRecord();
        $record->setProject($project);
        $record->setRegisteredAt(new \DateTimeImmutable());

        // Si tu Form usa el nombre, puedes pasar el de la entidad (ojo: saldrá traducido según listener)
        // Idealmente, pasa el ID y ajusta el Form para trabajar por ID.
        $form = $this->createForm(TransportEmissionType::class, $record, [
            'categoryId' => $categoryEntity->getId(),
        ]);
        $form->handleRequest($request);

        // Campo NO mapeado: activityId
        $activityId = $request->request->get('activityId');
        $activity   = $activityId ? $activityRepository->find($activityId) : null;

        if ($form->isSubmitted() && $form->isValid() && $activity) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $record->setPhase($phase);
                $record->setActivity($activity);
                $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                $em->persist($record);
                $em->flush();

                $this->addFlash('success', $t->trans('backend.emission.flash.created'));

                $categoryId = $activity->getCategory()->getId();
                return $this->redirectToRoute('backend_emission_index', ['categoryId' => $categoryId]);
            }
        } elseif ($form->isSubmitted() && !$activity) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.activity_required'));
        }

        return $this->render('backend/emission/transport_form.html.twig', [
            'form'      => $form->createView(),
            'project'   => $project,
            'record'    => $record,
            'category'  => $categoryEntity,
            'edit'      => false,
            'activityId'=> ''
        ]);
    }


    // =======================
    // EDIT TRANSPORT / TRAVEL
    // =======================
    #[Route('/{id}/edit-transport-travel', name: 'backend_emission_edit_transport')]
    public function editTransport(
        Request $request,
        EmissionRecord $record,
        ActiveProjectService $activeProjectService,
        EmissionActivityRepository $activityRepository,
        ProjectRepository $projectRepository,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        $project  = $activeProjectService->getActiveProject();
        $category = $record->getActivity() ? $record->getActivity()->getCategory() : null;

        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.invalid_project_or_ownership'));
        }
        if (!$category) {
            throw $this->createNotFoundException($t->trans('backend.emission.errors.category_not_found'));
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $form = $this->createForm(TransportEmissionType::class, $record, [
            'categoryId' => $category->getId(),
        ]);
        $form->handleRequest($request);

        // Campo NO mapeado: activityId
        $activityId = $request->request->get('activityId');
        $activity   = $activityId ? $activityRepository->find($activityId) : $record->getActivity();

        if ($form->isSubmitted() && $form->isValid() && $activity) {
            $date  = $record->getRegisteredAt();
            $phase = $projectRepository->findPhaseByDate($project, $date);

            if (!$phase) {
                $this->addFlash('danger', $t->trans('backend.emission.errors.date_out_of_phase', [
                    '%date%' => $date->format('Y-m-d')
                ]));
            } else {
                $record->setPhase($phase);
                $record->setActivity($activity);
                $record->setEmission($record->getAmount() * $activity->getEmissionFactor());

                $em->flush();

                $this->addFlash('success', $t->trans('backend.emission.flash.updated'));

                $categoryId = $activity->getCategory()->getId();
                return $this->redirectToRoute('backend_emission_index', ['categoryId' => $categoryId]);
            }
        } elseif ($form->isSubmitted() && !$activity) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.activity_required'));
        }

        return $this->render('backend/emission/transport_form.html.twig', [
            'form'       => $form->createView(),
            'project'    => $project,
            'record'     => $record,
            'category'   => $category,
            'edit'       => true,
            'activityId' => $record->getActivity() ? $record->getActivity()->getId() : ''
        ]);
    }


    // =======================
    // Helper para resolver categoría por ID / ES / EN
    // =======================
    private function resolveCategoryFromRouteParam(
        string $param,
        CategoryRepository $categoryRepository,
        EntityManagerInterface $em
    ): ?Category {
        $param = trim($param);

        // 1) ID numérico
        if ($param !== '' && ctype_digit($param)) {
            return $categoryRepository->find((int)$param);
        }

        // 2) Nombre base ES
        if ($param !== '') {
            $cat = $categoryRepository->findOneBy(['name' => $param]);
            if ($cat) {
                return $cat;
            }
        }

        // 3) Traducción EN (Gedmo ext_translations)
        if ($param !== '') {
            /** @var \Gedmo\Translatable\Entity\Translation|null $tr */
            $tr = $em->getRepository(\Gedmo\Translatable\Entity\Translation::class)->findOneBy([
                'objectClass' => Category::class,
                'field'       => 'name',
                'locale'      => 'en',
                'content'     => $param,
            ]);
            if ($tr) {
                return $categoryRepository->find($tr->getForeignKey());
            }
        }

        return null;
    }


    #[Route('/{id}/delete', name: 'backend_emission_delete', methods: ['POST'])]
    public function delete(
        EmissionRecord $record,
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepository,
        ActiveProjectService $activeProjectService,
        TranslatorInterface $t
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project || $record->getProject() !== $project) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.invalid_project_or_ownership'));
            return $this->redirectToRoute('backend_emission_index');
        }

        $this->denyAccessUnlessGranted(EmissionRecordVoter::EDIT, $record);

        if (!$this->isCsrfTokenValid('delete' . $record->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.csrf_invalid'));
            return $this->redirectToRoute('backend_project_edit', ['id' => $project->getId()]);
        }

        $categoryName = (string) ($request->request->get('category') ?: ($record->getActivity()?->getCategory()?->getName() ?? 'Alojamientos'));
        $category = $categoryRepository->findOneBy(['name' => $categoryName]);

        try {
            $em->remove($record);
            $em->flush();

            $this->addFlash('success', $t->trans('backend.emission.flash.deleted'));
        } catch (\Throwable $e) {
            $this->addFlash('danger', $t->trans('backend.emission.errors.delete_failed', [
                '%error%' => $e->getMessage()
            ]));
        }

        return $this->redirectToRoute('backend_emission_index', [
            'phase'    => $record->getPhase()?->getId(),
            'category' => $category?->getName() ?? 'Alojamientos',
        ]);
    }

    #[Route('/by-subcategory', name: 'backend_emission_by_subcategory', methods: ['GET'])]
    public function bySubcategory(Request $request, EmissionActivityRepository $repo): JsonResponse
    {
        $subcategory = $request->query->get('subcategory');        // código canónico: 'carretera','aereo',...
        $sourceName  = $request->query->get('sourceName', 'MITECO');
        $categoryId  = $request->query->getInt('categoryId', 0);   // <-- ID

        if ($categoryId <= 0) {
            return new JsonResponse(['error' => 'Missing or invalid categoryId'], 400);
        }

        // Nuevo método por ID (ver repo abajo)
        $activities = $repo->getActivitiesForLatestYearByCategoryId($sourceName, $categoryId, $subcategory ?: null);

        $result = [];
        foreach ($activities as $activity) {
            $result[] = [
                'id'   => $activity->getId(),
                'name' => $activity->getName(),
                'unit' => $activity->getUnit(),
            ];
        }

        return new JsonResponse($result);
    }

    #[Route('/calculate-distance', name: 'backend_emission_calculate_distance', methods: ['POST'])]
    public function calculateDistance(Request $request, HttpClientInterface $http, TranslatorInterface $t): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $lat1 = $data['lat1'] ?? null;
        $lon1 = $data['lon1'] ?? null;
        $lat2 = $data['lat2'] ?? null;
        $lon2 = $data['lon2'] ?? null;

        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            return $this->json(['error' => $t->trans('backend.emission.errors.invalid_coordinates')], 400);
        }

        $apiKey = $_ENV['ORS_API_KEY'] ?? 'eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6IjQ2ODcwZjM3NTQyYjRjOGJhYWVhNjA3MWI0NjBmYzNmIiwiaCI6Im11cm11cjY0In0=';

        try {
            $response = $http->request('POST', 'https://api.openrouteservice.org/v2/directions/driving-car', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'coordinates' => [
                        [(float)$lon1, (float)$lat1],
                        [(float)$lon2, (float)$lat2],
                    ],
                    'radiuses' => [1000, 1000]
                ],
            ]);
            $result = $response->toArray(false);
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            $errorResponse = $e->getResponse()->toArray(false);
            $errorMsg = $errorResponse['error']['message'] ?? $t->trans('backend.emission.errors.no_route_found');
            return $this->json(['error' => $errorMsg], 422);
        }

        if (
            !isset($result['routes'][0]['segments'][0]['distance']) ||
            empty($result['routes'][0]['segments'][0]['distance'])
        ) {
            $msg = $result['error']['message'] ?? $t->trans('backend.emission.errors.no_route_hint');
            return $this->json(['error' => $msg], 422);
        }

        $meters = $result['routes'][0]['segments'][0]['distance'];
        $kilometers = round($meters / 1000, 2);

        return $this->json(['kilometers' => $kilometers]);
    }
}
