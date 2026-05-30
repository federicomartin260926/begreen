<?php

namespace App\Controller\Backend;

use App\Entity\{Department, EmissionActivity, EmissionRecord, Plan, Position, Project, CrewMember, ProjectMembership, ProjectPhaseDate, User};
use App\Form\{ CrewMemberImportType, ProjectType, CrewMemberType, CrewMemberCollectionType };
use App\Repository\{ ProjectRepository, CrewMemberRepository, PositionRepository, DepartmentRepository };
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Entity\ProjectSubscription;
use App\Service\ProjectFeatureGate;
use Gedmo\Translatable\Entity\Translation;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{ Request, Response, RedirectResponse, StreamedResponse, ResponseHeaderBag };
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/project', name: 'backend_project_')]
#[IsGranted('ROLE_USER')]
class ProjectController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $t, private readonly ProjectFeatureGate $featureGate) {}

    #[Route('/', name: 'index')]
    public function index(
        ProjectRepository $projectRepository,
        ActiveProjectService $activeProjectService,
        Request $request
    ): Response {
        /** @var User $user */
        $user    = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN'); // SUPER_ADMIN incluido

        // Filtros GET
        $name     = trim((string) $request->query->get('name', ''));
        $type     = (string) $request->query->get('type', '');
        $country  = (string) $request->query->get('country', '');
        $owner    = trim((string) $request->query->get('owner', ''));
        $dateFrom = (string) $request->query->get('date_from', '');
        $dateTo   = (string) $request->query->get('date_to', '');

        // Paginación
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 10;
        $offset  = ($page - 1) * $perPage;

        // Query base (membresías)
        $qb = $projectRepository->createQueryBuilder('p')
            ->leftJoin('p.projectMemberships', 'pm')
            ->leftJoin('pm.user', 'mu') // miembro
            ->leftJoin('p.user', 'cu')  // creador
            ->leftJoin('p.subscription', 'sub')
            ->addSelect('pm', 'mu', 'cu', 'sub');

        // Alcance por rol
        if (!$isAdmin) {
            $qb->andWhere('pm.user = :me')->setParameter('me', $user);
        }

        // Filtros
        if ($name !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :name')
               ->setParameter('name', '%'.mb_strtolower($name).'%');
        }
        if ($type !== '') {
            $qb->andWhere('p.type = :type')->setParameter('type', $type);
        }
        if ($country !== '') {
            $qb->andWhere('p.country = :country')->setParameter('country', $country);
        }
        if ($isAdmin && $owner !== '') {
            $qb->andWhere('LOWER(cu.email) LIKE :owner')
               ->setParameter('owner', '%'.mb_strtolower($owner).'%');
        }
        if ($dateFrom !== '') {
            try {
                $qb->andWhere('p.createdAt >= :from')
                   ->setParameter('from', new \DateTimeImmutable($dateFrom.' 00:00:00'));
            } catch (\Throwable $e) {}
        }
        if ($dateTo !== '') {
            try {
                $qb->andWhere('p.createdAt <= :to')
                   ->setParameter('to', new \DateTimeImmutable($dateTo.' 23:59:59'));
            } catch (\Throwable $e) {}
        }

        // Total
        $qbCount = clone $qb;
        $total = (int) $qbCount
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Página actual
        $projects = $qb
            ->select('DISTINCT p')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        // Proyecto activo
        $activeProject = $activeProjectService->getActiveProject();

        return $this->render('backend/project/index.html.twig', [
            'projects'      => $projects,
            'activeProject' => $activeProject,
            'featureGate'   => $this->featureGate,
            'filters'       => [
                'name'      => $name,
                'type'      => $type,
                'country'   => $country,
                'owner'     => $owner,
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
            ],
            'is_admin'     => $isAdmin,
            'currentPage'  => $page,
            'totalPages'   => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        ActiveProjectService $activeProjectService
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $project = new Project();
        $this->ensureBasicSubscription($project);

        // Fases por defecto
        foreach (['actividad', 'preproduccion', 'postproduccion'] as $phaseName) {
            $phaseDate = new ProjectPhaseDate();
            $phaseDate->setPhase($phaseName);
            $project->addPhaseDate($phaseDate);
        }
        $this->reorderPhases($project);

        $form = $this->createForm(ProjectType::class, $project, [
            'show_commercial_tier' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $creator */
            $creator = $this->getUser();

            $project->setUser($creator);

            $membership = (new ProjectMembership())
                ->setUser($creator)
                ->setProject($project)
                ->setProjectRole('owner');

            $project->addProjectMembership($membership);

            $this->ensureBasicSubscription($project);

            $em->persist($project);
            $em->persist($membership);
            $em->flush();

            $activeProjectService->setActiveProject($project);

            $this->addFlash('success', 'backend.projects.flash.created');
            return $this->redirectToRoute('backend_project_index');
        }

        return $this->render('backend/project/form.html.twig', [
            'form' => $form->createView(),
            'edit' => false,
            'commercialTier' => $this->featureGate->getTier($project),
            'commercialTierLabel' => $this->featureGate->getPlanLabel($project),
            'commercialTierDescription' => $this->featureGate->getPlanDescription($project),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $this->reorderPhases($project);
        $showCommercialTier = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPER_ADMIN');

        // Guardar fases originales antes de modificar
        $originalPhases = new ArrayCollection();
        foreach ($project->getPhaseDates() as $phaseDate) {
            $originalPhases->add($phaseDate);
        }

        $form = $this->createForm(ProjectType::class, $project, [
            'show_commercial_tier' => $showCommercialTier,
        ]);
        if ($form->has('commercialTier')) {
            $form->get('commercialTier')->setData($this->featureGate->getTier($project));
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Eliminar fases eliminadas en el formulario
            foreach ($originalPhases as $originalPhase) {
                if (!$project->getPhaseDates()->contains($originalPhase)) {
                    $em->remove($originalPhase);
                }
            }

            if ($showCommercialTier && $form->has('commercialTier')) {
                $this->syncCommercialTier($project, (string) $form->get('commercialTier')->getData(), $em);
            }

            $em->flush();

            $this->addFlash('success', 'backend.projects.flash.updated');
            return $this->redirectToRoute('backend_project_index');
        }

        $lockedPhases = [];
        foreach ($project->getPhaseDates() as $phaseDate) {
            $lockedPhases[$phaseDate->getId()] = $em->getRepository(EmissionRecord::class)
                ->count(['phase' => $phaseDate]) > 0;
        }

        return $this->render('backend/project/form.html.twig', [
            'form'         => $form->createView(),
            'edit'         => true,
            'project'      => $project,
            'lockedPhases' => $lockedPhases
            ,
            'commercialTier' => $this->featureGate->getTier($project),
            'commercialTierLabel' => $this->featureGate->getPlanLabel($project),
            'commercialTierDescription' => $this->featureGate->getPlanDescription($project),
        ]);
    }

    private function reorderPhases(Project $project): void
    {
        $phases = $project->getPhaseDates()->toArray();
        $orderedPhases = [];

        foreach (['preproduccion', 'actividad', 'postproduccion'] as $key) {
            foreach ($phases as $phaseDate) {
                if ($phaseDate->getPhase() === $key) {
                    $orderedPhases[] = $phaseDate;
                    break;
                }
            }
        }

        $project->getPhaseDates()->clear();
        foreach ($orderedPhases as $phaseDate) {
            $project->addPhaseDate($phaseDate);
        }
    }

    #[Route('/{id}/clone', name: 'clone', methods: ['GET'])]
    public function clone(Project $project, EntityManagerInterface $em): RedirectResponse
    {
        /** @var User $creator */
        $creator = $this->getUser();

        // 1) Clonar datos básicos del proyecto
        $newProject = new Project();
        $this->ensureBasicSubscription($newProject);
        $newProject
            ->setName($project->getName() . ' (copia)')
            ->setType($project->getType())
            ->setCountry($project->getCountry())
            ->setEmissionSourceName($project->getEmissionSourceName())
            ->setUser($creator)
            ->setCreatedAt(new \DateTimeImmutable());

        // ---- copiar campos de RODAJE ----
        $newProject
            ->setFilmingType($project->getFilmingType())
            ->setFilmingGenre($project->getFilmingGenre())
            ->setIsLiveTv($project->isLiveTv())
            ->setIsAdvert($project->isAdvert())
            ->setIsCorporateVideo($project->isCorporateVideo())
            ->setIsMusicVideo($project->isMusicVideo())
            ->setIsOnlineContent($project->isOnlineContent())
            ->setIsShooting($project->isShooting());

        // ---- copiar campos de EVENTO ----
        $newProject
            ->setEventTypePrimary($project->getEventTypePrimary())
            ->setEventModality($project->getEventModality())
            ->setEventAttendeesType($project->getEventAttendeesType())
            ->setEventAttendeesCount($project->getEventAttendeesCount());

        // ---- copiar campos COMUNES (texto) ----
        $newProject
            ->setMedio($project->getMedio())
            ->setPresupuesto($project->getPresupuesto())
            ->setCine($project->getCine())
            ->setFechas($project->getFechas())
            ->setTvField($project->getTvField())
            ->setPlataformasStreaming($project->getPlataformasStreaming())
            ->setAgencia($project->getAgencia())
            ->setInternet($project->getInternet())
            ->setRedesSociales($project->getRedesSociales())
            ->setFotografia($project->getFotografia())
            ->setRadio($project->getRadio())
            ->setEpisodios($project->getEpisodios())
            ->setDuracionEpisodio($project->getDuracionEpisodio())
            ->setProductora($project->getProductora());

        $em->persist($newProject);
        $em->flush(); // obtener ID

        // 2) OWNER = usuario autenticado
        $membership = (new ProjectMembership())
            ->setUser($creator)
            ->setProject($newProject)
            ->setProjectRole('owner');

        $newProject->addProjectMembership($membership);
        $em->persist($membership);

        // 3) Clonar fases
        foreach ($project->getPhaseDates() as $phase) {
            $newPhase = new ProjectPhaseDate();
            $newPhase
                ->setProject($newProject)
                ->setPhase($phase->getPhase())
                ->setStartDate($phase->getStartDate())
                ->setEndDate($phase->getEndDate());

            $em->persist($newPhase);
        }

        $em->flush();

        $this->addFlash('success', 'backend.projects.flash.cloned');

        return $this->redirectToRoute('backend_project_index');
    }

    private function ensureBasicSubscription(Project $project): ProjectSubscription
    {
        $subscription = $project->getSubscription();
        if (!$subscription) {
            $subscription = new ProjectSubscription();
            $project->setSubscription($subscription);
        }

        $subscription
            ->setTier(ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_SYSTEM)
            ->setCurrency('EUR')
            ->setPaidAmountCents(null)
            ->setPaymentReference(null)
            ->setStripeCheckoutSessionId(null)
            ->setStripePaymentIntentId(null)
            ->setStripeInvoiceId(null)
            ->setStripeCustomerId(null)
            ->setStripeHostedInvoiceUrl(null)
            ->setStripeInvoicePdfUrl(null)
            ->setLastPaymentStatus(null)
            ->setPaidAt(null)
            ->setTargetTier(null);

        return $subscription;
    }

    private function syncCommercialTier(Project $project, string $tier, EntityManagerInterface $em): void
    {
        $subscription = $project->getSubscription() ?? new ProjectSubscription();
        $subscription
            ->setProject($project)
            ->setTier(in_array($tier, [ProjectSubscription::TIER_BASIC, ProjectSubscription::TIER_STANDARD, ProjectSubscription::TIER_PRO], true) ? $tier : ProjectSubscription::TIER_BASIC)
            ->setStatus(ProjectSubscription::STATUS_ACTIVE)
            ->setSource(ProjectSubscription::SOURCE_MANUAL)
            ->setPaidAmountCents(null)
            ->setPaymentReference(null)
            ->setStripeCheckoutSessionId(null)
            ->setStripePaymentIntentId(null)
            ->setStripeInvoiceId(null)
            ->setStripeCustomerId(null)
            ->setStripeHostedInvoiceUrl(null)
            ->setStripeInvoicePdfUrl(null)
            ->setLastPaymentStatus(null)
            ->setPaidAt(null)
            ->setTargetTier(null);

        if (!$project->getSubscription()) {
            $project->setSubscription($subscription);
            $em->persist($subscription);
        }
    }

    #[Route('/{id}/edit-crew', name: 'edit_crew')]
    public function editCrew(
        Project $project,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        // Miembros originales
        $originalMembers = new ArrayCollection();
        foreach ($project->getCrewMembers() as $member) {
            $originalMembers->add($member);
        }

        $form = $this->createForm(CrewMemberCollectionType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($originalMembers as $originalMember) {
                if (!$project->getCrewMembers()->contains($originalMember)) {
                    $em->remove($originalMember);
                }
            }

            $em->flush();

            $this->addFlash('success', 'backend.projects.flash.crew_updated');
            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        return $this->render('backend/project/edit_crew.html.twig', [
            'form'    => $form->createView(),
            'project' => $project,
        ]);
    }

    #[Route('/crew/template/download', name: 'template_download', methods: ['GET'])]
    public function downloadCrewTemplate(
        PositionRepository $positionRepository,
        DepartmentRepository $departmentRepository,
        ActiveProjectService $activeProjectService,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet();

        // Hoja principal
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->t->trans('backend.projects.crew.template.sheet_title'));

        // Encabezados
        $headers = [
            $this->t->trans('backend.projects.crew.template.headers.name'),
            $this->t->trans('backend.projects.crew.template.headers.last_name'),
            $this->t->trans('backend.projects.crew.template.headers.position'),
            $this->t->trans('backend.projects.crew.template.headers.department'),
            $this->t->trans('backend.projects.crew.template.headers.email'),
            $this->t->trans('backend.projects.crew.template.headers.phone'),
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        // === 1) Filtrado por tipo de proyecto ===
        $projectType = $activeProjectService->getActiveProject()?->getType(); // 'rodaje' | 'evento' | null
        // Criterio: permitir departamentos genéricos (null) y los del tipo
        $allDepartments = $departmentRepository->findAll();
        $allowedDepartments = array_values(array_filter($allDepartments, function (\App\Entity\Department $d) use ($projectType) {
            $pt = $d->getProjectType(); // null | 'rodaje' | 'evento'
            return $pt === null || ($projectType !== null && $pt === $projectType);
        }));

        // Indice rápido por ID para validar posiciones
        $allowedDeptIds = array_fill_keys(array_map(fn($d) => $d->getId(), $allowedDepartments), true);

        // Cargos sólo de departamentos permitidos
        $allPositions = $positionRepository->findAll();
        $positions = array_values(array_filter($allPositions, function (\App\Entity\Position $p) use ($allowedDeptIds) {
            $dept = $p->getDepartment();
            return $dept && isset($allowedDeptIds[$dept->getId()]);
        }));

        // Si por algún motivo no hay nada permitido, caemos a todos (evita plantilla vacía)
        if (empty($allowedDepartments)) {
            $allowedDepartments = $allDepartments;
            $allowedDeptIds = array_fill_keys(array_map(fn($d) => $d->getId(), $allowedDepartments), true);
            $positions = array_values(array_filter($allPositions, function (\App\Entity\Position $p) use ($allowedDeptIds) {
                $dept = $p->getDepartment();
                return $dept && isset($allowedDeptIds[$dept->getId()]);
            }));
        }

        // === 2) Hoja oculta "Listas" con el mapeo Departamento→Cargo y lista única de Deptos ===
        $listsTitle = 'Listas';
        $listSheet = new Worksheet($spreadsheet, $listsTitle);
        $spreadsheet->addSheet($listSheet);

        // A: Departamento (por fila), B: Cargo (por fila)
        $rowAB = 1;
        foreach ($positions as $pos) {
            $dept = $pos->getDepartment();
            if (!$dept) { continue; }
            $listSheet->setCellValue("A{$rowAB}", $dept->getName());
            $listSheet->setCellValue("B{$rowAB}", $pos->getName());
            $rowAB++;
        }
        $mapCount = $rowAB - 1;

        // Lista única de departamentos permitidos (columna D)
        $uniqueDeptNames = [];
        foreach ($allowedDepartments as $d) {
            $uniqueDeptNames[$d->getName()] = true;
        }
        $uniqueDeptNames = array_keys($uniqueDeptNames);
        sort($uniqueDeptNames, SORT_NATURAL | SORT_FLAG_CASE);

        $rowD = 1;
        foreach ($uniqueDeptNames as $dn) {
            $listSheet->setCellValue("D{$rowD}", $dn);
            $rowD++;
        }
        $deptCount = $rowD - 1;

        $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // === 3) Validaciones de datos (dependientes) ===
        $maxRows = 100;

        // Departamento (col D): lista de 'Listas'!D1:D{deptCount}
        for ($row = 2; $row <= $maxRows; $row++) {
            $dvDept = new DataValidation();
            $dvDept->setType(DataValidation::TYPE_LIST);
            $dvDept->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $dvDept->setAllowBlank(true);
            $dvDept->setShowDropDown(true);
            $dvDept->setFormula1("'{$listsTitle}'!\$D\$1:\$D\$" . max(1, $deptCount));
            $sheet->getCell("D{$row}")->setDataValidation($dvDept);
        }

        // Cargo (col C): dependiente del valor de D{row}
        for ($row = 2; $row <= $maxRows; $row++) {
            $formula = sprintf(
                '=OFFSET(%s!$B$1, MATCH($D%d, %s!$A:$A, 0)-1, 0, COUNTIF(%s!$A:$A, $D%d), 1)',
                $listsTitle, $row, $listsTitle, $listsTitle, $row
            );
            $dvPos = new DataValidation();
            $dvPos->setType(DataValidation::TYPE_LIST);
            $dvPos->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $dvPos->setAllowBlank(true);
            $dvPos->setShowDropDown(true);
            $dvPos->setFormula1($formula);
            $sheet->getCell("C{$row}")->setDataValidation($dvPos);
        }

        // === 4) Filas de ejemplo COHERENTES con el tipo ===
        $exampleDept = $uniqueDeptNames[0] ?? $this->t->trans('backend.projects.crew.template.example.department');

        // Busca 1–2 cargos que pertenezcan a ese departamento ejemplo dentro del mapeo filtrado
        $posExamples = [];
        if ($mapCount > 0 && $deptCount > 0) {
            for ($r = 1; $r <= $mapCount; $r++) {
                $dn = (string)$listSheet->getCell("A{$r}")->getValue();
                $pn = (string)$listSheet->getCell("B{$r}")->getValue();
                if ($dn === $exampleDept && $pn !== '') {
                    if (!in_array($pn, $posExamples, true)) {
                        $posExamples[] = $pn;
                    }
                    if (count($posExamples) >= 2) break;
                }
            }
        }

        // Si no logramos encontrar cargos de ejemplo coherentes, cae a un placeholder
        $examplePos1 = $posExamples[0] ?? $this->t->trans('backend.projects.crew.template.example.position');
        $examplePos2 = $posExamples[1] ?? $examplePos1;

        // Relleno de ejemplos
        $sheet->fromArray(
            [
                $this->t->trans('backend.projects.crew.template.example.name1'),
                $this->t->trans('backend.projects.crew.template.example.last_name1'),
                $examplePos1, $exampleDept,
                'ana.perez@email.com',
                '+34 600 123 456'
            ],
            null,
            'A2'
        );
        $sheet->fromArray(
            [
                $this->t->trans('backend.projects.crew.template.example.name2'),
                $this->t->trans('backend.projects.crew.template.example.last_name2'),
                $examplePos2, $exampleDept,
                'luis.garcia@email.com',
                '+34 600 987 654'
            ],
            null,
            'A3'
        );

        // === 5) Hoja "Referencias" (informativa) solo con permitidos ===
        $infoSheet = new Worksheet($spreadsheet, $this->t->trans('backend.projects.crew.template.info_sheet'));
        $spreadsheet->addSheet($infoSheet);

        $infoSheet->setCellValue('A1', $this->t->trans('backend.projects.crew.template.info_headers.departments'));
        $infoSheet->setCellValue('B1', $this->t->trans('backend.projects.crew.template.info_headers.sample_position'));

        $r = 2;
        foreach ($uniqueDeptNames as $dn) {
            $infoSheet->setCellValue("A{$r}", $dn);
            $r++;
        }

        $seen = [];
        $r = 2;
        for ($i = 1; $i <= $mapCount; $i++) {
            $dn = (string)$listSheet->getCell("A{$i}")->getValue();
            $pn = (string)$listSheet->getCell("B{$i}")->getValue();
            if ($dn !== '' && $pn !== '' && !isset($seen[$dn])) {
                $infoSheet->setCellValue("B{$r}", $pn);
                $seen[$dn] = true;
                $r++;
            }
        }

        // Anchos
        foreach (['A','B','C','D','E','F'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $infoSheet->getColumnDimension('A')->setAutoSize(true);
        $infoSheet->getColumnDimension('B')->setAutoSize(true);

        // Descargar
        $filename = $this->t->trans('backend.projects.crew.template.filename');

        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/{id}/import-crew', name: 'import_crew', methods: ['POST'])]
    public function importCrew(
        Project $project,
        Request $request,
        EntityManagerInterface $em
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $file = $request->files->get('crewFile');
        if (!$file) {
            $this->addFlash('danger', 'backend.projects.flash.crew_import_no_file');
            return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
        }

        [$ok, $messages] = $this->processCrewFile($file, $project, $em);

        if (!$ok) {
            foreach ($messages as $msg) {
                $this->addFlash('danger', $msg);
            }
        } else {
            $em->flush();
            $this->addFlash('success', 'backend.projects.flash.crew_import_ok');
            // avisos no bloqueantes
            foreach ($messages as $warn) {
                $this->addFlash('warning', $warn);
            }
        }

        return $this->redirectToRoute('backend_project_edit_crew', ['id' => $project->getId()]);
    }

    private function processCrewFile($file, Project $project, EntityManagerInterface $em): array
    {
        if (!$file) {
            return [false, [$this->t->trans('backend.projects.crew.import.errors.no_file')]];
        }

        try {
            $spreadsheet = IOFactory::load($file->getPathname());
        } catch (\Throwable $e) {
            return [false, [$this->t->trans('backend.projects.crew.import.errors.read_failed')]];
        }

        $sheet = $spreadsheet->getActiveSheet();

        // Normalizador simple (minúsculas + quitar tildes básicas)
        $norm = function (?string $s): string {
            $s = trim((string) $s);
            $s = mb_strtolower($s);
            $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
            return $s;
        };

        // === Cabeceras (fila 1), tolera columnas extra ===
        $headerCells = [];
        $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn()); // p.ej. "G" -> 7
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col); // 1 -> "A"
            $val = (string) $sheet->getCell($letter . '1')->getValue();
            if ($val !== '') {
                $headerCells[$col] = $val;
            }
        }

        // Aliases por campo admitidos (ES/EN)
        $aliases = [
            'name'       => ['nombre', 'name', 'first name', 'firstname', 'first_name'],
            'last_name'  => ['apellido', 'apellidos', 'last name', 'lastname', 'last_name', 'surname'],
            'position'   => ['cargo', 'puesto', 'position', 'role', 'job title', 'job'],
            'department' => ['departamento', 'department'],
            'email'      => ['email', 'e-mail', 'mail', 'correo', 'correo electronico', 'email address'],
            'phone'      => ['telefono', 'teléfono', 'phone', 'telephone', 'phone number', 'mobile'],
        ];

        // Mapea columna → campo
        $colIdx = [
            'name'       => null,
            'last_name'  => null,
            'position'   => null,
            'department' => null,
            'email'      => null,
            'phone'      => null,
        ];

        foreach ($headerCells as $colNum => $raw) {
            $h = $norm($raw);
            foreach ($aliases as $field => $list) {
                if (in_array($h, $list, true) && $colIdx[$field] === null) {
                    $colIdx[$field] = $colNum;
                    break;
                }
            }
        }

        // Campo mínimo requerido
        if ($colIdx['name'] === null) {
            return [false, [$this->t->trans('backend.projects.crew.import.errors.bad_format')]];
        }

        $errors   = [];
        $warnings = [];
        $projectType = $project->getType(); // 'rodaje' | 'evento'

        // Itera filas con datos
        $maxRow = $sheet->getHighestRow();
        for ($i = 2; $i <= $maxRow; $i++) {
            $get = function (?int $col) use ($sheet, $i): string {
                if (!$col) return '';
                $letter = Coordinate::stringFromColumnIndex($col);
                return trim((string) $sheet->getCell($letter . $i)->getValue());
            };

            $name           = $get($colIdx['name']);
            $lastName       = $get($colIdx['last_name']);
            $positionName   = $get($colIdx['position']);
            $departmentName = $get($colIdx['department']);
            $email          = $get($colIdx['email']);
            $phone          = $get($colIdx['phone']);

            // Fila vacía
            if ($name === '' && $lastName === '' && $positionName === '' && $departmentName === '' && $email === '' && $phone === '') {
                continue;
            }

            if ($name === '') {
                $errors[] = $this->t->trans('backend.projects.crew.import.errors.name_required', ['%row%' => $i]);
                continue;
            }

            // Buscar/crear por email+proyecto
            $member = null;
            if ($email !== '') {
                $member = $em->getRepository(CrewMember::class)->findOneBy([
                    'email'   => $email,
                    'project' => $project,
                ]);
            }
            if (!$member) {
                $member = new CrewMember();
            }

            $member->setProject($project);
            $member->setName($name);
            $member->setLastName($lastName !== '' ? $lastName : null);
            $member->setEmail($email !== '' ? $email : null);
            $member->setPhone($phone !== '' ? $phone : null);

            $projectType = $project->getType(); // 'rodaje' | 'evento'

            /// Department por nombre y tipo del proyecto (o genérico)
            $department = null;
            if ($departmentName !== '') {
                $department = $this->resolveDepartmentByAnyLocale($em, $departmentName, $projectType);
                if (!$department) {
                    $errors[] = $this->t->trans('backend.projects.crew.import.errors.department_not_found', [
                        '%line%'       => $i,
                        '%dept%'      => $departmentName,
                        '%type%' => $projectType,
                    ]);
                    continue;
                }
            }

            // Position (acotada por departamento si existe)
            $position = null;
            if ($positionName !== '') {
                $position = $this->resolvePositionByAnyLocale($em, $positionName, $department);
                if (!$position) {
                    $errors[] = $this->t->trans('backend.projects.crew.import.errors.position_not_found', [
                        '%row%'  => $i,
                        '%pos%'  => $positionName,
                        '%dept%' => $department?->getName() ?? '',
                    ]);
                    continue;
                }
            }

            // Coherencia Department/Position
            if ($position && $position->getDepartment()) {
                $posDept = $position->getDepartment();
                if ($department && $department->getId() !== $posDept->getId()) {
                    $warnings[] = $this->t->trans('backend.projects.crew.import.warnings.position_overrides_department', [
                        '%row%'  => $i,
                        '%pos%'  => $positionName,
                        '%dept%' => $posDept->getName(),
                    ]);
                }
                $member->setDepartment($posDept);
            } else {
                $member->setDepartment($department);
            }

            $member->setPosition($position ?: null);
            $em->persist($member);
        }

        return [count($errors) === 0, array_merge($warnings, $errors)];
    }

    private function resolveDepartmentByAnyLocale(
        EntityManagerInterface $em,
        string $name,
        ?string $projectType // 'rodaje' | 'evento' | null
    ): ?Department {
        $nameLower = mb_strtolower(trim($name));

        // 1) Match por nombre base (p.ej. español)
        $qb = $em->getRepository(Department::class)->createQueryBuilder('d')
            ->andWhere('LOWER(d.name) = :n')->setParameter('n', $nameLower);
        if ($projectType !== null) {
            $qb->andWhere('(d.projectType IS NULL OR d.projectType = :t)')->setParameter('t', $projectType);
        }
        $dep = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($dep) return $dep;

        // 2) Match por traducción (en, …)
        $qbT = $em->createQueryBuilder()
            ->select('d')
            ->from(Department::class, 'd')
            ->join(Translation::class, 't', 'WITH',
                't.objectClass = :cls AND t.field = :field AND t.foreignKey = d.id'
            )
            ->andWhere('LOWER(t.content) = :n')
            ->setParameter('cls', Department::class)
            ->setParameter('field', 'name')
            ->setParameter('n', $nameLower);
        if ($projectType !== null) {
            $qbT->andWhere('(d.projectType IS NULL OR d.projectType = :t)')->setParameter('t', $projectType);
        }
        return $qbT->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function resolvePositionByAnyLocale(
        EntityManagerInterface $em,
        string $name,
        ?Department $department
    ): ?Position {
        $nameLower = mb_strtolower(trim($name));

        // 1) Match por nombre base
        $qb = $em->getRepository(Position::class)->createQueryBuilder('p')
            ->andWhere('LOWER(p.name) = :n')->setParameter('n', $nameLower);
        if ($department) {
            $qb->andWhere('p.department = :d')->setParameter('d', $department);
        }
        $pos = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($pos) return $pos;

        // 2) Match por traducción
        $qbT = $em->createQueryBuilder()
            ->select('p')
            ->from(Position::class, 'p')
            ->join(Translation::class, 't', 'WITH',
                't.objectClass = :cls AND t.field = :field AND t.foreignKey = p.id'
            )
            ->andWhere('LOWER(t.content) = :n')
            ->setParameter('cls', Position::class)
            ->setParameter('field', 'name')
            ->setParameter('n', $nameLower);
        if ($department) {
            $qbT->andWhere('p.department = :d')->setParameter('d', $department);
        }
        return $qbT->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        // Autorización: ADMIN o miembro del proyecto
        if (!$this->isGranted('ROLE_ADMIN')) {
            $currentUser = $this->getUser();
            $isMember = false;
            foreach ($project->getProjectMemberships() as $m) {
                if ($m->getUser() === $currentUser) { $isMember = true; break; }
            }
            if (!$isMember) { throw $this->createAccessDeniedException(); }
        }

        if ($this->isCsrfTokenValid('delete'.$project->getId(), $request->request->get('_token'))) {
            try {
                $plan = $em->getRepository(Plan::class)->findOneBy(['project' => $project]);
                $emissions = $em->getRepository(EmissionRecord::class)->findBy(['project' => $project]);

                if ($emissions) {
                    $this->addFlash('danger', 'backend.projects.flash.delete_has_emissions');
                    return $this->redirectToRoute('backend_project_index');
                }

                if ($plan) {
                    $measures = count($plan->getPlanMeasures());
                    if ($measures) {
                        $this->addFlash('danger', $this->t->trans('backend.projects.flash.delete_has_measures', [
                            '%count%' => $measures
                        ]));
                        return $this->redirectToRoute('backend_project_index');
                    } else {
                        $em->remove($plan);
                    }
                }

                $em->remove($project);
                $em->flush();
                $this->addFlash('success', 'backend.projects.flash.deleted');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'backend.projects.flash.delete_failed');
            }
        }

        return $this->redirectToRoute('backend_project_index');
    }

    #[Route('/select-project/{id}', name: 'select_project', methods: ['POST','GET'])]
    public function selectProject(Project $project, ActiveProjectService $activeProjectService): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $activeProjectService->setActiveProject($project);

        return $this->redirectToRoute('app_backend');
    }
}
