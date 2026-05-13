<?php

namespace App\Controller\Backend;

use App\Entity\{Plan, PlanMeasure, Measure, Ods, EsG, Scope, Project, Protocol, CrewMember, Category, Department};
use App\Repository\{PlanRepository, MeasureRepository, PlanMeasureRepository, ProtocolRepository};
use App\Service\PlanMeasureCatalogResolver;
use App\Service\ProjectFeatureGate;
use App\Security\PlanVoter;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use Dompdf\{Dompdf, Options};
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/plan', name: 'backend_plan_')]
#[IsGranted('ROLE_USER')]
class PlanController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $t,
        private PlanMeasureCatalogResolver $catalogResolver,
        private ProjectFeatureGate $featureGate
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);

        // Si no hay plan o no hay protocolo seleccionado -> ir a Welcome
        if (!$plan || !$plan->getProtocol()) {
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // Si hay plan y está completo -> ir a review
        if ($plan && $plan->getStatus() === 'completo') {
            return $this->redirectToRoute('backend_plan_review');
        }

        // Con protocolo seleccionado -> ir a Medidas
        return $this->redirectToRoute('backend_plan_measures');
    }

    #[Route('/welcome', name: 'welcome', methods: ['GET'])]
    public function welcome(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        ProtocolRepository $protocolRepository,
        PlanMeasureRepository $planMeasureRepo,
        EntityManagerInterface $em
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            $plan = (new Plan())->setProject($project)->setUser($this->getUser());
            $em->persist($plan);
            $em->flush();
        }

        // Si ya hay alguna medida en el plan, ir a measures directamente
        $alreadyHasMeasures = (bool) $planMeasureRepo->findOneBy(['plan' => $plan]);
        if ($alreadyHasMeasures) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        // Protocolos aplicables al tipo de proyecto (evento/rodaje/ambos)
        $protocolNames = $protocolRepository->getNamesForProjectType($project->getType());
        $protocols = $protocolRepository->findBy(['name' => $protocolNames], ['name' => 'ASC']);

        return $this->render('backend/plan/welcome.html.twig', [
            'project'   => $project,
            'plan'      => $plan,
            'protocols' => $protocols,
        ]);
    }

    #[Route('/select-protocol', name: 'select_protocol', methods: ['POST'])]
    public function selectProtocol(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            $plan = (new Plan())->setProject($project)->setUser($this->getUser());
            $em->persist($plan);
            $em->flush();
        }

        $protocolId = $request->request->get('protocol_id');
        if (!$protocolId) {
            $this->addFlash('warning', 'backend.plan.flash.select_protocol_required');
            return $this->redirectToRoute('backend_plan_welcome');
        }

        /** @var Protocol|null $protocol */
        $protocol = $protocolRepository->find($protocolId);
        if (!$protocol) {
            $this->addFlash('danger', 'backend.plan.flash.invalid_protocol');
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // Persistimos en el plan
        $plan->setProtocol($protocol);
        $em->flush();

        return $this->redirectToRoute('backend_plan_measures');
    }

    #[Route('/measures', name: 'measures', methods: ['GET','POST'])]
    public function measures(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        PlanMeasureRepository $planMeasureRepository,
        EntityManagerInterface $em
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan || !$plan->getProtocol()) {
            $this->addFlash('info', 'backend.plan.flash.select_protocol_to_continue');
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // VOTER: puede editar el plan (miembro/admin)
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        $protocol     = $plan->getProtocol();
        $groupingBy   = $protocol->getGroupingBy(); // 'category' | 'department'
        $isDept       = ($groupingBy === Protocol::GROUP_BY_DEPARTMENT);
        $groupNullLbl = $isDept ? 'Sin departamento' : 'Sin categoría';

        // POST: guardar texto y actualizar estado a completo/incompleto
        if ($request->isMethod('POST')) {
            $text = trim((string) $request->request->get('custom_measures', ''));
            $plan->setCustomMeasures($text !== '' ? $text : null);

            $planComplete = $this->isPlanCompleteForProtocol($plan, $project, $measureRepository);
            $plan->setStatus($planComplete ? 'completo' : 'incompleto');
            $plan->setStatusChangedAt(new \DateTimeImmutable());
            $em->flush();

            $this->addFlash(
                'success',
                $planComplete
                    ? 'backend.plan.flash.completed'
                    : 'backend.plan.flash.saved_incomplete'
            );

            return $planComplete
                ? $this->redirectToRoute('backend_plan_done')
                : $this->redirectToRoute('backend_plan_measures');
        }

        // Si ya está completo, dirige a done (resumen)
        $planComplete = $this->isPlanCompleteForProtocol($plan, $project, $measureRepository);
        if ($planComplete) {
            // Mantén el status sincronizado
            if ($plan->getStatus() !== 'completo') {
                $plan->setStatus('completo');
                $plan->setStatusChangedAt(new \DateTimeImmutable());
                $em->flush();
            }
            return $this->redirectToRoute('backend_plan_done');
        } else {
            if ($plan->getStatus() !== 'incompleto') {
                $plan->setStatus('incompleto');
                $plan->setStatusChangedAt(new \DateTimeImmutable());
                $em->flush();
            }
        }

        // ===== Medidas del protocolo seleccionado (ORDER BY dinámico: categoría o departamento) =====
        $qb = $measureRepository->createQueryBuilder('m')
            ->join('m.protocol', 'p')
            ->leftJoin('m.category', 'c')
            ->leftJoin('m.department', 'd')
            ->andWhere('p = :protocol')
            ->setParameter('protocol', $protocol);
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p', $project);

        if ($isDept) {
            // Orden principal por Departamento
            $qb->addOrderBy('d.name', 'ASC')
            ->addOrderBy('m.name', 'ASC');
        } else {
            // Orden principal por Categoría (comportamiento actual)
            $qb->addOrderBy('c.name', 'ASC')
            ->addOrderBy('m.name', 'ASC');
        }

        $measures = $qb->getQuery()->getResult();

        $total = count($measures);
        $index = max(0, min($total - 1, $request->query->getInt('i', 0)));
        $currentMeasure = $measures[$index] ?? null;

        // ¿Plan completo?
        $planComplete = $this->isPlanCompleteForProtocol($plan, $project, $measureRepository);
        if ($planComplete && $index > 0) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        // ===== START mensajes de "cambios de grupo" (categoría/departamento) =====
        $session   = $request->getSession();
        $project   = $activeProjectService->getActiveProject();
        $projectId = $project?->getId() ?? 0;

        // Clave de sesión por proyecto + tipo de agrupación para no mezclar modos
        $sessionKey = sprintf('plan_prev_group_%d_%s', $projectId, $groupingBy);

        // Valor previo del grupo (cat/dep) en esta vista
        $prevGroupName = $session->get($sessionKey);

        // Nombre del grupo actual (dep/cat)
        $currentGroupName = null;
        if ($currentMeasure) {
            $currentGroupName = $isDept
                ? ($currentMeasure->getDepartment()?->getName() ?? $groupNullLbl)
                : ($currentMeasure->getCategory()?->getName() ?? $groupNullLbl);
        }

        // Buscar el siguiente nombre de grupo distinto (si existe)
        $nextGroupName = null;
        if ($currentGroupName !== null) {
            for ($j = $index + 1; $j < $total; $j++) {
                $nameJ = $isDept
                    ? ($measures[$j]->getDepartment()?->getName() ?? $groupNullLbl)
                    : ($measures[$j]->getCategory()?->getName() ?? $groupNullLbl);

                if ($nameJ !== $currentGroupName) {
                    $nextGroupName = $nameJ;
                    break;
                }
            }
        }

        // ¿Cambió el grupo respecto al guardado?
        $groupChanged = ($prevGroupName !== null && $currentGroupName !== null && $prevGroupName !== $currentGroupName);

        // Guardar grupo actual para el próximo render
        if ($currentGroupName !== null) {
            $session->set($sessionKey, $currentGroupName);
        }

        /**
         * Qué nombre mostrar en el mensaje "vamos a continuar con ..."
         * - Si ACABAMOS de cambiar de grupo (groupChanged === true), el destino es el GRUPO ACTUAL.
         * - Si NO hubo cambio, el destino (si existe) es el siguiente grupo distinto al actual.
         */
        $displayNextGroupName = $groupChanged ? $currentGroupName : $nextGroupName;
        // ===== END mensajes de "cambios de grupo" (categoría/departamento) =====

        // ===== PM actual (si existe) y lógica de navegación =====
        $currentPm = $currentMeasure
            ? $planMeasureRepository->findOneBy(['plan' => $plan, 'measure' => $currentMeasure])
            : null;

        $canGoNext = false;
        if ($currentPm) {
            $applies  = $currentPm->isApplicable();
            $critical = $currentPm->isCritical();
            $willImpl = $currentPm->willImplement();

            if ($applies === false) {
                $canGoNext = true;
            } elseif ($applies === true) {
                if ($critical !== null && $willImpl !== null) {
                    $canGoNext = true;
                }
            }
        }

        // --- Medidas obligatorias: reglas especiales ---
        $mandatory = $currentMeasure ? (bool) $currentMeasure->isMandatory() : false;
        if ($canGoNext && $mandatory) {
            $hasBothTrue = $currentPm
                && $currentPm->isApplicable() === true
                && $currentPm->willImplement() === true;

            if (!$hasBothTrue) {
                $canGoNext = false;
                $this->addFlash('warning', 'backend.plan.measures.mandatory_warning');
            }
        }

        // ===== Gráficos =====
        $measuresTotal = $total;
        $effective = $nonApplicable = $agreed = $implemented = 0;
        foreach ($plan->getPlanMeasures() as $pm) {
            if ($pm->getMeasure()?->getProtocol()?->getId() === $protocol->getId()) {
                if ($pm->isApplicable() === true)  $effective++;
                if ($pm->isApplicable() === false) $nonApplicable++;
                if ($pm->willImplement())          $agreed++;
                if ($pm->isImplemented())          $implemented++;
            }
        }

        $planChartsConfig = $this->buildChartsConfig(
            $measuresTotal,
            $effective,
            $nonApplicable,
            $agreed,
            $implemented
        );

        // ===== Puntuación (no persistente) =====
        $pmIndex = [];
        foreach ($plan->getPlanMeasures() as $pm) {
            if ($pm->getMeasure()?->getProtocol()?->getId() === $protocol->getId()) {
                $pmIndex[$pm->getMeasure()->getId()] = $pm;
            }
        }

        $scoreMax = 0;
        $scoreGained = 0;
        foreach ($measures as $m) {
            $score = (int) ($m->getScore() ?? 0);
            $scoreMax += $score;

            if ($score > 0 && isset($pmIndex[$m->getId()])) {
                $pm = $pmIndex[$m->getId()];
                if ($pm->isApplicable() === true && $pm->willImplement() === true) {
                    $scoreGained += $score;
                }
            }
        }

        // ===== Render =====
        return $this->render('backend/plan/measures.html.twig', [
            'project'          => $project,
            'plan'             => $plan,
            'projectTier'      => $this->featureGate->getTier($project),
            'commercialCards'  => $this->buildCommercialFeatureCards($project),
            'hasWatermark'     => $this->featureGate->hasWatermark($project),

            // navegación y medida actual
            'index'            => $index,
            'total'            => $total,
            'measure'          => $planComplete ? null : $currentMeasure,
            'planMeasures'     => $plan->getPlanMeasures(),
            'canGoNext'        => !$planComplete && $canGoNext,
            'planComplete'     => $planComplete,

            // sesión/categorías para twig (ahora representan el grupo activo)
            'groupChanged'   => $groupChanged ? 'si' : 'no',
            'prevGroupName'  => $prevGroupName,
            'nextGroupName'  => $displayNextGroupName,
            'groupingBy'     => $groupingBy,

            // gráficos
            'planChartsConfig' => $planChartsConfig,

            // puntuación
            'scoreGained'      => $scoreGained,
            'scoreMax'         => $scoreMax,
        ]);
    }

    #[Route('/done', name: 'done', methods: ['GET'])]
    public function done(
        ActiveProjectService $activeProjectService
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) return $this->redirectToRoute('app_backend');

        return $this->render('backend/plan/done.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function deletePlan(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        EntityManagerInterface $em
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            $this->addFlash('info', 'backend.plan.flash.no_plan_for_project');
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // VOTER: solo miembros pueden editar/borrar el plan
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        try {
            // 1) Eliminar plan (con orphanRemoval, se van PlanMeasure asociados)
            $em->remove($plan);
            $em->flush();

            // 2) Limpiar variables de sesión relacionadas con la navegación por grupos
            $session   = $request->getSession();
            $projectId = $project->getId();

            $session->remove(sprintf('plan_prev_group_%d_category', $projectId));
            $session->remove(sprintf('plan_prev_group_%d_department', $projectId));

            $this->addFlash('success', 'backend.plan.flash.deleted');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'backend.plan.flash.delete_failed');
        }

        return $this->redirectToRoute('backend_plan_welcome');
    }

    #[Route('/review', name: 'review', methods: ['GET','POST'])]
    public function review(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        TranslatorInterface $translator
    ): Response {
        // Proyecto activo
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }
        // Permiso de acceso al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        // Debe existir plan
        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) {
            return $this->redirectToRoute('backend_plan_welcome');
        }

        // Permiso de acceso al plan
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        // Debe estar completo
        if ($plan->getStatus() !== 'completo') {
            $this->addFlash('info', 'backend.plan.errors.not_complete');
            return $this->redirectToRoute('backend_plan_measures');
        }

        // Protocolos válidos para el tipo
        $protocols = $protocolRepository->getNamesForProjectType($project->getType());

        // --- POST: envío emails ET con PDF adjunto (solo si plan completo) ---
        if ($request->isMethod('POST') && $request->request->get('action') === 'send_et_emails') {
            $redirect = $this->handleSendEtEmails(
                $request,
                $activeProjectService,
                $planRepository,
                $measureRepository,
                $protocolRepository,
                $em,
                $mailer,
                $project,
                $translator
            );
            if ($redirect) {
                return $redirect;
            }
        }

        // --- Filtros por GET ---
        $protocol         = $request->query->get('protocol');
        $category         = $request->query->get('category');
        $department       = $request->query->get('department');
        $ods              = $request->query->get('ods');
        $esg              = $request->query->get('esg');
        $isApplicable     = $request->query->get('is_applicable');
        $willImplement    = $request->query->get('will_implement');
        $pendingSelection = $request->query->get('pending_selection');
        $onlyImplemented  = $request->query->get('only_implemented');
        $openId           = $request->query->getInt('open', 0);
        $isCritical       = $request->query->get('is_critical');

        // Por defecto (sin query string): solo "aplica" y "se implementará"
        $hasAnyQuery = !empty($request->query->all());
        if (!$hasAnyQuery) {
            $isApplicable  = '1';
            $willImplement = '1';
        } else {
            $isApplicable  = $request->query->get('is_applicable');
            $willImplement = $request->query->get('will_implement');
        }

        $page    = max(1, (int)$request->query->get('page', 1));
        $perPage = 10;

        // START Número medida
        $baseQb = $measureRepository->createQueryBuilder('m')
            ->select('m.id AS id')
            ->join('m.protocol', 'p');
        $this->catalogResolver->applyCatalogFilter($baseQb, 'm', 'p', $project);
        if (!$protocol) {
            $baseQb->where('p.name IN (:protocols)')->setParameter('protocols', $protocols);
        } else {
            $baseQb->andWhere('p.name = :protocol')->setParameter('protocol', $protocol);
        }
        $baseQb->orderBy('m.id', 'ASC');
        $baseIdsRows = $baseQb->getQuery()->getScalarResult();
        $positionById = [];
        foreach ($baseIdsRows as $idx => $row) {
            $id = (int) $row['id'];
            $positionById[$id] = $idx + 1;
        }
        // END Número medida

        // Query de Measure filtrada
        $qb = $measureRepository->createQueryBuilder('m')
            ->join('m.protocol', 'p')
            ->leftJoin('m.category', 'c')
            ->leftJoin('m.planMeasures', 'pm', 'WITH', 'pm.plan = :plan')
            ->setParameter('plan', $plan)
            ->andWhere('p = :protocol')
            ->setParameter('protocol', $plan->getProtocol())
            ->addSelect(
            "CASE
                WHEN pm.isApplicable = true AND pm.willImplement = true AND pm.isCritical = true THEN 1
                WHEN pm.isApplicable = true AND pm.willImplement = true THEN 2
                WHEN pm.isApplicable = true AND pm.willImplement = false THEN 3
                WHEN pm.isApplicable = false THEN 4
                ELSE 5
            END AS HIDDEN rank"
        );
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p', $project);

        // Orden por ranking y luego por nombre de la medida
        // Nombre para orden secundario: nameReview si existe, si no name
        $qb->addSelect("
            CASE
                WHEN m.nameReview IS NULL OR m.nameReview = '' THEN m.name
                ELSE m.nameReview
            END AS HIDDEN sortName
        ");

        // Orden final
        $qb->addOrderBy('rank', 'ASC')
        ->addOrderBy('sortName', 'ASC');

        // Filtros
        if ($category)        { $qb->andWhere('m.category = :category')->setParameter('category', $category); }
        if ($department) {
            $departmentEntity = $em->getRepository(Department::class)->find((int) $department);
            if ($departmentEntity) {
                $qb->andWhere('(m.department = :department OR :department MEMBER OF m.departments)')
                    ->setParameter('department', $departmentEntity);
            }
        }
        if ($ods) {
            $odsEntity = $em->getRepository(Ods::class)->find((int) $ods);
            if ($odsEntity) {
                $qb->andWhere('(m.ods = :ods OR :ods MEMBER OF m.odsItems)')
                    ->setParameter('ods', $odsEntity);
            }
        }
        if ($esg)             { $qb->andWhere('m.esg = :esg')->setParameter('esg', $esg); }
        if ($isApplicable)    { $qb->andWhere('pm.isApplicable = true'); }
        if ($willImplement)   { $qb->andWhere('pm.willImplement = true'); }
        if ($pendingSelection){ $qb->andWhere('pm.isApplicable IS NULL'); }
        if ($onlyImplemented) { $qb->andWhere('pm.implemented = true'); }
        if ($isCritical)      { $qb->andWhere('pm.isCritical = true'); }

        // Total y orden por ranking
        $allMeasures = $qb->getQuery()->getResult();
        $total = count($allMeasures);

        $page    = max(1, (int)$request->query->get('page', 1));
        $perPage = 10;
        $offset  = ($page - 1) * $perPage;
        $measures = array_slice($allMeasures, $offset, $perPage);

        // Datos para gráficos (WEB, con filtros)
        $filtersArr = [
            'protocol'          => $protocol,
            'category'          => $category,
            'department'        => $department,
            'ods'               => $ods,
            'esg'               => $esg,
            'is_applicable'     => $isApplicable,
            'will_implement'    => $willImplement,
            'pending_selection' => $pendingSelection,
            'only_implemented'  => $onlyImplemented,
        ];
        $filteredPlanMeasures = $this->getFilteredPlanMeasures($plan, $project, $filtersArr);

        $effective = 0; $nonApplicable = 0; $agreed = 0; $implemented = 0;
        foreach ($filteredPlanMeasures as $pm) {
            if ($pm->isApplicable() === true)  $effective++;
            if ($pm->isApplicable() === false) $nonApplicable++;
            if ($pm->willImplement())          $agreed++;
            if ($pm->isImplemented())          $implemented++;
        }

        $measuresTotal = (int) $total;

        $planChartsConfig = $this->buildChartsConfig(
            $measuresTotal,
            $effective,
            $nonApplicable,
            $agreed,
            $implemented
        );

        // Puntuación total y ganada para el protocolo del plan
        $scoreMax = 0;
        $scoreGained = 0;

        foreach ($plan->getPlanMeasures() as $pm) {
            $m = $pm->getMeasure();
            if (!$m || !$this->catalogResolver->isCatalogMeasure($m, $project)) {
                continue;
            }
            if ($m->getProtocol()?->getId() !== $plan->getProtocol()?->getId()) continue;

            $score = $m->getScore();
            if ($score !== null) {
                $scoreMax += (int) $score;
                if ($pm->isApplicable() === true && $pm->willImplement() === true) {
                    $scoreGained += (int) $score;
                }
            }
        }

        $uiLocale = $request->getLocale();

        return $this->render('backend/plan/review.html.twig', [
            'project'          => $project,
            'plan'             => $plan,
            'projectTier'      => $this->featureGate->getTier($project),
            'commercialCards'  => $this->buildCommercialFeatureCards($project),
            'hasWatermark'     => $this->featureGate->hasWatermark($project),
            'planMeasures'     => $plan->getPlanMeasures(),
            'measures'         => $measures,
            'currentPage'      => $page,
            'totalPages'       => max(1, (int)ceil($total / $perPage)),
            'offset'           => $offset,
            'perPage'          => $perPage,
            'positionById'     => $positionById,
            'filters'          => [
                'protocol'          => $protocol,
                'category'          => $category,
                'department'        => $department,
                'ods'               => $ods,
                'esg'               => $esg,
                'is_applicable'     => $isApplicable,
                'will_implement'    => $willImplement,
                'pending_selection' => $pendingSelection,
                'only_implemented'  => $onlyImplemented,
                'is_critical'       => $isCritical,
            ],
            'protocols'        => $protocols,
            'categories'       => $measureRepository->getCategories($project, $uiLocale),
            'departments'      => $measureRepository->getDepartments($project, $uiLocale),
            'odsList'          => $em->getRepository(Ods::class)->findAll(),
            'esgList'          => $em->getRepository(EsG::class)->findAll(),
            'scopeList'        => $em->getRepository(Scope::class)->findAll(),
            'planChartsConfig' => $planChartsConfig,
            'scoreMax'         => $scoreMax,
            'scoreGained'      => $scoreGained,
            'openId'           => $openId,
        ]);
    }

    #[Route('/update-selection', name: 'update_selection', methods: ['POST'])]
    public function updateSelection(
        Request $request,
        MeasureRepository $measureRepo,
        PlanMeasureRepository $planMeasureRepo,
        PlanRepository $planRepo,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        $project = $activeProjectService->getActiveProject();
        if (!$user || !$project) {
            return new JsonResponse(['success' => false, 'error' => 'No project or user'], 400);
        }

        // ⛔ Permiso mínimo para acceder al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $measureId = $request->request->get('measureId');
        $field     = $request->request->get('field');
        $value     = $request->request->get('value');

        if (!$measureId || !$field) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
        }

        /** @var Measure|null $measure */
        $measure = $measureRepo->find($measureId);
        if (!$measure) {
            return new JsonResponse(['success' => false, 'error' => 'Measure not found'], 404);
        }
        if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
            return new JsonResponse(['success' => false, 'error' => 'Feature not available for current plan tier'], 403);
        }

        // Asegura Plan
        $plan = $planRepo->findOneBy(['project' => $project]);
        if (!$plan) {
            // Vas a CREAR plan -> requiere EDIT del proyecto
            $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

            $plan = new Plan();
            $plan->setProject($project);
            $plan->setUser($user);
            $em->persist($plan);
            $em->flush();
        }

        // A partir de aquí, vas a modificar datos del plan -> requiere EDIT del plan
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        // Asegura PlanMeasure
        $planMeasure = $planMeasureRepo->findOneBy(['plan' => $plan, 'measure' => $measure]);
        if (!$planMeasure) {
            $planMeasure = new PlanMeasure();
            $planMeasure->setPlan($plan);
            $planMeasure->setMeasure($measure);
            // Deja el resto en NULL hasta respuesta explícita del usuario
        }

        // --- Mutaciones por campo ---
        switch ($field) {
            case 'isApplicable':
                $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                $planMeasure->setIsApplicable($bool);
                if ($bool === false) {
                    $planMeasure->setIsCritical(null);
                    $planMeasure->setCriticalReason(null);
                    $planMeasure->setWillImplement(null);
                    $planMeasure->setImplemented(null);
                }
                break;

            case 'isCritical':
            case 'critical':
                $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                $planMeasure->setIsCritical($bool);
                if ($bool === false) {
                    $planMeasure->setCriticalReason(null);
                }
                break;

            case 'criticalReason':
            case 'critical_reason':
                $text = trim((string)($value ?? ''));
                $planMeasure->setCriticalReason($text !== '' ? $text : null);
                break;

            case 'willImplement':
                // Solo permitir elegir implementar si aplica === true y la crítica fue respondida (null=no respondida)
                if ($planMeasure->isApplicable() === true && $planMeasure->isCritical() !== null) {
                    $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                    $planMeasure->setWillImplement($bool);
                }
                break;

            case 'implemented':
                // Solo permitir marcar implementado si la medida estaba marcada para implementar
                if ($planMeasure->willImplement() !== true) {
                    return new JsonResponse([
                        'success' => false,
                        'error'   => 'Solo puedes marcar como implementada una medida marcada para implementar.',
                    ], 400);
                }
                $bool = ($value === 'true') ? true : (($value === 'false') ? false : null);
                $planMeasure->setImplemented($bool);
                break;

            case 'verification':
                $bool = ($value === 'true');
                $planMeasure->setVerification($bool);
                break;

            case 'action_taken':
                $text = trim((string)$value);
                $planMeasure->setActionTaken($text !== '' ? $text : null);
                break;

            case 'evidence':
                $text = trim((string)$value);
                $planMeasure->setEvidence($text !== '' ? $text : null);
                break;

            case 'observations':
                $text = trim((string)$value);
                $planMeasure->setObservations($text !== '' ? $text : null);
                break;

            default:
                return new JsonResponse(['success' => false, 'error' => 'Unknown field'], 400);
        }

        $em->persist($planMeasure);
        $em->flush();

        // Estado del plan
        $complete = $this->isPlanCompleteForProtocol($plan, $project, $measureRepo);
        $plan->setStatus($complete ? 'completo' : 'incompleto');
        $plan->setStatusChangedAt(new \DateTimeImmutable());
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    private function handleSendEtEmails(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        $project,
        TranslatorInterface $translator
    ) {
        $selectedIds = $request->request->all('crew_ids') ?? [];

        if (!$selectedIds || count($selectedIds) === 0) {
            $this->addFlash('warning', 'backend.plan.email.select_member');
            return $this->redirectToRoute('backend_plan_review', $request->query->all());
        }

        // Filtros del formulario (hidden en el modal)
        $filters = [
            'protocol'          => $request->request->get('filter_protocol'),
            'category'          => $request->request->get('filter_category'),
            'department'        => $request->request->get('filter_department'),
            'ods'               => $request->request->get('filter_ods'),
            'esg'               => $request->request->get('filter_esg'),
            'is_applicable'     => $request->request->get('filter_is_applicable'),
            'will_implement'    => $request->request->get('filter_will_implement'),
            'pending_selection' => $request->request->get('filter_pending_selection'),
            'only_implemented'  => $request->request->get('filter_only_implemented'),
        ];

        // Generar PDF una sola vez
        $pdfBytes = $this->buildPdfBytesForFilters(
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $protocolRepository,
            $filters,
            $em,
            $translator
        );

        // Miembros seleccionados
        $crewRepo = $em->getRepository(CrewMember::class);
        $members = $crewRepo->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('ids', array_map('intval', $selectedIds))
            ->getQuery()
            ->getResult();

        if (!$members) {
            $this->addFlash('warning', 'backend.plan.email.members_not_found');
            return $this->redirectToRoute('backend_plan_review', $request->query->all());
        }

        $from = $this->getParameter('app.mail_from') ?? 'no-reply@begreenmyfriend.local';
        $slugger = new AsciiSlugger();
        $projectName = (string) $project->getName();
        $projectSlug = $slugger->slug(mb_substr($projectName, 0, 60))->lower();
        $dateIso     = (new \DateTimeImmutable())->format('Y-m-d');

        // Basename localizado (sin extensión)
        $basename = $translator->trans('backend.plan.email.attachment_basename', [
            '%project%' => $projectName,
            '%project_slug%' => $projectSlug,
            '%date%'    => $dateIso
        ]);

        // Asegurar nombre “safe” para el header (slug si el traduce con acentos)
        $basenameSafe = (string) $slugger->slug($basename)->lower();
        $filename = $basenameSafe . '.pdf';

        // Textos comunes traducidos
        $subjectTpl = $translator->trans('backend.plan.email.subject', [
            '%project%' => $projectName,
        ]);

        $ok = 0; $fail = 0;

        foreach ($members as $m) {
            $to = trim((string) $m->getEmail());
            if (!$to) { $fail++; continue; }

            $displayName = trim((string) ($m->getName() ?? ''));
            if ($displayName === '') {
                // nombre a partir del email (antes de @), como fallback
                $displayName = strtok($to, '@') ?: $to;
            }

            // Cuerpos traducidos
            $greeting   = $translator->trans('backend.plan.email.greeting', ['%name%' => $displayName]);
            $intro      = $translator->trans('backend.plan.email.intro',    ['%project%' => $projectName]);
            $closing    = $translator->trans('backend.plan.email.closing');

            $plain = $greeting . "\n\n" . $intro . "\n\n" . $closing;
            $html  = sprintf(
                '<p>%s</p><p>%s</p><p>%s</p>',
                htmlspecialchars($greeting, ENT_QUOTES),
                htmlspecialchars($intro, ENT_QUOTES),
                htmlspecialchars($closing, ENT_QUOTES)
            );

            try {
                $email = (new Email())
                    ->from($from)
                    ->to($to)
                    ->subject($subjectTpl)
                    ->text($plain)
                    ->html($html)
                    ->attach($pdfBytes, $filename, 'application/pdf');

                $mailer->send($email);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
            }
        }

        if ($ok > 0)  { $this->addFlash('success', 'backend.plan.email.sent_ok'); }
        if ($fail > 0){ $this->addFlash('danger',  'backend.plan.email.sent_fail'); }

        return $this->redirectToRoute('backend_plan_review', $request->query->all());
    }


    /**
     * Devuelve true si TODAS las medidas del protocolo seleccionado están contestadas:
     * - Si NO aplica: no requiere más.
     * - Si SÍ aplica: requiere crítica (true|false) y willImplement (true|false).
     */
    private function isPlanCompleteForProtocol(
        Plan $plan,
        Project $project,
        MeasureRepository $measureRepository
    ): bool {
        $protocol = $plan->getProtocol();
        if (!$protocol) return false;

        $measures = $measureRepository->createQueryBuilder('m')
            ->join('m.protocol', 'p')
            ->andWhere('p = :protocol')
            ->setParameter('protocol', $protocol)
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()->getResult();

        $pmByMeasureId = [];
        foreach ($plan->getPlanMeasures() as $pm) {
            $measure = $pm->getMeasure();
            if (!$measure || !$this->catalogResolver->isCatalogMeasure($measure, $project)) {
                continue;
            }
            if ($measure->getProtocol()?->getId() === $protocol->getId()) {
                $pmByMeasureId[$measure->getId()] = $pm;
            }
        }

        foreach ($measures as $m) {
            $pm = $pmByMeasureId[$m->getId()] ?? null;
            if (!$pm) return false;

            $applies  = $pm->isApplicable();   // null|bool
            $critical = $pm->isCritical();     // null|bool
            $willImpl = $pm->willImplement();  // null|bool

            if ($applies === null) return false;
            if ($applies === true) {
                if ($critical === null) return false;
                if ($willImpl === null)  return false;
            }
        }

        return true;
    }

    #[Route('/upload-evidences', name: 'upload_evidences', methods: ['POST'])]
    public function uploadEvidences(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepo,
        MeasureRepository $measureRepo,
        PlanMeasureRepository $pmRepo,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): JsonResponse {
        $user = $this->getUser();
        $project = $activeProjectService->getActiveProject();
        if (!$user || !$project) {
            return new JsonResponse(['success' => false, 'error' => 'No project or user'], 400);
        }

        // VOTER: acceso al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $measureId = (int) $request->request->get('measureId', 0);
        /** @var UploadedFile[]|UploadedFile|null $files */
        $files = $request->files->all('evidences');
        if (!is_array($files)) {
            $files = $files ? [$files] : [];
        }

        // Reglas
        $maxFiles  = 4;
        $maxBytes  = 4 * 1024 * 1024; // 4 MB

        if (count($files) > $maxFiles) {
            return new JsonResponse(['success' => false, 'error' => sprintf('Puedes cargar como máximo %d archivos por vez.', $maxFiles)], 400);
        }
        foreach ($files as $f) {
            if ($f && $f->getSize() !== null && $f->getSize() > $maxBytes) {
                return new JsonResponse(['success' => false, 'error' => 'Cada archivo debe pesar como máximo 4 MB.'], 400);
            }
        }
        if (!$measureId || empty($files)) {
            return new JsonResponse(['success' => false, 'error' => 'Parámetros inválidos'], 400);
        }

        $measure = $measureRepo->find($measureId);
        if (!$measure) {
            return new JsonResponse(['success' => false, 'error' => 'Measure not found'], 404);
        }
        if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
            return new JsonResponse(['success' => false, 'error' => 'Feature not available for current plan tier'], 403);
        }

        // Asegurar Plan (debe existir; no se crea aquí)
        $plan = $planRepo->findOneBy(['project' => $project]);
        if (!$plan) {
            return new JsonResponse(['success' => false, 'error' => 'Plan not found'], 404);
        }

        // VOTER: vas a modificar evidencias del plan -> EDIT
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        // Asegurar PlanMeasure (se puede crear si no existe todavía)
        $pm = $pmRepo->findOneBy(['plan' => $plan, 'measure' => $measure]);
        if (!$pm) {
            $pm = (new PlanMeasure())->setPlan($plan)->setMeasure($measure);
            $em->persist($pm);
            $em->flush();
        }

        $maxEvidenceCount = $this->featureGate->getMaxEvidenceCount($project);
        if ($maxEvidenceCount !== null) {
            $currentEvidenceCount = $this->countProjectEvidenceFiles($plan);
            $incomingEvidenceCount = 0;
            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $incomingEvidenceCount++;
                }
            }

            if ($currentEvidenceCount + $incomingEvidenceCount > $maxEvidenceCount) {
                return new JsonResponse([
                    'success' => false,
                    'error' => sprintf('Basic permite un máximo de %d evidencias por proyecto.', $maxEvidenceCount),
                ], 403);
            }
        }

        // Directorio de subida
        $publicDir  = $this->getParameter('kernel.project_dir') . '/public';
        $projectDir = (string) $project->getId();
        $uploadRel  = '/uploads/evidences/' . $projectDir;
        $uploadAbs  = $publicDir . $uploadRel;
        if (!is_dir($uploadAbs)) {
            @mkdir($uploadAbs, 0775, true);
        }

        // Evidencias existentes (una por línea)
        $existing = array_filter(array_map('trim', explode("\n", (string) $pm->getEvidence())));

        $added = [];
        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }
            $orig = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $ext  = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
            $safe = preg_replace('/[^a-z0-9\-_.]+/i', '_', $orig);

            $target = $safe . '_' . substr(bin2hex(random_bytes(3)), 0, 5) . '.' . $ext;
            $file->move($uploadAbs, $target);

            $relPath = $uploadRel . '/' . $target;
            $existing[] = $relPath;
            $added[] = $relPath;
        }

        $existing = array_values(array_unique($existing));
        $pm->setEvidence(implode("\n", $existing));
        $em->persist($pm);
        $em->flush();

        return new JsonResponse(['success' => true, 'files' => $added]);
    }

    #[Route('/delete-evidence', name: 'delete_evidence', methods: ['POST'])]
    public function deleteEvidence(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepo,
        MeasureRepository $measureRepo,
        PlanMeasureRepository $pmRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        $project = $activeProjectService->getActiveProject();
        if (!$user || !$project) {
            return new JsonResponse(['success' => false, 'error' => 'No project or user'], 400);
        }

        // VOTER: acceso al proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $measureId = (int) $request->request->get('measureId', 0);
        $file = trim((string) $request->request->get('file', ''));

        if (!$measureId || $file === '') {
            return new JsonResponse(['success' => false, 'error' => 'Parámetros inválidos'], 400);
        }

        $measure = $measureRepo->find($measureId);
        if (!$measure) {
            return new JsonResponse(['success' => false, 'error' => 'Measure not found'], 404);
        }
        if (!$this->catalogResolver->isCatalogMeasure($measure, $project)) {
            return new JsonResponse(['success' => false, 'error' => 'Feature not available for current plan tier'], 403);
        }

        $plan = $planRepo->findOneBy(['project' => $project]);
        if (!$plan) {
            return new JsonResponse(['success' => false, 'error' => 'Plan not found'], 404);
        }

        // VOTER: vas a modificar evidencias del plan -> EDIT
        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);

        $pm = $pmRepo->findOneBy(['plan' => $plan, 'measure' => $measure]);
        if (!$pm) {
            return new JsonResponse(['success' => false, 'error' => 'PlanMeasure not found'], 404);
        }

        $list = array_filter(array_map('trim', explode("\n", (string) $pm->getEvidence())));
        $newList = [];
        $removed = false;
        foreach ($list as $path) {
            if ($removed === false && $path === $file) {
                $removed = true;
                $abs = $this->getParameter('kernel.project_dir') . '/public' . $path;
                if (is_file($abs)) {
                    @unlink($abs);
                }
                continue;
            }
            $newList[] = $path;
        }

        if ($removed) {
            $pm->setEvidence(implode("\n", $newList));
            $em->persist($pm);
            $em->flush();
        }

        return new JsonResponse(['success' => true, 'removed' => $removed]);
    }

    #[Route('/download', name: 'download_pdf', methods: ['GET'])]
    public function downloadPdf(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        Request $request,
        TranslatorInterface $translator
    ): Response {
        return $this->handlePreviewOrDownload(
            activeProjectService: $activeProjectService,
            planRepository:       $planRepository,
            measureRepository:    $measureRepository,
            protocolRepository:   $protocolRepository,
            em:                   $em,
            request:              $request,
            asPdf:                true,
            translator:           $translator
        );
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        Request $request,
        TranslatorInterface $translator
    ): Response {
        return $this->handlePreviewOrDownload(
            activeProjectService: $activeProjectService,
            planRepository:       $planRepository,
            measureRepository:    $measureRepository,
            protocolRepository:   $protocolRepository,
            em:                   $em,
            request:              $request,
            asPdf:                false,
            translator:           $translator
        );
    }

    /**
     * Manejador único para vista previa (HTML) y descarga (PDF),
     * reutilizando el builder de contexto para evitar duplicación.
     */
    private function handlePreviewOrDownload(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        Request $request,
        bool $asPdf,
        TranslatorInterface $translator
    ): Response {
        // --- Guards de acceso y estado ---
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        // Debe poder ver el proyecto
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        // Debe existir plan y estar completo
        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan || $plan->getStatus() !== 'completo') {
            $this->addFlash('info', 'backend.plan.errors.not_complete');
            return $this->redirectToRoute('backend_plan_measures');
        }

        // Debe poder ver el plan
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        // 1) Leer filtros de la query
        $filters = [
            'protocol'           => $request->query->get('protocol'),
            'category'           => $request->query->get('category'),
            'department'         => $request->query->get('department'),
            'ods'                => $request->query->get('ods'),
            'esg'                => $request->query->get('esg'),
            'is_applicable'      => $request->query->get('is_applicable'),
            'will_implement'     => $request->query->get('will_implement'),
            'pending_selection'  => $request->query->get('pending_selection'),
            'only_implemented'   => $request->query->get('only_implemented'),
        ];

        // 2) Construir contexto unificado
        $ctx = $this->buildPdfContext(
            activeProjectService: $activeProjectService,
            planRepository:       $planRepository,
            measureRepository:    $measureRepository,
            protocolRepository:   $protocolRepository,
            em:                   $em,
            filters:              $filters,
            translator:           $translator
        );

        if ($asPdf) {
            // 3A) Renderizar HTML del PDF y generar bytes
            $html    = $this->renderPdfHtml($ctx);
            $pdfData = $this->pdfBytesFromHtml($html);

            // 4A) Responder como descarga
            /** @var Project $project */
            $project = $ctx['project'];
            $slugger = new AsciiSlugger();
            $projectNameSlug = $slugger->slug(substr($project->getName(), 0, 20))->lower();
            $filename = 'plan_sostenibilidad_' . $projectNameSlug . '.pdf';

            $response = new Response($pdfData);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set(
                'Content-Disposition',
                ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . $filename . '"'
            );
            $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'public');
            $response->headers->set('Content-Length', (string) strlen($pdfData));

            return $response;
        }

        // 3B) Vista previa HTML
        return $this->render('backend/plan/_preview.html.twig', [
            'project'        => $ctx['project'],
            'plan'           => $ctx['plan'],
            'measuresByDpto' => $ctx['measuresByDpto'],
            'activeFilters'  => $ctx['activeFilters'],
            'scoreMax'       => $ctx['scoreMax'],
            'scoreGained'    => $ctx['scoreGained'],
            'scorePct'       => $ctx['scorePct'],
            // 'planChartsUrls' => $ctx['planChartsUrls'],
            'preview'        => true,
        ]);
    }

    private function buildPdfBytesForFilters(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        array $filters,
        EntityManagerInterface $em,
        TranslatorInterface $translator
    ): string {
        $ctx  = $this->buildPdfContext(
            $activeProjectService,
            $planRepository,
            $measureRepository,
            $protocolRepository,
            $em,
            $filters,
            $translator
        );
        $html = $this->renderPdfHtml($ctx);
        return $this->pdfBytesFromHtml($html);
    }

    private function buildActiveFilterLabels(
        ?string $filterProtocol,
        ?string $filterCategory,
        ?string $filterDepartment,
        ?string $filterOds,
        ?string $filterEsg,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        TranslatorInterface $translator
    ): array {
        $protocolLabel = null;
        if ($filterProtocol) {
            if (ctype_digit((string) $filterProtocol)) {
                $protocol = $em->getRepository(Protocol::class)->find((int) $filterProtocol);
                $protocolLabel = $protocol?->getName();
            } else {
                $protocolLabel = $filterProtocol;
            }
        }

        $categoryLabel = null;
        if ($filterCategory && ctype_digit((string) $filterCategory)) {
            $category = $em->getRepository(Category::class)->find((int) $filterCategory);
            $categoryLabel = $category?->getName();
        }

        $departmentLabel = null;
        if ($filterDepartment && ctype_digit((string) $filterDepartment)) {
            $dept = $em->getRepository(Department::class)->find((int) $filterDepartment);
            $departmentLabel = $dept?->getDisplayName();
        }

        $odsLabel = null;
        if ($filterOds && ctype_digit((string) $filterOds)) {
            $ods = $em->getRepository(Ods::class)->find((int) $filterOds);
            $odsLabel = method_exists($ods, 'getName') ? $ods?->getName() : ($ods?->getName());
        }

        $esgLabel = null;
        if ($filterEsg && ctype_digit((string) $filterEsg)) {
            $esg = $em->getRepository(EsG::class)->find((int) $filterEsg);
            $esgLabel = method_exists($esg, 'getName') ? $esg?->getName() : ($esg?->getName());
        }

        $labelProtocol   = $translator->trans('backend.plan.filters.protocol');
        $labelCategory   = $translator->trans('backend.plan.filters.category');
        $labelDepartment = $translator->trans('backend.plan.filters.department');
        $labelOds        = $translator->trans('backend.plan.filters.ods');
        $labelEsg        = $translator->trans('backend.plan.filters.esg');

        return array_filter([
            $labelProtocol   => $protocolLabel,
            $labelCategory   => $categoryLabel,
            $labelDepartment => $departmentLabel,
            $labelOds        => $odsLabel,
            $labelEsg        => $esgLabel,
        ]);
    }

    private function buildPdfContext(
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        EntityManagerInterface $em,
        array $filters,
        TranslatorInterface $translator
    ): array {
        $project = $activeProjectService->getActiveProject();
        if (!$project) throw $this->createNotFoundException('backend.projects.flash.no_active');

        $plan = $planRepository->findOneBy(['project' => $project]);
        if (!$plan) throw $this->createNotFoundException('backend.plan.errors.not_found');

        $filterDepartment      = $filters['department']         ?? null;
        $filterCategory        = $filters['category']           ?? null;
        $filterProtocol        = $filters['protocol']           ?? null;
        $filterOds             = $filters['ods']                ?? null;
        $filterEsg             = $filters['esg']                ?? null;
        $filterApplicable      = $filters['is_applicable']      ?? null;
        $filterImplement       = $filters['will_implement']     ?? null;
        $filterPending         = $filters['pending_selection']  ?? null;
        $filterOnlyImplemented = $filters['only_implemented']   ?? null;

        $filtersArr = [
            'protocol'          => $filterProtocol,
            'category'          => $filterCategory,
            'department'        => $filterDepartment,
            'ods'               => $filterOds,
            'esg'               => $filterEsg,
            'is_applicable'     => $filterApplicable,
            'will_implement'    => $filterImplement,
            'pending_selection' => $filterPending,
            'only_implemented'  => $filterOnlyImplemented,
        ];

        $filteredPlanMeasures = $this->getFilteredPlanMeasures($plan, $project, $filtersArr);

        $measuresByDpto = [];
        $noDeptLabel = $translator->trans('backend.plan.labels.no_department');
        foreach ($filteredPlanMeasures as $pm) {
            $m = $pm->getMeasure();
            if (!$m) {
                continue;
            }

            $dpto = $m->getPrimaryDepartment()?->getDisplayName() ?? $noDeptLabel;
            $measuresByDpto[$dpto][] = $pm;
        }

        $measuresTotal = $this->countFilteredMeasures($measureRepository, $protocolRepository, $project, $filtersArr, $em);

        // --- PUNTUACIÓN ALCANZADA (con filtros aplicados) ---
        $scoreMax = 0;
        $scoreGained = 0;

        foreach ($filteredPlanMeasures as $pm) {
            $m = $pm->getMeasure();
            if (!$m) {
                continue;
            }
            $score = (int) ($m->getScore() ?? 0);
            $scoreMax += $score;

            if ($score > 0 && $pm->isApplicable() === true && $pm->willImplement() === true) {
                $scoreGained += $score;
            }
        }

        $scorePct = $scoreMax > 0 ? round(100 * $scoreGained / $scoreMax) : null;

        $effective = $nonApplicable = $agreed = $implemented = 0;
        foreach ($filteredPlanMeasures as $pm) {
            if ($pm->isApplicable() === true)  $effective++;
            if ($pm->isApplicable() === false) $nonApplicable++;
            if ($pm->willImplement())          $agreed++;
            if ($pm->isImplemented())          $implemented++;
        }

        $planChartsConfig = $this->buildChartsConfig($measuresTotal, $effective, $nonApplicable, $agreed, $implemented);
        $planChartsUrls   = $this->buildQuickChartUrlsFromConfig($planChartsConfig);

        $activeMain = $this->buildActiveFilterLabels(
            $filterProtocol,
            $filterCategory,
            $filterDepartment,
            $filterOds,
            $filterEsg,
            $protocolRepository,
            $em,
            $translator
        );

        $activeFlags = [];
        if ($filterApplicable) {
            $activeFlags[$translator->trans('backend.plan.filters.only_applicable')] = $translator->trans('backend.common.yes');
        }
        if ($filterImplement) {
            $activeFlags[$translator->trans('backend.plan.filters.only_to_implement')] = $translator->trans('backend.common.yes');
        }
        if ($filterPending) {
            $activeFlags[$translator->trans('backend.plan.filters.only_pending')] = $translator->trans('backend.common.yes');
        }
        if ($filterOnlyImplemented) {
            $activeFlags[$translator->trans('backend.plan.filters.only_implemented')] = $translator->trans('backend.common.yes');
        }

        $activeFilters = array_merge($activeMain, $activeFlags);

        return [
            'project'        => $project,
            'plan'           => $plan,
            'projectTier'    => $this->featureGate->getTier($project),
            'hasWatermark'   => $this->featureGate->hasWatermark($project),
            'activeFilters'  => $activeFilters,
            'measuresByDpto' => $measuresByDpto,
            'planChartsUrls' => $planChartsUrls,
            'scoreMax'       => $scoreMax,
            'scoreGained'    => $scoreGained,
            'scorePct'       => $scorePct,
        ];
    }

    private function renderPdfHtml(array $context): string
    {
        return $this->renderView('backend/plan/pdf.html.twig', $context);
    }

    private function pdfBytesFromHtml(string $html, string $orientation = 'landscape'): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    private function getFilteredPlanMeasures(Plan $plan, Project $project, array $filters): array
    {
        $result = [];
        foreach ($plan->getPlanMeasures() as $pm) {
            $m = $pm->getMeasure();
            if (!$m || !$this->catalogResolver->isCatalogMeasure($m, $project)) {
                continue;
            }

            if ($filters['department']) {
                $departmentMatch = false;
                foreach ($m->getResolvedDepartments() as $departmentItem) {
                    if ($departmentItem->getId() == $filters['department']) {
                        $departmentMatch = true;
                        break;
                    }
                }
                if (!$departmentMatch) continue;
            }
            if ($filters['category'] && $m->getCategory()?->getId() != $filters['category']) continue;

            if ($filters['protocol']) {
                $proto = $m->getProtocol();
                $matchByName = $proto?->getName() === $filters['protocol'];
                $matchById   = $proto?->getId() == $filters['protocol'];
                if (!$matchByName && !$matchById) continue;
            }

            if ($filters['ods']) {
                $odsMatch = false;
                foreach ($m->getResolvedOdsItems() as $odsItem) {
                    if ($odsItem->getId() == $filters['ods']) {
                        $odsMatch = true;
                        break;
                    }
                }
                if (!$odsMatch) continue;
            }
            if ($filters['esg'] && $m->getEsg()?->getId() != $filters['esg']) continue;

            if ($filters['is_applicable'] !== null && filter_var($filters['is_applicable'], FILTER_VALIDATE_BOOLEAN)) {
                if (!$pm->isApplicable()) continue;
            }
            if ($filters['will_implement'] !== null && filter_var($filters['will_implement'], FILTER_VALIDATE_BOOLEAN)) {
                if (!$pm->willImplement()) continue;
            }
            if ($filters['pending_selection'] !== null && filter_var($filters['pending_selection'], FILTER_VALIDATE_BOOLEAN)) {
                if ($pm->isApplicable() !== null || $pm->willImplement() !== null) continue;
            }
            if ($filters['only_implemented'] !== null && filter_var($filters['only_implemented'], FILTER_VALIDATE_BOOLEAN)) {
                if (!$pm->isImplemented()) continue;
            }

            $result[] = $pm;
        }
        return $result;
    }

    private function countFilteredMeasures(
        MeasureRepository $measureRepository,
        ProtocolRepository $protocolRepository,
        Project $project,
        array $filters,
        EntityManagerInterface $em): int
    {
        $protocols = $protocolRepository->getNamesForProjectType($project->getType());

        $qb = $measureRepository->createQueryBuilder('m')->join('m.protocol', 'p');
        $this->catalogResolver->applyCatalogFilter($qb, 'm', 'p', $project);

        if (!$filters['protocol'])   $qb->where('p.name IN (:protocols)')->setParameter('protocols', $protocols);
        else                         $qb->andWhere('p.name = :protocol')->setParameter('protocol', $filters['protocol']);
        if ($filters['category'])    $qb->andWhere('m.category = :category')->setParameter('category', $filters['category']);
        if ($filters['department']) {
            $departmentEntity = $em->getRepository(Department::class)->find((int) $filters['department']);
            if ($departmentEntity) {
                $qb->andWhere('(m.department = :department OR :department MEMBER OF m.departments)')
                    ->setParameter('department', $departmentEntity);
            }
        }
        if ($filters['ods']) {
            $odsEntity = $em->getRepository(Ods::class)->find((int) $filters['ods']);
            if ($odsEntity) {
                $qb->andWhere('(m.ods = :ods OR :ods MEMBER OF m.odsItems)')
                    ->setParameter('ods', $odsEntity);
            }
        }
        if ($filters['esg'])         $qb->andWhere('m.esg = :esg')->setParameter('esg', $filters['esg']);
        if ($filters['is_applicable']) {
            $qb->join('m.planMeasures', 'pm')->andWhere('pm.isApplicable = true');
        }
        if ($filters['will_implement']) {
            $qb->join('m.planMeasures', 'pm2')->andWhere('pm2.willImplement = true');
        }
        if ($filters['pending_selection']) {
            $qb->leftJoin('m.planMeasures', 'pm3')->andWhere('pm3.isApplicable IS NULL');
        }
        if ($filters['only_implemented']) {
            $qb->join('m.planMeasures', 'pm_impl')->andWhere('pm_impl.implemented = true');
        }

        return count($qb->getQuery()->getResult());
    }

    /**
     * Construye la config de los 4 gráficos (web y base para PDF).
     * Pies muestran % + cantidad:
     *  - Compromiso: Implementar vs No implementar (base: Aplicables)
     *  - Implementación: Implementadas vs No implementadas (base: Implementar)
     *  - Alcance: En alcance (Aplicables) vs Fuera de alcance (base: Totales)
     * Terminología alineada a los filtros.
     */
    private function buildChartsConfig(
        int $measuresTotal,
        int $effective,
        int $nonApplicable,
        int $agreed,
        int $implemented
    ): array {
        // Paleta
        $c1       = '#2ecc71';
        $c2       = 'rgba(63, 195, 138, 0.40)';
        $c3       = '#e9ecef';
        $c1Border = '#27ae60';
        $c2Border = 'rgba(63, 195, 138, 1)';
        $c3Border = '#7f8c8d';

        // % compromiso: Implementar / Aplicables
        $commitmentPct     = $effective > 0 ? round(($agreed / $effective) * 100) : 0;

        // % implementación: Implementadas / Implementar
        $implementationPct = $agreed > 0 ? round(($implemented / $agreed) * 100) : 0;

        // % alcance: Aplicables / Totales
        $reachPct          = $measuresTotal > 0 ? round(($effective / $measuresTotal) * 100) : 0;

        // Valores ABSOLUTOS para pies (antes del “safe pie”)
        $alcanceDataRaw        = [$measuresTotal - $effective, $effective];     // Fuera, En
        $commitmentDataRaw     = [$effective - $agreed, $agreed];               // No implementar, Implementar
        $implementationDataRaw = [$agreed - $implemented, $implemented];        // No implementadas, Implementadas

        // Evitar datasets [0,0] que no renderizan
        $alcanceData        = $this->safePie($alcanceDataRaw);
        $commitmentData     = $this->safePie($commitmentDataRaw);
        $implementationData = $this->safePie($implementationDataRaw);

        return [
            // 1) Barras (valores absolutos)
            'summary' => [
                'type'   => 'bar',
                'title'  => $this->t->trans('backend.plan.charts.title_measures'),
                'labels' => [
                    $this->t->trans('backend.plan.charts.total'),
                    $this->t->trans('backend.plan.charts.applicable'),
                    $this->t->trans('backend.plan.charts.non_applicable'),
                ],
                'datasets' => [[
                    'label' => $this->t->trans('backend.plan.charts.measures'),
                    'data'  => [$measuresTotal, $effective, $nonApplicable],
                    'backgroundColor' => [$c2, $c1, $c3],
                    'borderColor'     => [$c2Border, $c1Border, $c3Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c2, $c1, $c3],
                ]],
                'options' => [
                    'scales' => ['y' => [
                        'beginAtZero' => true,
                        'grace' => '12%',
                    ]],
                ],
            ],

            // 2) Pie: alcance (Aplicables vs Fuera de alcance, base Totales)
            'alcance' => [
                'type'          => 'pie',
                'title'         => $this->t->trans('backend.plan.charts.reach_title', ['%pct%' => $reachPct]),
                'labels'        => [
                    $this->t->trans('backend.plan.charts.out_of_scope'),
                    $this->t->trans('backend.plan.charts.in_scope'),
                ],
                'datasets'      => [[
                    'label' => $this->t->trans('backend.plan.charts.reach'),
                    'data'  => $alcanceData,
                    'backgroundColor' => [$c3, $c1],
                    'borderColor'     => [$c3Border, $c1Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c3, $c1],
                ]],
            ],

            // 3) Pie: compromiso (Implementar vs No implementar, base Aplicables)
            'commitment' => [
                'type'          => 'pie',
                'title'         => $this->t->trans('backend.plan.charts.commitment_title', ['%pct%' => $commitmentPct]),
                'labels'        => [
                    $this->t->trans('backend.plan.charts.not_to_implement'),
                    $this->t->trans('backend.plan.charts.to_implement'),
                ],
                'datasets'      => [[
                    'label' => $this->t->trans('backend.plan.charts.commitment'),
                    'data'  => $commitmentData,
                    'backgroundColor' => [$c3, $c1],
                    'borderColor'     => [$c3Border, $c1Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c3, $c1],
                ]],
            ],

            // 4) Pie: implementación (Implementadas vs No implementadas, base Implementar)
            'implementation' => [
                'type'          => 'pie',
                'title'         => $this->t->trans('backend.plan.charts.performance_title', ['%pct%' => $implementationPct]),
                'labels'        => [
                    $this->t->trans('backend.plan.charts.not_implemented'),
                    $this->t->trans('backend.plan.charts.implemented'),
                ],
                'datasets'      => [[
                    'label' => $this->t->trans('backend.plan.charts.performance'),
                    'data'  => $implementationData,
                    'backgroundColor' => [$c3, $c1],
                    'borderColor'     => [$c3Border, $c1Border],
                    'borderWidth'     => 1,
                    'hoverBackgroundColor' => [$c3, $c1],
                ]],
            ],
        ];
    }

    private function buildCommercialFeatureCards(Project $project): array
    {
        $definitions = [
            'sustainability_plan.department_pdf' => 'PDF por departamentos',
            'sustainability_plan.advanced_exports' => 'Exportaciones avanzadas',
            'sustainability_plan.custom_comments' => 'Comentarios personalizados',
            'sustainability_plan.internal_notes' => 'Notas internas',
            'sustainability_plan.responsibles' => 'Responsables',
            'sustainability_plan.checklist' => 'Checklist',
            'sustainability_plan.custom_measures' => 'Medidas custom',
            'sustainability_plan.branding' => 'Branding',
        ];

        $cards = [];
        foreach ($definitions as $feature => $label) {
            $state = $this->featureGate->getFeatureState($project, $feature);
            if ($state['visible'] && !$state['enabled']) {
                $cards[] = [
                    'label' => $label,
                    'reason' => $state['reason'] ?? null,
                ];
            }
        }

        return $cards;
    }

    private function countProjectEvidenceFiles(Plan $plan): int
    {
        $paths = [];
        foreach ($plan->getPlanMeasures() as $pm) {
            $evidence = trim((string) $pm->getEvidence());
            if ($evidence === '') {
                continue;
            }

            foreach (array_filter(array_map('trim', explode("\n", $evidence))) as $path) {
                $paths[$path] = true;
            }
        }

        return count($paths);
    }


    // Añade este helper en la clase PlanController (por ejemplo, debajo de buildQuickChartUrlsFromConfig)
    private function safePie(array $data): array
    {
        $sum = 0;
        foreach ($data as $v) { $sum += (float) $v; }
        if ($sum > 0) {
            return $data;
        }
        // Si todo es 0, devolvemos un dataset mínimo para que Chart.js pinte el pie
        $n = count($data);
        if ($n === 2) return [1, 0];
        if ($n === 3) return [1, 0, 0];
        return [1];
    }



    /**
     * Genera URLs de QuickChart para PDF con fuentes más grandes.
     */
    private function buildQuickChartUrlsFromConfig(array $planChartsConfig): array
    {
        $urls = [];

        foreach ($planChartsConfig as $key => $cfg) {
            $isPie = in_array($cfg['type'], ['pie','doughnut'], true);

            $chartConfig = [
                'type' => $cfg['type'],
                'data' => [
                    'labels'   => $cfg['labels'] ?? [],
                    'datasets' => $cfg['datasets'] ?? [],
                ],
                'options' => [
                    'plugins' => [
                        'legend' => [
                            'position' => 'bottom',
                            'labels' => [
                                'font' => ['size' => 16],
                            ],
                        ],
                        'title'  => [
                            'display' => !empty($cfg['title']),
                            'text'    => $cfg['title'] ?? '',
                            'font'    => ['size' => 18, 'weight' => '600'],
                        ],
                    ],
                    'responsive' => true,
                    'maintainAspectRatio' => false,
                ],
            ];

            // Más espacio para el título en pies
            if ($isPie) {
                $chartConfig['options']['layout'] = [
                    'padding' => ['top' => 16, 'bottom' => 4, 'left' => 4, 'right' => 4]
                ];
            }

            // Aumentar tamaño de etiquetas de ejes en barras
            if (($cfg['type'] ?? null) === 'bar') {
                $chartConfig['options']['scales'] = [
                    'x' => ['ticks' => ['font' => ['size' => 14]]],
                    'y' => ['ticks' => ['font' => ['size' => 14]]],
                ];
            }

            // Datalabels y tooltips en pies: "% (cantidad)"
            $extraParams = '';
            if ($isPie) {
                $extraParams = '&plugins=datalabels';
                $chartConfig['options']['plugins']['datalabels'] = [
                    'anchor'   => 'center',
                    'align'    => 'center',
                    'offset'   => 0,
                    'clamp'    => true,
                    'clip'     => false,
                    'font'     => ['weight' => '700', 'size' => 16],
                    'display'  => 'function(ctx){ const v = ctx.dataset.data?.[ctx.dataIndex]; return v > 0; }',
                    'formatter'=> 'function(value, ctx){ var ds=ctx.chart.data.datasets[0].data||[]; var s=ds.reduce((a,b)=>a+(+b||0),0); if(!s) return null; var p=Math.round((value/s)*100); return p + "% (" + value + ")"; }',
                ];

                $chartConfig['options']['plugins']['tooltip'] = [
                    'callbacks' => [
                        'label' => 'function(ctx){ var ds=ctx.dataset.data||[]; var s=ds.reduce((a,b)=>a+(+b||0),0); var v=ctx.parsed; var p=s?Math.round((v/s)*100):0; return ctx.label + ": " + v + " (" + p + "%)"; }'
                    ]
                ];
            }

            $encoded = urlencode(json_encode($chartConfig));
            $urls[$key] = "https://quickchart.io/chart?c={$encoded}&w=900&h=520&backgroundColor=white{$extraParams}";
        }

        return $urls;
    }
}
